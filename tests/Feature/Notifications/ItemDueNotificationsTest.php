<?php

namespace Tests\Feature\Notifications;

use App\Notifications\ItemDueNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * BRAI-103: item_due — "due tomorrow" and "overdue" for assigned board issues, in the assignee's
 * time zone, once per issue, kind and due date (sent_item_reminders), with the existing reminders run.
 */
class ItemDueNotificationsTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        Notification::fake();
        $this->editor->update(['timezone' => 'Europe/Berlin']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function remindersAt(string $utc): void
    {
        Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
        $this->artisan('app:send-item-reminders')->assertSuccessful();
    }

    private function sent(string $kind): int
    {
        return Notification::sent($this->editor, ItemDueNotification::class)->filter(fn (ItemDueNotification $n) => $n->kind === $kind)->count();
    }

    public function test_due_tomorrow_goes_out_from_eight_in_the_morning_local_time_once(): void
    {
        $this->item(['assigned_to' => $this->editor->id, 'due_date' => '2026-11-06', 'due_time' => '14:00', 'key' => 'BRAI-5']);

        $this->remindersAt('2026-11-05 06:30'); // 07:30 in Berlin
        $this->assertSame(0, $this->sent(ItemDueNotification::DUE_TOMORROW));

        $this->remindersAt('2026-11-05 07:05'); // 08:05 in Berlin
        $this->remindersAt('2026-11-05 07:10');
        $this->assertSame(1, $this->sent(ItemDueNotification::DUE_TOMORROW));

        Notification::assertSentTo($this->editor, ItemDueNotification::class, function (ItemDueNotification $n) {
            $d = $n->toDatabase($this->editor);

            return $d['type'] === 'item_due' && $d['title'] === 'BRAI-5 is due tomorrow' && str_ends_with($d['body'], 'Due tomorrow at 14:00') && $d['subject_key'] === 'BRAI-5';
        });
    }

    public function test_overdue_once_after_the_due_moment_and_not_for_old_or_done_issues(): void
    {
        $this->item(['assigned_to' => $this->editor->id, 'due_date' => '2026-11-05', 'key' => 'BRAI-6']);
        $this->item(['assigned_to' => $this->editor->id, 'due_date' => '2026-10-01']); // long overdue: no flood
        $this->item(['assigned_to' => $this->editor->id, 'due_date' => '2026-11-05', 'column_id' => $this->column('done')]);

        $this->remindersAt('2026-11-05 22:30'); // 23:30 in Berlin: still due today
        $this->assertSame(0, $this->sent(ItemDueNotification::OVERDUE));

        $this->remindersAt('2026-11-05 23:05'); // 00:05 the next day in Berlin
        $this->remindersAt('2026-11-06 09:00');
        $this->assertSame(1, $this->sent(ItemDueNotification::OVERDUE));
        Notification::assertSentTo($this->editor, ItemDueNotification::class, fn (ItemDueNotification $n) => $n->kind === ItemDueNotification::OVERDUE && $n->toDatabase($this->editor)['title'] === 'BRAI-6 is overdue');
    }

    public function test_a_new_due_date_is_reminded_again_and_unassigned_issues_never(): void
    {
        $item = $this->item(['assigned_to' => $this->editor->id, 'due_date' => '2026-11-06']);
        $this->item(['due_date' => '2026-11-06']);

        $this->remindersAt('2026-11-05 09:00');
        $item->update(['due_date' => '2026-11-07']);
        $this->remindersAt('2026-11-06 09:00');

        $this->assertSame(2, $this->sent(ItemDueNotification::DUE_TOMORROW));
    }

    public function test_the_reminders_category_preference_decides_the_channels(): void
    {
        $item = $this->item(['assigned_to' => $this->editor->id, 'due_date' => '2026-11-06']);
        $notification = new ItemDueNotification($item, ItemDueNotification::DUE_TOMORROW, 'Due tomorrow');

        $this->assertSame(['database', WebPushChannel::class], $notification->via($this->editor));

        $this->editor->update(['notification_preferences' => ['reminders' => ['in_app' => true, 'push' => false, 'email' => true]]]);
        $this->assertSame(['database', 'mail'], $notification->via($this->editor->fresh()));

        $this->editor->update(['notification_preferences' => ['reminders' => ['in_app' => false, 'push' => false, 'email' => false]]]);
        $this->assertSame([], $notification->via($this->editor->fresh()));
    }
}
