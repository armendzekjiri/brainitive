<?php

namespace Tests\Feature\Pages;

use App\Models\PageComment;
use App\Models\PageReview;
use App\Notifications\ReviewCommentedNotification;
use App\Support\PageReviews\QuoteAnchor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Review comments (BRAI-80): text-quote anchors that never touch the page HTML, outdated threads,
 * replies, resolve / reopen, who sees which threads, and review_commented notifications.
 */
class PageReviewCommentsTest extends TestCase
{
    use InteractsWithPageReviews;
    use InteractsWithPages;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPages();
        Notification::fake();
    }

    public function test_quote_anchors_follow_the_shared_rule(): void
    {
        $text = QuoteAnchor::text('<h1>Intro</h1><p>The  cat sat.&nbsp;The cat ran &amp; hid.</p>');
        $this->assertSame('IntroThe cat sat. The cat ran & hid.', $text);

        // one occurrence → there, whatever the context says
        $this->assertSame(strpos($text, 'ran'), QuoteAnchor::locate($text, 'ran', 'nonsense', 'nonsense'));
        // several → the one whose prefix and suffix match
        $this->assertSame(strrpos($text, 'The cat'), QuoteAnchor::locate($text, 'The cat', 'sat. ', ' ran'));
        // several → the single one matching either side
        $this->assertSame(strrpos($text, 'The cat'), QuoteAnchor::locate($text, 'The cat', 'zzz', ' ran'));
        // several, no context match → never the wrong text
        $this->assertNull(QuoteAnchor::locate($text, 'The cat', 'zzz', 'yyy'));
        // gone → outdated
        $this->assertNull(QuoteAnchor::locate($text, 'dog', '', ''));
        // whitespace inside the quote is normalized like the text
        $this->assertNotNull(QuoteAnchor::locate($text, "cat\n ran", null, null));
    }

    public function test_an_inline_comment_anchors_to_the_snapshot_without_touching_the_page(): void
    {
        $page = $this->draftPage(['content' => $this->html('<p>Ship the beta on Friday.</p>')]);
        $before = $page->fresh()->content;
        $review = $this->requestReview($page, [$this->viewer]);

        $this->actingAs($this->viewer)->post($this->reviewRoute('pages.reviews.comments.store', $review), [
            'body' => 'Which Friday?',
            'quote' => 'on Friday',
            'prefix' => 'Ship the beta ',
            'suffix' => '.',
        ])->assertSessionHasNoErrors();

        $thread = PageComment::firstOrFail();
        $this->assertSame('on Friday', $thread->quote);
        $this->assertSame($before, $page->fresh()->content); // the saved HTML never changes

        $this->actingAs($this->author)->get($this->reviewRoute('pages.reviews.show', $review))
            ->assertInertia(fn (Assert $inertia) => $inertia
                ->where('threads.0.quote', 'on Friday')
                ->where('threads.0.outdated', false)
                ->where('threads.0.review_number', 1)
                ->where('threads.0.comments.0.body', 'Which Friday?'));

        // a quote that isn't in the snapshot is refused
        $this->actingAs($this->viewer)->post($this->reviewRoute('pages.reviews.comments.store', $review), [
            'body' => 'Hm', 'quote' => 'on Monday',
        ])->assertSessionHasErrors('quote');
    }

    public function test_a_thread_is_outdated_once_its_text_is_gone_from_the_reviewed_snapshot(): void
    {
        $page = $this->draftPage(['content' => $this->html('<p>Ship the beta on Friday.</p>')]);
        $first = $this->requestReview($page, [$this->viewer]);
        $this->actingAs($this->viewer)->post($this->reviewRoute('pages.reviews.comments.store', $first), [
            'body' => 'Which Friday?', 'quote' => 'on Friday', 'prefix' => 'Ship the beta ', 'suffix' => '.',
        ]);
        $this->actingAs($this->viewer)->post($this->reviewRoute('pages.reviews.comments.store', $first), ['body' => 'Overall fine']);
        $this->decide($first, $this->viewer, 'changes_requested');

        $this->editDraft($page, '<p>Ship the beta on 14 November.</p>');
        $second = $this->requestReview($page, [$this->viewer]);

        // in the new review the old thread is carried over, marked outdated; the page-level one never is
        $this->actingAs($this->viewer)->get($this->reviewRoute('pages.reviews.show', $second))
            ->assertInertia(fn (Assert $inertia) => $inertia
                ->has('threads', 2)
                ->where('threads.0.outdated', true)
                ->where('threads.0.review_number', 1)
                ->where('threads.1.quote', null)
                ->where('threads.1.outdated', false));

        // the first review still shows it in place
        $this->actingAs($this->viewer)->get($this->reviewRoute('pages.reviews.show', $first))
            ->assertInertia(fn (Assert $inertia) => $inertia->where('threads.0.outdated', false));
    }

    public function test_replies_and_resolving(): void
    {
        $page = $this->draftPage();
        $review = $this->requestReview($page, [$this->viewer]);
        $this->actingAs($this->viewer)->post($this->reviewRoute('pages.reviews.comments.store', $review), ['body' => 'Needs a summary']);
        $thread = PageComment::firstOrFail();

        $this->actingAs($this->author)->post($this->reviewRoute('pages.reviews.comments.store', $review), [
            'body' => 'Added one', 'parent' => $thread->uuid,
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, $thread->replies()->count());

        $threadUrl = route('pages.reviews.threads.update', [$this->workspace->slug, $this->project->slug, $page->uuid, $thread->uuid]);
        $this->actingAs($this->author)->patch($threadUrl, ['resolved' => true])->assertSessionHasNoErrors();
        $this->assertNotNull($thread->fresh()->resolved_at);
        $this->assertSame($this->author->id, $thread->fresh()->resolved_by);

        $this->actingAs($this->viewer)->patch($threadUrl, ['resolved' => false])->assertSessionHasNoErrors();
        $this->assertNull($thread->fresh()->resolved_at);

        // someone who can't see the review can't reply or resolve
        $this->actingAs($this->editor)->post($this->reviewRoute('pages.reviews.comments.store', $review), [
            'body' => 'Sneaky', 'parent' => $thread->uuid,
        ])->assertForbidden();
        $this->actingAs($this->editor)->patch($threadUrl, ['resolved' => true])->assertForbidden();
    }

    public function test_reviewers_only_see_threads_of_reviews_they_are_on(): void
    {
        $page = $this->draftPage();
        $first = $this->requestReview($page, [$this->editor]);
        $this->actingAs($this->editor)->post($this->reviewRoute('pages.reviews.comments.store', $first), ['body' => 'From the first review']);
        $second = $this->requestReview($page, [$this->viewer]);

        $this->actingAs($this->viewer)->get($this->reviewRoute('pages.reviews.show', $second))
            ->assertInertia(fn (Assert $inertia) => $inertia->has('threads', 0));
        // the author sees every thread of the page
        $this->actingAs($this->author)->get($this->reviewRoute('pages.reviews.show', $second))
            ->assertInertia(fn (Assert $inertia) => $inertia->has('threads', 1));
        // a closed review takes no new threads
        $this->actingAs($this->editor)->post($this->reviewRoute('pages.reviews.comments.store', $first), ['body' => 'Late'])->assertForbidden();
    }

    public function test_comment_notifications_reach_participants_and_the_author_once_never_the_actor(): void
    {
        $page = $this->draftPage();
        $review = $this->requestReview($page, [$this->viewer, $this->editor]);
        $comment = fn ($user, array $data) => $this->actingAs($user)->post($this->reviewRoute('pages.reviews.comments.store', $review), $data);

        // a reviewer starts a thread → the author hears about it
        $comment($this->viewer, ['body' => 'Typo in the title']);
        $thread = PageComment::firstOrFail();
        Notification::assertSentToTimes($this->author, ReviewCommentedNotification::class, 1);
        Notification::assertNotSentTo($this->viewer, ReviewCommentedNotification::class);
        Notification::assertNotSentTo($this->editor, ReviewCommentedNotification::class); // not in the thread

        // the author replies → the thread starter, not the author
        $comment($this->author, ['body' => 'Fixed', 'parent' => $thread->uuid]);
        Notification::assertSentToTimes($this->viewer, ReviewCommentedNotification::class, 1);
        Notification::assertSentToTimes($this->author, ReviewCommentedNotification::class, 1);

        // a second reviewer joins → the author and the starter, once each
        $comment($this->editor, ['body' => 'Agreed', 'parent' => $thread->uuid]);
        Notification::assertSentToTimes($this->author, ReviewCommentedNotification::class, 2);
        Notification::assertSentToTimes($this->viewer, ReviewCommentedNotification::class, 2);
        Notification::assertNotSentTo($this->editor, ReviewCommentedNotification::class);

        // someone who lost access isn't told
        $this->project->removeUser($this->viewer);
        $comment($this->author, ['body' => 'Thanks both', 'parent' => $thread->uuid]);
        Notification::assertSentToTimes($this->viewer, ReviewCommentedNotification::class, 2);
        Notification::assertSentToTimes($this->editor, ReviewCommentedNotification::class, 1);

        $payload = (new ReviewCommentedNotification(PageReview::firstOrFail(), $thread, $this->viewer))->toDatabase($this->author);
        $this->assertSame('review_commented', $payload['type']);
        $this->assertSame('Typo in the title', $payload['body']);
    }
}
