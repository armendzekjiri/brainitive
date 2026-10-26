<?php

namespace Tests\Feature\Feedback;

use App\Models\FeedbackPost;
use App\Models\PortalIdentity;
use App\Notifications\FeedbackStatusChangedNotification;
use App\Notifications\FeedbackSubmittedNotification;
use App\Support\Feedback\FeedbackModeration;
use App\Support\Feedback\FeedbackVotes;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** BRAI-60: the public side of the feedback portal — read, submit, vote, follow. */
class FeedbackPortalTest extends TestCase
{
    use InteractsWithFeedback;
    use RefreshDatabase;

    /** Every key a public post may carry — nothing internal. */
    private const PUBLIC_POST_KEYS = [
        'number', 'title', 'body', 'excerpt', 'status', 'status_label', 'status_note', 'votes_count', 'author_name',
        'created_at', 'url', 'is_pending', 'is_mine', 'has_voted', 'is_following',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->setUpFeedback();
    }

    public function test_an_enabled_portal_is_readable_anonymously(): void
    {
        $post = $this->feedbackPost(['title' => 'Dark mode']);

        $this->get(route('feedback.portal', 'acme-ideas'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Feedback/Index')
                ->where('portal.title', 'Acme ideas')
                ->where('identity', null)
                ->has('posts', 1)
                ->where('posts.0.title', 'Dark mode'));

        $this->get(route('feedback.post', ['acme-ideas', $post->number]))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Feedback/Show')->where('post.title', 'Dark mode'));
        $this->get(route('feedback.roadmap', 'acme-ideas'))->assertOk();
    }

    public function test_a_disabled_portal_or_deleted_project_is_404_everywhere(): void
    {
        $post = $this->feedbackPost();
        $this->portal->update(['enabled' => false]);
        $guest = $this->identity();

        $this->get(route('feedback.portal', 'acme-ideas'))->assertNotFound();
        $this->get(route('feedback.roadmap', 'acme-ideas'))->assertNotFound();
        $this->get(route('feedback.post', ['acme-ideas', $post->number]))->assertNotFound();
        $this->getJson(route('feedback.similar', ['portalSlug' => 'acme-ideas', 'q' => 'idea']))->assertNotFound();
        $this->asGuest($guest)->post(route('feedback.vote', ['acme-ideas', $post->number]))->assertNotFound();
        $this->asGuest($guest)->post(route('feedback.posts.store', 'acme-ideas'), ['title' => 'Hello there'])->assertNotFound();
        $this->get(route('feedback.portal', 'no-such-portal'))->assertNotFound();

        $this->portal->update(['enabled' => true]);
        $this->project->delete(); // soft delete
        $this->get(route('feedback.portal', 'acme-ideas'))->assertNotFound();
    }

    public function test_pending_and_rejected_posts_are_hidden_from_readers(): void
    {
        $author = $this->identity();
        $pending = $this->feedbackPost(['title' => 'Pending idea', 'moderation' => 'pending', 'approved_at' => null, 'author_identity_id' => $author->id]);
        $rejected = $this->feedbackPost(['title' => 'Rejected idea', 'moderation' => 'rejected', 'approved_at' => null, 'author_identity_id' => $author->id]);
        $this->feedbackPost(['title' => 'Visible idea']);

        $this->get(route('feedback.portal', 'acme-ideas'))
            ->assertInertia(fn ($page) => $page->has('posts', 1)->where('posts.0.title', 'Visible idea')->where('counts.open', 1));
        $this->get(route('feedback.post', ['acme-ideas', $pending->number]))->assertNotFound();
        $this->get(route('feedback.post', ['acme-ideas', $rejected->number]))->assertNotFound();
        $this->asGuest($this->identity())->get(route('feedback.post', ['acme-ideas', $pending->number]))->assertNotFound();
        $this->getJson(route('feedback.similar', ['portalSlug' => 'acme-ideas', 'q' => 'idea']))
            ->assertOk()->assertJsonCount(1, 'posts')->assertJsonPath('posts.0.title', 'Visible idea');

        // the author sees their own pending idea (marked), and it is listed as "waiting for review"
        $this->asGuest($author)->get(route('feedback.post', ['acme-ideas', $pending->number]))->assertOk()
            ->assertInertia(fn ($page) => $page->where('post.is_pending', true)->where('post.is_mine', true));
        $this->asGuest($author)->get(route('feedback.portal', 'acme-ideas'))
            ->assertInertia(fn ($page) => $page->has('pending', 1)->where('pending.0.title', 'Pending idea'));
        $this->asGuest($author)->get(route('feedback.post', ['acme-ideas', $rejected->number]))->assertNotFound();
    }

    public function test_sort_filter_and_search(): void
    {
        $old = $this->feedbackPost(['title' => 'Export to CSV', 'votes_count' => 9, 'status' => 'planned']);
        $this->travel(1)->minutes();
        $new = $this->feedbackPost(['title' => 'Calendar sync', 'body' => 'Google CALENDAR please', 'votes_count' => 2]);

        $this->get(route('feedback.portal', 'acme-ideas'))->assertInertia(fn ($page) => $page->where('posts.0.number', $old->number));
        $this->get(route('feedback.portal', ['acme-ideas', 'sort' => 'new']))->assertInertia(fn ($page) => $page->where('posts.0.number', $new->number));
        $this->get(route('feedback.portal', ['acme-ideas', 'status' => 'planned']))->assertInertia(fn ($page) => $page->has('posts', 1)->where('posts.0.number', $old->number));
        // case-insensitive, title or body
        $this->get(route('feedback.portal', ['acme-ideas', 'q' => 'calendar']))->assertInertia(fn ($page) => $page->has('posts', 1)->where('posts.0.number', $new->number));
        $this->get(route('feedback.portal', ['acme-ideas', 'q' => '100%_']))->assertInertia(fn ($page) => $page->has('posts', 0));
        $this->get(route('feedback.portal', ['acme-ideas', 'status' => 'nope']))->assertSessionHasErrors('status');

        $this->get(route('feedback.roadmap', 'acme-ideas'))->assertInertia(fn ($page) => $page
            ->where('columns.0.status', 'planned')->has('columns.0.posts', 1)
            ->has('columns.1.posts', 0)->has('columns.2.posts', 0));
    }

    public function test_the_list_is_paginated(): void
    {
        foreach (range(1, 22) as $i) {
            $this->feedbackPost();
        }

        $this->get(route('feedback.portal', 'acme-ideas'))->assertInertia(fn ($page) => $page->has('posts', 20)->where('pagination.last_page', 2));
        $this->get(route('feedback.portal', ['acme-ideas', 'page' => 2]))->assertInertia(fn ($page) => $page->has('posts', 2));
    }

    public function test_public_responses_never_carry_internal_fields(): void
    {
        $author = $this->identity('secret.author@example.com', null);
        $post = $this->feedbackPost(['title' => 'Public title', 'status' => 'planned', 'author_identity_id' => $author->id]);
        $item = $this->item(['title' => 'INTERNAL card title', 'key' => 'BRAI-77', 'description' => '<p>internal notes</p>']);
        FeedbackModeration::linkCard($post, $item, $this->admin);

        foreach ([route('feedback.portal', 'acme-ideas'), route('feedback.post', ['acme-ideas', $post->number]), route('feedback.roadmap', 'acme-ideas')] as $url) {
            $response = $this->get($url)->assertOk();
            // the page's props (the HTML also carries Ziggy's route list, which names parameters like {uuid})
            $props = json_encode($response->viewData('page')['props']);
            $this->assertStringContainsString('Public title', $props);
            foreach (['INTERNAL card title', 'BRAI-77', 'internal notes', 'secret.author', 'item_id', '"uuid"', '"id"', 'moderation'] as $secret) {
                $this->assertStringNotContainsString($secret, $props, "$url leaks $secret");
            }
        }

        $this->get(route('feedback.post', ['acme-ideas', $post->number]))->assertInertia(fn ($page) => $page
            ->where('post', fn ($data) => array_keys($data->all()) === self::PUBLIC_POST_KEYS)
            ->where('post.author_name', null)); // no name → no email-derived fallback
        $this->get(route('feedback.portal', 'acme-ideas'))->assertInertia(fn ($page) => $page
            ->where('posts.0', fn ($data) => array_keys($data->all()) === self::PUBLIC_POST_KEYS)
            ->where('portal', fn ($data) => array_keys($data->all()) === ['slug', 'title', 'intro', 'url', 'roadmap_url']));
    }

    public function test_submitting_needs_a_signed_in_identity(): void
    {
        $this->post(route('feedback.posts.store', 'acme-ideas'), ['title' => 'Anonymous idea'])
            ->assertRedirect(route('guest.login', ['return_to' => '/']));
        $this->postJson(route('feedback.posts.store', 'acme-ideas'), ['title' => 'Anonymous idea'])->assertUnauthorized();
        $this->assertSame(0, FeedbackPost::count());
    }

    public function test_a_guest_submits_an_idea_that_waits_for_review_and_admins_hear_about_it(): void
    {
        $guest = $this->identity(null, 'Gina');
        $this->feedbackPost(); // #1 exists already

        $this->asGuest($guest)->post(route('feedback.posts.store', 'acme-ideas'), [
            'title' => "  <b>Offline</b>   mode\n",
            'body' => "Line one <script>alert(1)</script>\n\n\n\nLine two\x07",
        ])->assertRedirect(route('feedback.post', ['acme-ideas', 2]))->assertSessionHas('success');

        $post = FeedbackPost::where('number', 2)->firstOrFail();
        $this->assertSame('Offline mode', $post->title);
        $this->assertSame("Line one alert(1)\n\nLine two", $post->body);
        $this->assertSame('pending', $post->moderation);
        $this->assertSame($guest->id, $post->author_identity_id);
        $this->assertDatabaseHas('feedback_subscriptions', ['feedback_post_id' => $post->id, 'portal_identity_id' => $guest->id]);

        Notification::assertSentTo($this->admin, FeedbackSubmittedNotification::class, fn ($n) => $n->post->is($post));
        Notification::assertNotSentTo([$this->editor, $this->viewer, $this->outsider], FeedbackSubmittedNotification::class);
        $data = (new FeedbackSubmittedNotification($post))->toDatabase($this->admin);
        $this->assertSame('feedback_submitted', $data['type']);
        $this->assertSame('#2', $data['subject_key']);

        // not public yet
        $this->flushSession();
        $this->get(route('feedback.post', ['acme-ideas', 2]))->assertNotFound();
    }

    public function test_an_admin_submitting_through_their_own_account_is_not_notified(): void
    {
        $this->actingAs($this->admin)->post(route('feedback.posts.store', 'acme-ideas'), ['title' => 'From the team'])->assertRedirect();

        $post = FeedbackPost::firstOrFail();
        $this->assertSame($this->admin->id, $post->author->user_id);
        Notification::assertNotSentTo($this->admin, FeedbackSubmittedNotification::class);
    }

    public function test_submissions_are_validated_and_limited_per_identity(): void
    {
        $guest = $this->identity();
        $store = route('feedback.posts.store', 'acme-ideas');

        $this->asGuest($guest)->post($store, ['title' => '<i></i>ab'])->assertSessionHasErrors('title');
        $this->asGuest($guest)->post($store, ['title' => str_repeat('x', 161)])->assertSessionHasErrors('title');
        $this->asGuest($guest)->post($store, ['title' => 'Fine title', 'body' => str_repeat('x', 5001)])->assertSessionHasErrors('body');

        foreach (range(1, 5) as $i) {
            $this->asGuest($guest)->post($store, ['title' => "Idea number $i"])->assertSessionHasNoErrors();
        }
        $this->asGuest($guest)->post($store, ['title' => 'One too many'])->assertSessionHasErrors('title');
        $this->assertSame(5, FeedbackPost::count());
    }

    public function test_votes_count_once_per_identity_and_can_be_taken_back(): void
    {
        $post = $this->feedbackPost();
        [$ann, $bob] = [$this->identity(), $this->identity()];
        $vote = route('feedback.vote', ['acme-ideas', $post->number]);

        $this->asGuest($ann)->post($vote)->assertRedirect();
        $this->asGuest($ann)->post($vote)->assertRedirect(); // double click / retry
        $this->asGuest($bob)->post($vote)->assertRedirect();
        $this->assertSame(2, $post->fresh()->votes_count);
        $this->assertSame(2, DB::table('feedback_votes')->where('feedback_post_id', $post->id)->count());

        $this->asGuest($ann)->get(route('feedback.post', ['acme-ideas', $post->number]))
            ->assertInertia(fn ($page) => $page->where('post.has_voted', true)->where('post.votes_count', 2));

        $this->asGuest($ann)->delete($vote)->assertRedirect();
        $this->asGuest($ann)->delete($vote)->assertRedirect();
        $this->assertSame(1, $post->fresh()->votes_count);

        // the unique index holds even when two requests race past each other
        FeedbackPost::whereKey($post->id)->update(['votes_count' => 1]);
        DB::table('feedback_votes')->insertOrIgnore(['feedback_post_id' => $post->id, 'portal_identity_id' => $bob->id, 'created_at' => now()]);
        $this->assertFalse(FeedbackVotes::vote($post, $bob));
        $this->assertSame(1, $post->fresh()->votes_count);
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('feedback_votes')->insert(['feedback_post_id' => $post->id, 'portal_identity_id' => $bob->id, 'created_at' => now()]);
    }

    public function test_voting_needs_an_identity_and_a_published_post(): void
    {
        $post = $this->feedbackPost();
        $pending = $this->feedbackPost(['moderation' => 'pending']);
        $guest = $this->identity();

        $this->post(route('feedback.vote', ['acme-ideas', $post->number]))->assertRedirect();
        $this->postJson(route('feedback.vote', ['acme-ideas', $post->number]))->assertUnauthorized();
        $this->asGuest($guest)->post(route('feedback.vote', ['acme-ideas', $pending->number]))->assertNotFound();
        $this->assertSame(0, DB::table('feedback_votes')->count());

        // an unverified identity in the session is no identity
        $unverified = PortalIdentity::create(['email' => 'new@example.com']);
        $this->asGuest($unverified)->postJson(route('feedback.vote', ['acme-ideas', $post->number]))->assertUnauthorized();
    }

    public function test_follow_and_unfollow(): void
    {
        $post = $this->feedbackPost();
        $guest = $this->identity();
        $follow = route('feedback.follow', ['acme-ideas', $post->number]);

        $this->asGuest($guest)->post($follow)->assertRedirect();
        $this->asGuest($guest)->post($follow)->assertRedirect();
        $this->assertSame(1, $post->subscriptions()->count());
        $this->asGuest($guest)->get(route('feedback.post', ['acme-ideas', $post->number]))->assertInertia(fn ($page) => $page->where('post.is_following', true));

        $this->asGuest($guest)->delete($follow)->assertRedirect();
        $this->assertSame(0, $post->subscriptions()->count());
    }

    public function test_the_signed_unfollow_link_works_without_signing_in_and_only_with_a_valid_signature(): void
    {
        $post = $this->feedbackPost();
        $guest = $this->identity();
        FeedbackVotes::follow($post, $guest);
        $url = FeedbackStatusChangedNotification::unfollowUrl($this->portal, $post, $guest->uuid);

        // opening the link only asks (mail scanners)
        $this->get($url)->assertOk()->assertInertia(fn ($page) => $page->component('Feedback/Unsubscribe')->where('done', false));
        $this->assertSame(1, $post->subscriptions()->count());

        $this->post($url.'x')->assertForbidden();
        $this->post(str_replace($guest->uuid, $this->identity()->uuid, $url))->assertForbidden();
        $this->assertSame(1, $post->subscriptions()->count());

        $this->post($url)->assertRedirect($url);
        $this->assertSame(0, $post->subscriptions()->count());
        $this->get($url)->assertInertia(fn ($page) => $page->where('done', true));
    }

    public function test_a_merged_post_redirects_to_its_target(): void
    {
        $target = $this->feedbackPost();
        $dupe = $this->feedbackPost();
        FeedbackModeration::merge($dupe, $target, $this->admin);

        $this->get(route('feedback.post', ['acme-ideas', $dupe->number]))->assertStatus(301)
            ->assertRedirect(route('feedback.post', ['acme-ideas', $target->number]));
        $this->get(route('feedback.portal', 'acme-ideas'))->assertInertia(fn ($page) => $page->has('posts', 1));
    }
}
