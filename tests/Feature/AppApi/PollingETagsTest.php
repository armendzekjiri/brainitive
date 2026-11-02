<?php

namespace Tests\Feature\AppApi;

use App\Models\BillingActivity;
use App\Models\MyDayEntry;
use App\Models\User;
use App\Support\Billing\WorkTimers;
use App\Support\MyDay\MyDayPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * ETag / If-None-Match (BRAI-440) on what the desktop tray, mobile and TV poll: the notification badge,
 * the focus timer and My Day. The tag changes exactly when the answer changes, is per user, and a
 * matching tag answers 304 without a body.
 */
class PollingETagsTest extends TestCase
{
    use ActsAsAppClient;
    use ForgetsGuardsBetweenRequests;
    use InteractsWithBoards;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        Carbon::setTestNow(Carbon::parse('2026-11-10 08:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function poll(string $path, ?User $user = null, ?string $etag = null)
    {
        $headers = $this->appHeaders($this->appToken($user ?? $this->editor));

        return $this->getJson($path, $etag ? $headers + ['If-None-Match' => $etag] : $headers);
    }

    private function notify(User $user, string $text): void
    {
        $user->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'App\\Notifications\\Test', 'data' => ['message' => $text]]);
    }

    public function test_unread_count_answers_304_until_the_count_changes(): void
    {
        $this->notify($this->editor, 'one');
        $path = '/api/v1/notifications/unread-count';

        $first = $this->poll($path)->assertOk()->assertJsonPath('data.unread_count', 1);
        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $first->assertHeader('Cache-Control', 'no-cache, private');

        $this->poll($path, null, $etag)->assertStatus(304)->assertNoContent(304);
        // a wrong tag still answers in full
        $this->poll($path, null, '"nope"')->assertOk();

        $this->notify($this->editor, 'two');
        $changed = $this->poll($path, null, $etag)->assertOk()->assertJsonPath('data.unread_count', 2);
        $this->assertNotSame($etag, $changed->headers->get('ETag'));
    }

    public function test_the_unread_count_tag_is_per_user(): void
    {
        $this->notify($this->editor, 'a');
        $this->notify($this->viewer, 'b'); // the same count: 1
        $path = '/api/v1/notifications/unread-count';

        $mine = $this->poll($path)->headers->get('ETag');
        $theirs = $this->poll($path, $this->viewer)->headers->get('ETag');

        $this->assertNotSame($mine, $theirs);
        // another user's tag never gives a 304 (and so never confirms anything about their data)
        $this->poll($path, $this->viewer, $mine)->assertOk()->assertJsonPath('data.unread_count', 1);
    }

    public function test_timer_answers_304_while_nothing_changes_even_as_time_passes(): void
    {
        $path = '/api/v1/me/timer';

        $idle = $this->poll($path)->assertOk()->assertJsonPath('data.running', false);
        $idleTag = $idle->headers->get('ETag');
        $this->poll($path, null, $idleTag)->assertStatus(304);

        $item = $this->item(['title' => 'Fix login']);
        WorkTimers::start($this->editor, $item);
        $running = $this->poll($path, null, $idleTag)->assertOk()->assertJsonPath('data.running', true);
        $runningTag = $running->headers->get('ETag');
        $this->assertNotSame($idleTag, $runningTag);

        // the elapsed time ticks by itself: still 304
        Carbon::setTestNow(now()->addMinutes(5));
        $this->poll($path, null, $runningTag)->assertStatus(304);

        // a real change (the issue is renamed, an activity appears) changes the tag
        $item->update(['title' => 'Renamed']);
        $renamed = $this->poll($path, null, $runningTag)->assertOk()->assertJsonPath('data.timer.issue.title', 'Renamed');
        $this->assertNotSame($runningTag, $renamed->headers->get('ETag'));

        BillingActivity::create(['workspace_id' => $this->workspace->id, 'name' => 'Programming', 'pricing_type' => 'hourly', 'rate_cents' => 100]);
        $withActivity = $this->poll($path, null, $renamed->headers->get('ETag'))->assertOk()->assertJsonPath('data.activity_required', true);
        $this->assertNotSame($renamed->headers->get('ETag'), $withActivity->headers->get('ETag'));

        // stopping changes it back
        $this->postJson('/api/v1/me/timer/discard', ['timer_id' => $running->json('data.timer.id')], $this->appHeaders($this->appToken($this->editor)))->assertOk();
        $this->poll($path, null, $withActivity->headers->get('ETag'))->assertOk()->assertJsonPath('data.running', false);
    }

    public function test_a_timer_tag_never_leaks_across_users(): void
    {
        WorkTimers::start($this->editor, $this->item(['title' => 'Private work']));
        $path = '/api/v1/me/timer';

        $mine = $this->poll($path)->assertOk()->headers->get('ETag');
        $other = $this->poll($path, $this->viewer, $mine)->assertOk()->assertJsonPath('data.running', false);
        $this->assertNotSame($mine, $other->headers->get('ETag'));
        $this->assertStringNotContainsString('Private work', $other->getContent());
    }

    public function test_my_day_answers_304_until_the_plan_changes_and_is_per_user(): void
    {
        $path = '/api/v1/me/my-day';
        $item = $this->item(['title' => 'Planned']);

        $empty = $this->poll($path)->assertOk()->assertJsonCount(0, 'data.items');
        $emptyTag = $empty->headers->get('ETag');
        $this->poll($path, null, $emptyTag)->assertStatus(304)->assertNoContent(304);

        MyDayEntry::create(['user_id' => $this->editor->id, 'work_type' => 'board', 'work_id' => $item->id, 'plan_date' => MyDayPlan::today($this->editor), 'position' => 1]);
        $planned = $this->poll($path, null, $emptyTag)->assertOk()->assertJsonCount(1, 'data.items');
        $plannedTag = $planned->headers->get('ETag');
        $this->assertNotSame($emptyTag, $plannedTag);
        $this->poll($path, null, $plannedTag)->assertStatus(304);

        // a change inside the item shows up too
        $item->update(['title' => 'Renamed']);
        $this->assertNotSame($plannedTag, $this->poll($path, null, $plannedTag)->assertOk()->headers->get('ETag'));

        // someone else with an empty day neither gets a 304 on this tag nor sees the plan
        $other = $this->poll($path, $this->viewer, $plannedTag)->assertOk()->assertJsonCount(0, 'data.items');
        $this->assertNotSame($plannedTag, $other->headers->get('ETag'));
        $this->assertSame($emptyTag === $other->headers->get('ETag'), false); // the empty tag is the editor's, not shared
    }
}
