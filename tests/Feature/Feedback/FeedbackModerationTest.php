<?php

namespace Tests\Feature\Feedback;

use App\Models\Board;
use App\Models\FeedbackEvent;
use App\Models\FeedbackPortal;
use App\Models\FeedbackPost;
use App\Models\Item;
use App\Models\Project;
use App\Notifications\FeedbackStatusChangedNotification;
use App\Support\Feedback\FeedbackModeration;
use App\Support\Feedback\FeedbackVotes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** BRAI-60: the team side — settings, moderation, status mails, merge, cards, audit. */
class FeedbackModerationTest extends TestCase
{
    use InteractsWithFeedback;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->setUpFeedback();
    }

    public function test_only_project_admins_reach_the_team_page_and_its_actions(): void
    {
        $post = $this->feedbackPost(['moderation' => 'pending']);
        $owner = $this->workspace->owner;

        $requests = [
            ['get', $this->teamRoute('projects.feedback.index'), []],
            ['put', $this->teamRoute('projects.feedback.settings'), ['enabled' => true, 'slug' => 'acme-ideas', 'title' => 'Ideas']],
            ['post', $this->teamRoute('projects.feedback.moderate', [$post->id]), ['decision' => 'approve']],
            ['put', $this->teamRoute('projects.feedback.status', [$post->id]), ['status' => 'planned']],
            ['post', $this->teamRoute('projects.feedback.merge', [$post->id]), ['target_id' => $post->id]],
            ['post', $this->teamRoute('projects.feedback.card.create', [$post->id]), ['board_id' => $this->board->id]],
            ['put', $this->teamRoute('projects.feedback.card.link', [$post->id]), ['key' => 'BRAI-1']],
            ['delete', $this->teamRoute('projects.feedback.card.unlink', [$post->id]), []],
        ];

        foreach ([$this->editor, $this->viewer, $this->outsider] as $user) {
            foreach ($requests as [$method, $url, $data]) {
                $status = $this->actingAs($user)->{$method}($url, $data)->status();
                $this->assertContains($status, [403, 404], "{$user->email} {$method} {$url} → {$status}");
            }
        }
        $this->assertSame('pending', $post->fresh()->moderation);
        $this->assertSame(0, FeedbackEvent::count());

        // guests (no account) are sent to the app login
        auth()->logout();
        $this->get($this->teamRoute('projects.feedback.index'))->assertRedirect(route('login'));

        foreach ([$this->admin, $owner] as $user) {
            $this->actingAs($user)->get($this->teamRoute('projects.feedback.index'))->assertOk()
                ->assertInertia(fn ($page) => $page->component('Projects/Feedback')->where('portal.slug', 'acme-ideas')->where('tab', 'pending')->has('posts', 1));
        }
    }

    public function test_an_admin_creates_and_configures_the_portal(): void
    {
        $this->portal->delete();
        $other = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->admin->id]);
        FeedbackPortal::create(['project_id' => $other->id, 'slug' => 'taken-slug', 'title' => 'Other']);

        $this->actingAs($this->admin)->get($this->teamRoute('projects.feedback.index'))
            ->assertInertia(fn ($page) => $page->where('portal', null)->whereType('suggestedSlug', 'string'));

        $settings = $this->teamRoute('projects.feedback.settings');
        $this->put($settings, ['enabled' => true, 'slug' => 'Bad Slug', 'title' => 'Ideas'])->assertSessionHasErrors('slug');
        $this->put($settings, ['enabled' => true, 'slug' => '-bad', 'title' => 'Ideas'])->assertSessionHasErrors('slug');
        $this->put($settings, ['enabled' => true, 'slug' => 'taken-slug', 'title' => 'Ideas'])->assertSessionHasErrors('slug');
        $this->put($settings, ['enabled' => true, 'slug' => 'my-ideas', 'title' => '<b>Ideas</b>', 'intro' => "Hi <i>there</i>\n\nThanks"])
            ->assertSessionHasNoErrors()->assertRedirect();

        $portal = FeedbackPortal::where('project_id', $this->project->id)->firstOrFail();
        $this->assertTrue($portal->enabled);
        $this->assertSame('Ideas', $portal->title);
        $this->assertSame("Hi there\n\nThanks", $portal->intro);
        $this->get(route('feedback.portal', 'my-ideas'))->assertOk();

        $this->put($settings, ['enabled' => false, 'slug' => 'my-ideas', 'title' => 'Ideas'])->assertSessionHasNoErrors();
        $this->get(route('feedback.portal', 'my-ideas'))->assertNotFound();

        $events = FeedbackEvent::where('feedback_portal_id', $portal->id)->orderBy('id')->get();
        $this->assertSame(['portal_updated', 'portal_updated'], $events->pluck('action')->all());
        $this->assertSame($this->admin->id, $events[0]->user_id);
        $this->assertContains('enabled', $events[1]->changes['fields']);
    }

    public function test_approve_and_reject_are_audited(): void
    {
        $a = $this->feedbackPost(['moderation' => 'pending', 'approved_at' => null]);
        $b = $this->feedbackPost(['moderation' => 'pending', 'approved_at' => null]);

        $this->actingAs($this->admin)->post($this->teamRoute('projects.feedback.moderate', [$a->id]), ['decision' => 'approve'])->assertRedirect();
        $this->post($this->teamRoute('projects.feedback.moderate', [$a->id]), ['decision' => 'approve'])->assertRedirect(); // repeat: no new event
        $this->post($this->teamRoute('projects.feedback.moderate', [$b->id]), ['decision' => 'reject'])->assertRedirect();
        $this->post($this->teamRoute('projects.feedback.moderate', [$b->id]), ['decision' => 'maybe'])->assertSessionHasErrors('decision');

        $this->assertSame('approved', $a->fresh()->moderation);
        $this->assertNotNull($a->fresh()->approved_at);
        $this->assertSame('rejected', $b->fresh()->moderation);
        $this->get(route('feedback.post', ['acme-ideas', $a->number]))->assertOk();
        $this->get(route('feedback.post', ['acme-ideas', $b->number]))->assertNotFound();

        $events = FeedbackEvent::orderBy('id')->get();
        $this->assertSame(['approved', 'rejected'], $events->pluck('action')->all());
        $this->assertSame([$a->id, $b->id], $events->pluck('feedback_post_id')->all());
        $this->assertSame($this->admin->name, $events[0]->actor_name);
        $this->assertNotNull($events[0]->created_at);
    }

    public function test_a_status_change_mails_current_followers_once(): void
    {
        $post = $this->feedbackPost();
        [$ann, $bob, $cid] = [$this->identity('ann@example.com'), $this->identity('bob@example.com'), $this->identity('cid@example.com')];
        foreach ([$ann, $bob, $cid] as $identity) {
            FeedbackVotes::follow($post, $identity);
        }
        FeedbackVotes::unfollow($post, $cid); // opted out
        $status = $this->teamRoute('projects.feedback.status', [$post->id]);

        $this->actingAs($this->admin)->put($status, ['status' => 'planned', 'note' => 'Coming in Q1'])->assertRedirect();
        $this->put($status, ['status' => 'planned', 'note' => 'Coming in Q1'])->assertRedirect(); // retry
        $this->put($status, ['status' => 'planned', 'note' => 'Coming in Q2'])->assertRedirect(); // note only
        $this->put($status, ['status' => 'building'])->assertRedirect();
        $this->put($status, ['status' => 'planned'])->assertRedirect(); // back again: no second "planned" mail
        $this->put($status, ['status' => 'shipped'])->assertSessionHasErrors('status');

        Notification::assertSentToTimes($ann, FeedbackStatusChangedNotification::class, 2);
        Notification::assertSentTo($ann, FeedbackStatusChangedNotification::class, fn ($n) => $n->status === 'planned' && $n->note === 'Coming in Q1');
        Notification::assertSentTo($ann, FeedbackStatusChangedNotification::class, fn ($n) => $n->status === 'building');
        Notification::assertSentToTimes($bob, FeedbackStatusChangedNotification::class, 2);
        Notification::assertNotSentTo($cid, FeedbackStatusChangedNotification::class);
        $this->assertSame(4, DB::table('feedback_status_mails')->count());

        $fresh = $post->fresh();
        $this->assertSame('planned', $fresh->status);
        $this->assertNull($fresh->status_note);
        $this->assertSame(4, FeedbackEvent::where('action', 'status_changed')->count()); // the retry changed nothing

        // the mail: subject, the public note, a signed unfollow link
        $mail = (new FeedbackStatusChangedNotification($fresh, $this->portal, 'planned', 'Coming in Q1'))->toMail($ann);
        $this->assertStringContainsString('is now Planned', $mail->subject);
        $this->assertStringContainsString('signature=', $mail->footerLinks[0]['url']); // "Stop following this idea" in the footer (BRAI-155)
        $this->assertStringContainsString('Stop following this idea', (string) $mail->render());
        $this->assertSame(['mail'], (new FeedbackStatusChangedNotification($fresh, $this->portal, 'planned', null))->via($ann));
    }

    public function test_a_pending_post_changes_status_silently(): void
    {
        $post = $this->feedbackPost(['moderation' => 'pending']);
        FeedbackVotes::follow($post, $author = $this->identity());

        $this->actingAs($this->admin)->put($this->teamRoute('projects.feedback.status', [$post->id]), ['status' => 'planned'])->assertRedirect();

        Notification::assertNothingSentTo($author);
    }

    public function test_merge_unions_votes_and_followers_without_double_counting_and_redirects(): void
    {
        $target = $this->feedbackPost(['title' => 'Dark mode']);
        $dupe = $this->feedbackPost(['title' => 'Night theme', 'moderation' => 'pending']);
        $older = $this->feedbackPost(['title' => 'Black UI']);
        [$ann, $bob, $cid] = [$this->identity(), $this->identity(), $this->identity()];

        FeedbackVotes::vote($target, $ann);
        FeedbackVotes::vote($target, $bob);
        FeedbackVotes::follow($target, $bob);
        // the dupe is pending: votes come from before (e.g. it was published, then reopened) — insert directly
        DB::table('feedback_votes')->insert([
            ['feedback_post_id' => $dupe->id, 'portal_identity_id' => $bob->id, 'created_at' => now()],
            ['feedback_post_id' => $dupe->id, 'portal_identity_id' => $cid->id, 'created_at' => now()],
        ]);
        FeedbackPost::whereKey($dupe->id)->update(['votes_count' => 2]);
        FeedbackVotes::follow($dupe, $bob);
        FeedbackVotes::follow($dupe, $cid);
        // an earlier duplicate already points at the dupe
        FeedbackPost::whereKey($older->id)->update(['merged_into_id' => $dupe->id]);

        $merge = $this->teamRoute('projects.feedback.merge', [$dupe->id]);
        $this->actingAs($this->admin)->post($merge, ['target_id' => $target->id])->assertRedirect()->assertSessionHasNoErrors();
        $this->post($merge, ['target_id' => $target->id])->assertSessionHasNoErrors(); // retry: same result

        $target->refresh();
        $this->assertSame(3, $target->votes_count); // ann, bob (once), cid
        $this->assertSame(3, DB::table('feedback_votes')->where('feedback_post_id', $target->id)->count());
        $this->assertSame([$bob->id, $cid->id], DB::table('feedback_subscriptions')->where('feedback_post_id', $target->id)->orderBy('portal_identity_id')->pluck('portal_identity_id')->all());
        $this->assertSame(0, DB::table('feedback_votes')->where('feedback_post_id', $dupe->id)->count());
        $this->assertSame(0, $dupe->fresh()->votes_count);
        $this->assertSame($target->id, $dupe->fresh()->merged_into_id);
        $this->assertSame($target->id, $older->fresh()->merged_into_id);

        // a URL that was public redirects (chains follow); a never-published duplicate stays hidden
        $this->get(route('feedback.post', ['acme-ideas', $older->number]))
            ->assertStatus(301)->assertRedirect(route('feedback.post', ['acme-ideas', $target->number]));
        $this->get(route('feedback.post', ['acme-ideas', $dupe->number]))->assertNotFound();
        FeedbackPost::whereKey($dupe->id)->update(['author_identity_id' => $cid->id]);
        auth()->logout(); // as the guest author, not the signed-in admin
        $this->asGuest($cid)->get(route('feedback.post', ['acme-ideas', $dupe->number]))
            ->assertStatus(301)->assertRedirect(route('feedback.post', ['acme-ideas', $target->number]));
        // following or voting on the merged duplicate does nothing
        $this->asGuest($cid)->post(route('feedback.follow', ['acme-ideas', $dupe->number]))->assertNotFound();
        $this->assertFalse(FeedbackVotes::follow($dupe->fresh(), $ann));
        $this->assertSame(0, DB::table('feedback_subscriptions')->where('feedback_post_id', $dupe->id)->count());

        $event = FeedbackEvent::where('action', 'merged')->sole();
        $this->assertSame($dupe->id, $event->feedback_post_id);
        $this->assertSame(['title' => 'Night theme', 'into_number' => $target->number, 'into_title' => 'Dark mode', 'votes_before' => 2, 'votes_after' => 3], $event->changes);
        $this->assertSame($this->admin->id, $event->user_id);

        // a merged post's voter acts on the target now: voting again counts nothing
        $this->assertFalse(FeedbackVotes::vote($target, $cid));
        $this->assertSame(3, $target->fresh()->votes_count);
    }

    public function test_merge_rejects_itself_other_portals_and_unpublished_targets(): void
    {
        $a = $this->feedbackPost();
        $pending = $this->feedbackPost(['moderation' => 'pending']);
        $otherProject = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->admin->id]);
        $otherPortal = FeedbackPortal::create(['project_id' => $otherProject->id, 'slug' => 'other-ideas', 'title' => 'Other', 'enabled' => true]);
        $foreign = $this->feedbackPost([], $otherPortal);

        $merge = $this->teamRoute('projects.feedback.merge', [$a->id]);
        $this->actingAs($this->admin)->post($merge, ['target_id' => $a->id])->assertSessionHasErrors('target');
        $this->post($merge, ['target_id' => $foreign->id])->assertSessionHasErrors('target_id');
        $this->post($merge, ['target_id' => $pending->id])->assertSessionHasErrors('target');
        $this->assertNull($a->fresh()->merged_into_id);
        $this->assertSame(0, FeedbackEvent::count());
    }

    public function test_create_card_is_idempotent_and_stays_in_the_project(): void
    {
        $post = $this->feedbackPost(['title' => 'Bulk export', 'body' => "Please <add>\n\nit"]);
        $card = $this->teamRoute('projects.feedback.card.create', [$post->id]);
        $before = Item::count();

        $this->actingAs($this->admin)->post($card, ['board_id' => $this->board->id])->assertRedirect()->assertSessionHasNoErrors();
        $this->post($card, ['board_id' => $this->board->id])->assertRedirect()->assertSessionHasNoErrors();
        [$item] = FeedbackModeration::createCard($post->fresh(), $this->board, $this->admin);

        $this->assertSame($before + 1, Item::count());
        $this->assertSame($item->id, $post->fresh()->item_id);
        $this->assertSame('Bulk export', $item->title);
        $this->assertSame($this->board->id, $item->board_id);
        $this->assertStringContainsString('&lt;add&gt;', $item->description);
        $this->assertSame(1, FeedbackEvent::where('action', 'card_created')->count());

        // a board of another project is refused
        $post2 = $this->feedbackPost();
        $otherProject = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->admin->id]);
        $otherBoard = Board::factory()->create(['project_id' => $otherProject->id, 'created_by' => $this->admin->id]);
        $this->post($this->teamRoute('projects.feedback.card.create', [$post2->id]), ['board_id' => $otherBoard->id])->assertSessionHasErrors('board_id');
        $this->assertNull($post2->fresh()->item_id);
        $this->assertSame($before + 1, Item::count());

        // the card is internal: the public page shows nothing of it
        $this->get(route('feedback.post', ['acme-ideas', $post->number]))->assertInertia(fn ($page) => $page->missing('post.item')->missing('post.item_id'));
    }

    public function test_create_card_on_a_board_without_columns_is_a_validation_error(): void
    {
        $post = $this->feedbackPost();
        $empty = Board::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->admin->id]);
        $empty->columns()->delete();

        $this->actingAs($this->admin)->post($this->teamRoute('projects.feedback.card.create', [$post->id]), ['board_id' => $empty->id])
            ->assertSessionHasErrors('board_id');
        $this->assertNull($post->fresh()->item_id);
        $this->assertSame(0, FeedbackEvent::count());
    }

    public function test_link_card_accepts_only_issues_of_the_same_project(): void
    {
        $post = $this->feedbackPost();
        $own = $this->item(['key' => 'BRAI-5']);
        $otherProject = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->admin->id, 'key' => 'OTH']);
        $otherBoard = Board::factory()->create(['project_id' => $otherProject->id, 'created_by' => $this->admin->id]);
        $foreign = Item::factory()->create(['board_id' => $otherBoard->id, 'created_by' => $this->admin->id, 'key' => 'OTH-1']);
        $link = $this->teamRoute('projects.feedback.card.link', [$post->id]);

        $this->actingAs($this->admin)->put($link, ['key' => 'OTH-1'])->assertSessionHasErrors('key');
        $this->put($link, ['item_id' => $foreign->id])->assertSessionHasErrors('key');
        $this->assertNull($post->fresh()->item_id);
        $this->expectsValidation(fn () => FeedbackModeration::linkCard($post, $foreign, $this->admin));

        $this->put($link, ['key' => 'brai-5'])->assertSessionHasNoErrors();
        $this->put($link, ['key' => 'BRAI-5'])->assertSessionHasNoErrors(); // same card again: no-op
        $this->assertSame($own->id, $post->fresh()->item_id);
        $this->assertSame(1, FeedbackEvent::where('action', 'card_linked')->count());

        $this->delete($this->teamRoute('projects.feedback.card.unlink', [$post->id]))->assertRedirect();
        $this->assertNull($post->fresh()->item_id);
        $this->assertSame(['title' => $post->title, 'card' => 'BRAI-5'], FeedbackEvent::where('action', 'card_unlinked')->sole()->changes);
    }

    public function test_another_projects_post_is_not_found(): void
    {
        $otherProject = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->admin->id]);
        $otherPortal = FeedbackPortal::create(['project_id' => $otherProject->id, 'slug' => 'other-ideas', 'title' => 'Other', 'enabled' => true]);
        $foreign = $this->feedbackPost(['moderation' => 'pending'], $otherPortal);

        $this->actingAs($this->admin)->post($this->teamRoute('projects.feedback.moderate', [$foreign->id]), ['decision' => 'approve'])->assertNotFound();
        $this->put($this->teamRoute('projects.feedback.status', [$foreign->id]), ['status' => 'planned'])->assertNotFound();
        $this->post($this->teamRoute('projects.feedback.card.create', [$foreign->id]), ['board_id' => $this->board->id])->assertNotFound();
        $this->assertSame('pending', $foreign->fresh()->moderation);
    }

    public function test_the_team_page_lists_the_audit_log_and_post_details(): void
    {
        $guest = $this->identity('gina@example.com', 'Gina');
        $post = $this->feedbackPost(['moderation' => 'pending', 'author_identity_id' => $guest->id]);
        FeedbackModeration::moderate($post, $this->admin, 'approve');
        FeedbackModeration::setStatus($post, $this->admin, 'building', 'On it');

        $this->actingAs($this->admin)->get($this->teamRoute('projects.feedback.index', ['tab' => 'published']))
            ->assertInertia(fn ($page) => $page
                ->where('tab', 'published')
                ->where('posts.0.author.email', 'gina@example.com')
                ->where('posts.0.status', 'building')
                ->where('counts.published', 1)
                ->has('events', 2)
                ->where('events.0.action', 'status_changed')
                ->where('events.0.actor_name', $this->admin->name)
                ->where('portal.public_url', route('feedback.portal', 'acme-ideas')));
    }

    private function expectsValidation(\Closure $callback): void
    {
        try {
            $callback();
            $this->fail('expected a validation error');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }
}
