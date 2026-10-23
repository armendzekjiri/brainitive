<?php

namespace Tests\Feature\MyDay;

use App\Models\ActivityLog;
use App\Models\WorkTimer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

class WorkTimerTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function logs(): int
    {
        return ActivityLog::where('action', 'work_logged')->count();
    }

    public function test_one_running_timer_per_user_shown_on_every_page(): void
    {
        $a = $this->item(['key' => 'BRAI-99', 'title' => 'Write tests']); // a key the counter won't hand out
        $b = $this->item();

        $this->actingAs($this->editor)->post(route('work-timer.start'), ['item_id' => $a->id])->assertSessionHasNoErrors();
        $this->post(route('work-timer.start'), ['item_id' => $b->id])->assertSessionHasErrors('timer');

        $this->assertSame($a->id, WorkTimer::sole()->item_id);
        $this->get(route('my-work.index'))->assertInertia(fn (AssertableInertia $p) => $p
            ->where('activeTimer.item.key', 'BRAI-99')
            ->where('activeTimer.item.title', 'Write tests'));
    }

    public function test_stopping_logs_the_reviewed_time_once(): void
    {
        $item = $this->item();
        $this->actingAs($this->editor)->post(route('work-timer.start'), ['item_id' => $item->id]);
        $id = WorkTimer::sole()->id;

        $this->post(route('work-timer.stop'), ['timer_id' => $id, 'time' => '25m', 'note' => 'Deep work'])->assertSessionHas('success');
        $this->post(route('work-timer.stop'), ['timer_id' => $id, 'time' => '25m'])->assertSessionHas('success', 'This timer was already stopped.');

        $this->assertSame(25, (int) $item->fresh()->time_spent);
        $this->assertSame(1, $this->logs());
        $this->assertSame('Deep work', ActivityLog::where('action', 'work_logged')->sole()->metadata['note']);
        $this->assertSame(0, WorkTimer::count());
    }

    public function test_discarding_logs_nothing_and_bad_durations_keep_the_timer(): void
    {
        $item = $this->item();
        $this->actingAs($this->editor)->post(route('work-timer.start'), ['item_id' => $item->id]);

        $id = WorkTimer::sole()->id;

        $this->post(route('work-timer.stop'), ['timer_id' => $id, 'time' => 'soon'])->assertSessionHasErrors('time');
        $this->assertSame(1, WorkTimer::count());

        $this->delete(route('work-timer.discard'), ['timer_id' => $id]);
        $this->assertSame([0, 0], [WorkTimer::count(), $this->logs()]);
    }

    public function test_only_people_who_may_log_work_can_time_it(): void
    {
        $item = $this->item();
        $this->actingAs($this->viewer)->post(route('work-timer.start'), ['item_id' => $item->id])->assertForbidden();

        // Lost the right while the timer ran: the timer stops, nothing is logged.
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->editor)->post(route('work-timer.start'), ['item_id' => $item->id]);
        $id = WorkTimer::sole()->id;
        $this->project->users()->detach($this->editor->id);
        $this->get(route('my-work.index'))->assertInertia(fn (AssertableInertia $p) => $p->where('activeTimer.item', null)); // title hidden
        $this->post(route('work-timer.stop'), ['timer_id' => $id, 'time' => '10m'])->assertSessionHasErrors('timer');

        $this->assertSame([0, 0], [WorkTimer::count(), $this->logs()]);
    }

    public function test_a_stale_tab_cannot_stop_or_discard_a_newer_timer(): void
    {
        $first = $this->item();
        $second = $this->item();
        $this->actingAs($this->editor)->post(route('work-timer.start'), ['item_id' => $first->id]);
        $old = WorkTimer::sole()->id;
        $this->post(route('work-timer.stop'), ['timer_id' => $old, 'time' => '5m']);
        $this->post(route('work-timer.start'), ['item_id' => $second->id]); // e.g. in another tab

        $this->post(route('work-timer.stop'), ['timer_id' => $old, 'time' => '30m'])->assertSessionHas('success', 'This timer was already stopped.');
        $this->delete(route('work-timer.discard'), ['timer_id' => $old]);

        $this->assertSame([5, 0], [(int) $first->fresh()->time_spent, (int) $second->fresh()->time_spent]);
        $this->assertSame($second->id, WorkTimer::sole()->item_id);
    }

    public function test_starting_on_an_unknown_issue_is_a_404(): void
    {
        $this->actingAs($this->editor)->post(route('work-timer.start'), ['item_id' => 999999])->assertNotFound();
    }

    public function test_elapsed_time_comes_from_the_start_also_across_midnight(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-05 23:50', 'UTC'));
        $this->actingAs($this->editor)->post(route('work-timer.start'), ['item_id' => $this->item()->id]);

        Carbon::setTestNow(Carbon::parse('2026-11-06 00:20', 'UTC'));
        $this->get(route('my-work.index'))->assertInertia(fn (AssertableInertia $p) => $p->where('activeTimer.elapsed_minutes', 30));
    }
}
