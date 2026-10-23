<?php

namespace Tests\Feature\TaskLists;

use App\Models\Board;
use App\Models\Item;
use App\Models\Project;
use App\Models\TaskList;
use App\Models\TaskListItem;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\ItemReminderNotification;
use App\Notifications\TaskDigestNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TaskRemindersTest extends TestCase
{
    use RefreshDatabase;

    private TaskList $list;

    private User $berlin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->berlin = User::factory()->create(['timezone' => 'Europe/Berlin']);
        $workspace = Workspace::factory()->create(['owner_id' => $this->berlin->id]);
        $workspace->addUser($this->berlin, 'admin');
        $project = Project::factory()->create(['workspace_id' => $workspace->id, 'created_by' => $this->berlin->id]);
        $this->list = TaskList::factory()->create(['project_id' => $project->id, 'created_by' => $this->berlin->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function task(array $attributes = []): TaskListItem
    {
        return TaskListItem::factory()->create($attributes + ['task_list_id' => $this->list->id, 'created_by' => $this->berlin->id]);
    }

    private function remindersAt(string $utc): void
    {
        Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
        $this->artisan('app:send-item-reminders')->assertSuccessful();
    }

    private function digestAt(string $utc): void
    {
        Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
        $this->artisan('app:send-task-digest')->assertSuccessful();
    }

    public function test_due_reminder_fires_at_the_chosen_time_in_the_users_time_zone(): void
    {
        // 14:00 in Berlin (CET, UTC+1) = 13:00 UTC; one hour before = 12:00 UTC.
        $this->task(['title' => 'Call the bank', 'due_date' => '2026-11-05', 'due_time' => '14:00', 'reminder_minutes' => 60]);

        $this->remindersAt('2026-11-05 11:58');
        Notification::assertNothingSent();

        $this->remindersAt('2026-11-05 12:01');
        $this->remindersAt('2026-11-05 12:06'); // next scheduler run: no repeat
        Notification::assertSentToTimes($this->berlin, ItemReminderNotification::class, 1);
        Notification::assertSentTo($this->berlin, ItemReminderNotification::class,
            fn (ItemReminderNotification $n) => $n->itemTitle === 'Call the bank' && $n->whenLabel === 'Due in 59 minutes');
    }

    public function test_a_day_ahead_reminder_without_a_due_time_uses_nine_in_the_morning(): void
    {
        $this->task(['due_date' => '2026-11-06', 'reminder_minutes' => 1440]);

        $this->remindersAt('2026-11-05 08:02'); // 09:02 in Berlin, the day before
        Notification::assertSentTo($this->berlin, ItemReminderNotification::class, fn ($n) => $n->whenLabel === 'Due tomorrow');
    }

    public function test_reminders_go_to_the_assignee_and_skip_done_tasks(): void
    {
        $assignee = $this->projectMember();
        $this->task(['assignee_id' => $assignee->id, 'due_date' => '2026-11-05', 'due_time' => '14:00', 'reminder_minutes' => 0]);
        $this->task(['due_date' => '2026-11-05', 'due_time' => '14:00', 'reminder_minutes' => 0, 'status' => 'done']);

        $this->remindersAt('2026-11-05 13:00');

        Notification::assertSentToTimes($assignee, ItemReminderNotification::class, 1);
        Notification::assertNotSentTo($this->berlin, ItemReminderNotification::class);
    }

    public function test_no_reminder_reaches_people_who_cannot_open_the_list(): void
    {
        // A workspace member outside this project, and a creator who has left it.
        $member = User::factory()->create(['timezone' => 'Europe/Berlin']);
        $this->list->project->workspace->addUser($member, 'member');
        $leaver = $this->projectMember();
        $this->task(['title' => 'Secret A', 'assignee_id' => $member->id, 'due_date' => '2026-11-05', 'due_time' => '14:00', 'reminder_minutes' => 0]);
        $this->task(['title' => 'Secret B', 'created_by' => $leaver->id, 'start_date' => '2026-11-05', 'start_time' => '14:10']);
        $this->list->project->users()->detach($leaver->id);

        $this->remindersAt('2026-11-05 13:00');

        Notification::assertNothingSent();
    }

    public function test_day_before_reminder_keeps_the_clock_time_across_a_dst_change(): void
    {
        // Berlin switches to summer time on 2026-03-29: 09:00 CEST due → reminder 09:00 CET the day before = 08:00 UTC.
        $this->task(['due_date' => '2026-03-29', 'due_time' => '09:00', 'reminder_minutes' => 1440]);

        $this->remindersAt('2026-03-28 07:02'); // what subMinutes(1440) would have picked
        Notification::assertNothingSent();

        $this->remindersAt('2026-03-28 08:01');
        Notification::assertSentTo($this->berlin, ItemReminderNotification::class, fn ($n) => $n->whenLabel === 'Due tomorrow at 09:00');
    }

    public function test_a_reminder_missed_by_more_than_half_an_hour_is_skipped(): void
    {
        $this->task(['due_date' => '2026-11-05', 'due_time' => '14:00', 'reminder_minutes' => 60]); // 12:00 UTC

        $this->remindersAt('2026-11-05 12:40');

        Notification::assertNothingSent();
    }

    public function test_board_start_reminders_use_the_assignees_zone_and_access(): void
    {
        $assignee = $this->projectMember();
        $outsider = User::factory()->create(['timezone' => 'Europe/Berlin']);
        $board = Board::factory()->create(['project_id' => $this->list->project_id, 'created_by' => $this->berlin->id]);
        Item::factory()->create(['board_id' => $board->id, 'created_by' => $this->berlin->id, 'assigned_to' => $assignee->id, 'start_date' => '2026-11-05', 'start_time' => '10:00']);
        Item::factory()->create(['board_id' => $board->id, 'created_by' => $this->berlin->id, 'assigned_to' => $outsider->id, 'start_date' => '2026-11-05', 'start_time' => '10:00']);

        $this->remindersAt('2026-11-05 08:50'); // 09:50 in Berlin

        Notification::assertSentTo($assignee, ItemReminderNotification::class, fn ($n) => $n->whenLabel === 'Starts in 10 minutes');
        Notification::assertNotSentTo($outsider, ItemReminderNotification::class);
    }

    private function projectMember(): User
    {
        $user = User::factory()->create(['timezone' => 'Europe/Berlin']);
        $this->list->project->workspace->addUser($user, 'member');
        $this->list->project->users()->attach($user->id, ['role' => 'editor']);

        return $user;
    }

    public function test_start_reminders_use_the_users_time_zone(): void
    {
        $this->task(['start_date' => '2026-11-05', 'start_time' => '10:00']); // 09:00 UTC

        $this->remindersAt('2026-11-05 09:50'); // would be "in 10 min" if read as UTC
        Notification::assertNothingSent();

        $this->remindersAt('2026-11-05 08:50');
        Notification::assertSentTo($this->berlin, ItemReminderNotification::class, fn ($n) => $n->whenLabel === 'Starts in 10 minutes');
    }

    public function test_morning_digest_is_sent_once_after_eight_local_time(): void
    {
        $this->task(['title' => 'Overdue thing', 'due_date' => '2026-11-03']);
        $this->task(['title' => 'Today thing', 'due_date' => '2026-11-05']);
        $this->task(['due_date' => '2026-11-06']);
        $this->task(['due_date' => '2026-11-01', 'status' => 'done']);

        $this->digestAt('2026-11-05 06:30'); // 07:30 in Berlin
        Notification::assertNothingSent();

        $this->digestAt('2026-11-05 07:05'); // 08:05
        $this->digestAt('2026-11-05 09:00');
        Notification::assertSentToTimes($this->berlin, TaskDigestNotification::class, 1);
        Notification::assertSentTo($this->berlin, TaskDigestNotification::class, fn (TaskDigestNotification $n) => $n->dueToday === 1
            && $n->overdue === 1
            && $n->titles === ['Overdue thing', 'Today thing']
            && $n->summary() === '1 due today · 1 overdue');

        $this->digestAt('2026-11-06 07:05'); // next day: again
        Notification::assertSentToTimes($this->berlin, TaskDigestNotification::class, 2);
    }

    public function test_digest_can_be_turned_off_and_skips_lists_the_user_cannot_open(): void
    {
        $outsider = User::factory()->create();
        $this->task(['assignee_id' => $outsider->id, 'due_date' => '2026-11-05']);
        $this->berlin->update(['task_digest' => false]);
        $this->task(['due_date' => '2026-11-05']);

        $this->digestAt('2026-11-05 12:00');

        Notification::assertNothingSent();
    }
}
