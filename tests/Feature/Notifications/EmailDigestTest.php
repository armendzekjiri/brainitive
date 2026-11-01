<?php

namespace Tests\Feature\Notifications;

use App\Jobs\SendNotificationDigest;
use App\Models\Board;
use App\Models\Comment;
use App\Models\Item;
use App\Models\PendingNotificationEmail;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\Channels\DigestMailChannel;
use App\Notifications\ItemAssignedNotification;
use App\Notifications\ItemCommentedNotification;
use App\Notifications\MentionNotification;
use App\Notifications\VerifyEmailNotification;
use App\Notifications\WorkspaceRoleChangedNotification;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mime\Email;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * BRAI-429: activity emails wait 60 s from the first one and go out as one email per person and
 * workspace; the bell stays instant. Real rendered mails (array mailer); the delayed job is captured
 * with Queue::fake() and run by hand after travelling past the window.
 */
class EmailDigestTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    /** Digest jobs already run by runDueJobs(). */
    private int $ran = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        config(['notifications.email_digest_seconds' => 60, 'queue.default' => 'database']);
        Queue::fake();
        $this->emailOn($this->viewer);
    }

    private function emailOn(User $user, array $categories = ['assignments', 'comments', 'mentions', 'workspace']): void
    {
        $prefs = $user->notification_preferences ?? [];
        foreach ($categories as $category) {
            $prefs[$category] = ['in_app' => true, 'push' => false, 'email' => true];
        }
        $user->update(['notification_preferences' => $prefs]);
    }

    private function assign(Item $item, User $to): void
    {
        $this->actingAs($this->editor)
            ->patch($this->itemRoute('boards.items.update', $item), ['assigned_to' => $to->id])
            ->assertSessionHasNoErrors();
    }

    /** @return list<Email> */
    private function mails(): array
    {
        $transport = app('mail.manager')->mailer()->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);

        return $transport->messages()->map(fn ($sent) => $sent->getOriginalMessage())->values()->all();
    }

    /** Runs every digest job queued since the last call, like a worker at the current (travelled) time. */
    private function runDueJobs(): void
    {
        $jobs = Queue::pushed(SendNotificationDigest::class)->values();
        foreach ($jobs->slice($this->ran) as $job) {
            $job->handle();
        }
        $this->ran = $jobs->count();
    }

    /** @return list<SendNotificationDigest> */
    private function jobs(): array
    {
        return Queue::pushed(SendNotificationDigest::class)->values()->all();
    }

    private function otherBoard(Workspace $workspace, string $key): Board
    {
        $project = Project::factory()->create(['workspace_id' => $workspace->id, 'created_by' => $workspace->owner_id, 'key' => $key]);
        $project->users()->attach($this->viewer->id, ['role' => 'viewer']);
        $project->users()->attach($this->editor->id, ['role' => 'editor']);

        return Board::factory()->create(['project_id' => $project->id, 'created_by' => $workspace->owner_id]);
    }

    public function test_five_assignments_in_a_minute_are_five_bell_notifications_at_once_and_one_email(): void
    {
        $items = collect(range(1, 5))->map(fn (int $i) => $this->item(['title' => "Task number {$i}"]));
        foreach ($items as $item) {
            $this->assign($item, $this->viewer);
        }

        // bell: immediately, one per assignment
        $this->assertSame(5, $this->viewer->notifications()->count());
        // mail: nothing yet, one window with one delayed job
        $this->assertCount(0, $this->mails());
        $this->assertSame(5, PendingNotificationEmail::where('user_id', $this->viewer->id)->count());
        $this->assertCount(1, $this->jobs());
        $this->assertEqualsWithDelta(now()->addSeconds(60)->getTimestamp(), $this->jobs()[0]->delay->getTimestamp(), 1);

        // a worker that runs the job early sends nothing
        $this->travel(30)->seconds();
        $this->runDueJobs();
        $this->assertCount(0, $this->mails());

        $this->travel(31)->seconds();
        $this->jobs()[0]->handle();

        $mails = $this->mails();
        $this->assertCount(1, $mails);
        $mail = $mails[0];
        $this->assertSame($this->viewer->email, $mail->getTo()[0]->getAddress());
        $this->assertSame("5 updates in {$this->workspace->name}: 5 assignments", $mail->getSubject());
        $html = (string) $mail->getHtmlBody();
        $text = (string) $mail->getTextBody();
        foreach ($items as $item) {
            $item->refresh();
            $url = route('boards.items.show', [$this->workspace->slug, $this->project->slug, $this->board->slug, $item->id]);
            $this->assertStringContainsString(e($item->key.' · '.$item->title), $html);
            $this->assertStringContainsString('href="'.e($url).'"', $html);
            $this->assertStringContainsString($url, $text);
        }
        $this->assertSame(5, substr_count($html, 'class="entry"'));
        $this->assertStringContainsString('Manage notifications', $html);
        $this->assertSame(0, PendingNotificationEmail::count());
    }

    public function test_a_single_event_is_one_normal_email_after_the_window(): void
    {
        $item = $this->item(['title' => 'Fix the login redirect']);
        $this->assign($item, $this->viewer);
        $item->refresh();

        $this->assertCount(0, $this->mails());
        $this->travel(61)->seconds();
        $this->runDueJobs();

        $mails = $this->mails();
        $this->assertCount(1, $mails);
        $this->assertSame("{$this->editor->name} assigned you to {$item->key}: Fix the login redirect", $mails[0]->getSubject());
        $html = (string) $mails[0]->getHtmlBody();
        $this->assertStringContainsString('Open issue', $html);
        $this->assertStringNotContainsString('class="entry"', $html);
        $this->assertStringContainsString('Assignments', $html); // footer: why you got this
    }

    public function test_events_after_the_window_go_into_the_next_digest_without_duplicates_or_gaps(): void
    {
        [$a, $b, $c] = [$this->item(['title' => 'Alpha task']), $this->item(['title' => 'Bravo task']), $this->item(['title' => 'Charlie task'])];

        $this->assign($a, $this->viewer);
        $this->travel(30)->seconds();
        $this->assign($b, $this->viewer); // same window (fixed: not extended)
        $this->travel(31)->seconds();
        $this->assign($c, $this->viewer); // window 1 has ended, its job hasn't run yet → window 2

        $this->assertCount(2, $this->jobs());
        $this->assertSame(
            $this->jobs()[0]->delay->getTimestamp() + 61,
            $this->jobs()[1]->delay->getTimestamp(),
        );

        $this->runDueJobs(); // worker catches up: job 1 sends window 1, job 2 is early
        $this->assertCount(1, $this->mails());
        $first = (string) $this->mails()[0]->getHtmlBody();
        $this->assertStringContainsString('Alpha task', $first);
        $this->assertStringContainsString('Bravo task', $first);
        $this->assertStringNotContainsString('Charlie task', $first);

        $this->travel(61)->seconds();
        $this->jobs()[1]->handle();
        $mails = $this->mails();
        $this->assertCount(2, $mails);
        $second = (string) $mails[1]->getHtmlBody();
        $this->assertStringContainsString('Charlie task', $second);
        $this->assertStringNotContainsString('Alpha task', $second);
        $this->assertSame(0, PendingNotificationEmail::count());
    }

    public function test_a_retried_or_concurrent_job_never_sends_twice_and_loses_nothing(): void
    {
        $this->assign($this->item(['title' => 'Alpha task']), $this->viewer);
        $this->assign($this->item(['title' => 'Bravo task']), $this->viewer);
        $job = $this->jobs()[0];
        $this->travel(61)->seconds();

        // the first attempt claimed the rows and crashed before sending
        PendingNotificationEmail::query()->update(['claim_token' => $job->claimToken, 'claimed_at' => now()]);

        // a second job for the same person + workspace (two workers, a duplicate dispatch) can't take them
        $duplicate = new SendNotificationDigest($this->viewer->id, $this->workspace->id);
        $duplicate->handle();
        $this->assertCount(0, $this->mails());
        $this->assertSame(2, PendingNotificationEmail::count());

        // the retry (same job, same token) sends them — once
        $job->handle();
        $job->handle();
        $duplicate->handle();
        $this->assertCount(1, $this->mails());
        $this->assertStringContainsString('2 updates in', $this->mails()[0]->getSubject());
        $this->assertSame(0, PendingNotificationEmail::count());
    }

    public function test_a_job_that_fails_every_try_hands_its_lines_to_the_next_digest(): void
    {
        $this->assign($this->item(['title' => 'Alpha task']), $this->viewer);
        $job = $this->jobs()[0];
        $this->travel(61)->seconds();
        PendingNotificationEmail::query()->update(['claim_token' => $job->claimToken, 'claimed_at' => now()]);

        $job->failed(new \RuntimeException('SMTP down'));
        $this->assertSame(1, PendingNotificationEmail::whereNull('claim_token')->count());

        $this->assign($this->item(['title' => 'Bravo task']), $this->viewer);
        $this->travel(61)->seconds();
        $this->runDueJobs();

        $this->assertCount(1, $this->mails());
        $html = (string) $this->mails()[0]->getHtmlBody();
        $this->assertStringContainsString('Alpha task', $html);
        $this->assertStringContainsString('Bravo task', $html);
    }

    public function test_transactional_and_other_emails_are_never_batched(): void
    {
        $this->viewer->notifyNow(new VerifyEmailNotification);
        $this->viewer->notifyNow(new ResetPassword('token'));
        $this->viewer->notifyNow(new WorkspaceRoleChangedNotification($this->workspace, $this->editor, 'member', 'editor'));

        $this->assertCount(3, $this->mails());
        $this->assertSame(0, PendingNotificationEmail::count());
        Queue::assertNotPushed(SendNotificationDigest::class);

        // only eligible types swap the mail channel
        $this->assertContains('mail', (new WorkspaceRoleChangedNotification($this->workspace, $this->editor, 'member', 'editor'))->via($this->viewer));
        $via = (new ItemAssignedNotification($this->item(), $this->editor))->via($this->viewer);
        $this->assertContains(DigestMailChannel::class, $via);
        $this->assertContains('database', $via);
        $this->assertNotContains('mail', $via);
    }

    public function test_email_turned_off_sends_nothing_even_for_waiting_lines(): void
    {
        $this->viewer->update(['notification_preferences' => ['assignments' => ['in_app' => true, 'push' => false, 'email' => false]]]);
        $this->assign($this->item(), $this->viewer);
        $this->assertSame(0, PendingNotificationEmail::count());
        $this->assertSame(1, $this->viewer->notifications()->count());

        // turned off while a line was waiting
        $this->emailOn($this->viewer);
        $this->assign($this->item(), $this->viewer);
        $this->assertSame(1, PendingNotificationEmail::count());
        $this->viewer->update(['notification_preferences' => ['assignments' => ['in_app' => true, 'push' => false, 'email' => false]]]);

        $this->travel(61)->seconds();
        $this->runDueJobs();
        $this->assertCount(0, $this->mails());
        $this->assertSame(0, PendingNotificationEmail::count());
    }

    public function test_a_line_is_dropped_when_the_person_lost_access_meanwhile(): void
    {
        $opsBoard = $this->otherBoard($this->workspace, 'OPS');
        $secret = Item::factory()->create(['board_id' => $opsBoard->id, 'created_by' => $this->editor->id, 'title' => 'Secret salary review']);
        $visible = $this->item(['title' => 'Public roadmap']);

        $this->assign($visible, $this->viewer);
        $this->actingAs($this->editor)
            ->patch(route('boards.items.update', [$this->workspace->slug, $opsBoard->project->slug, $opsBoard->slug, $secret->id]), ['assigned_to' => $this->viewer->id])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, PendingNotificationEmail::count());

        $opsBoard->project->users()->detach($this->viewer->id);

        $this->travel(61)->seconds();
        $this->runDueJobs();

        $mails = $this->mails();
        $this->assertCount(1, $mails);
        $this->assertStringContainsString('Public roadmap', $mails[0]->getSubject());
        $all = $mails[0]->getSubject().$mails[0]->getHtmlBody().$mails[0]->getTextBody();
        $this->assertStringNotContainsString('Secret salary review', $all);
        $this->assertStringNotContainsString('OPS-', $all);
    }

    public function test_two_workspaces_get_two_separate_emails(): void
    {
        $owner = User::factory()->create();
        $other = Workspace::factory()->create(['owner_id' => $owner->id, 'name' => 'Globex Labs']);
        $other->addUser($owner, 'admin');
        $other->addUser($this->viewer, 'member');
        $other->addUser($this->editor, 'member');
        $globexBoard = $this->otherBoard($other, 'GLX');
        $globexItem = Item::factory()->create(['board_id' => $globexBoard->id, 'created_by' => $this->editor->id, 'title' => 'Globex launch plan']);

        $this->assign($this->item(['title' => 'Acme task one']), $this->viewer);
        $this->assign($this->item(['title' => 'Acme task two']), $this->viewer);
        $this->actingAs($this->editor)
            ->patch(route('boards.items.update', [$other->slug, $globexBoard->project->slug, $globexBoard->slug, $globexItem->id]), ['assigned_to' => $this->viewer->id])
            ->assertSessionHasNoErrors();

        $this->assertCount(2, $this->jobs());
        $this->travel(61)->seconds();
        $this->runDueJobs();

        $mails = collect($this->mails());
        $this->assertCount(2, $mails);
        $acme = $mails->first(fn (Email $m) => str_contains((string) $m->getSubject(), $this->workspace->name));
        $globex = $mails->first(fn (Email $m) => str_contains((string) $m->getSubject(), 'Globex launch plan'));
        $this->assertNotNull($acme);
        $this->assertNotNull($globex);
        $this->assertStringNotContainsString('Globex', (string) $acme->getHtmlBody());
        $this->assertStringNotContainsString('Acme task', (string) $globex->getHtmlBody());
    }

    public function test_assignments_comments_and_mentions_share_one_digest_and_exact_duplicates_are_dropped(): void
    {
        $item = $this->item(['title' => 'Shared issue']);
        $this->assign($item, $this->viewer);
        $item->refresh();

        $comment = Comment::create(['item_id' => $item->id, 'user_id' => $this->editor->id, 'body' => '<p>Please look at <b>this</b></p>']);
        $this->viewer->notifyNow(new ItemCommentedNotification($item, $comment, $this->editor));
        $mention = new MentionNotification('issue', $item->key.' - '.$item->title, route('boards.items.show', [$this->workspace->slug, $this->project->slug, $this->board->slug, $item->id]), $this->editor, $item);
        $this->viewer->notifyNow($mention);
        $this->viewer->notifyNow($mention); // the same event again (a retried job)

        $this->assertSame(3, PendingNotificationEmail::count());
        $this->assertSame(4, $this->viewer->notifications()->count()); // the bell is never deduped or delayed

        $this->travel(61)->seconds();
        $this->runDueJobs();

        $mails = $this->mails();
        $this->assertCount(1, $mails);
        $this->assertStringContainsString('3 updates in', $mails[0]->getSubject());
        $this->assertStringContainsString('1 assignment', $mails[0]->getSubject());
        $this->assertStringContainsString('1 comment', $mails[0]->getSubject());
        $this->assertStringContainsString('1 mention', $mails[0]->getSubject());
        $this->assertStringContainsString('Please look at this', (string) $mails[0]->getHtmlBody());
    }

    public function test_interval_zero_or_the_sync_queue_send_at_once_as_before(): void
    {
        config(['notifications.email_digest_seconds' => 0]);
        $this->assign($this->item(['title' => 'Right now']), $this->viewer);
        $this->assertCount(1, $this->mails());

        config(['notifications.email_digest_seconds' => 60, 'queue.default' => 'sync']);
        $this->assign($this->item(['title' => 'Also right now']), $this->viewer);
        $this->assertCount(2, $this->mails());

        $this->assertSame(0, PendingNotificationEmail::count());
        Queue::assertNotPushed(SendNotificationDigest::class);
    }
}
