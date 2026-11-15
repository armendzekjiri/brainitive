<?php

namespace Tests\Feature\MyDay;

use App\Models\MyDayEntry;
use App\Models\TaskList;
use App\Models\TaskListItem;
use App\Support\MyDay\MyDayPlan;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * My Week (v1.53.0): free-form lines and linked tasks planned on any day, moved between days,
 * shown as a Monday-to-Sunday week on My Work.
 */
class MyWeekTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        $this->withoutVite();
        Carbon::setTestNow(Carbon::parse('2026-11-18 12:00', 'UTC')); // a Wednesday
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function note(string $date, string $title, array $extra = [])
    {
        return $this->actingAs($this->editor)->post(route('my-day.entries.store'), ['plan_date' => $date, 'title' => $title] + $extra);
    }

    /** @return array<string, list<string>> date => line titles (note title or task title) */
    private function week(string $query = 'plan=week'): array
    {
        $days = [];
        $this->actingAs($this->editor)->get(route('my-work.index').'?'.$query)
            ->assertInertia(function (AssertableInertia $p) use (&$days) {
                $p->where('plan', 'week');
                foreach ($p->toArray()['props']['myWeek']['days'] as $day) {
                    $days[$day['date']] = array_map(fn ($line) => $line['title'] ?? $line['item']['title'], $day['lines']);
                }
            });

        return $days;
    }

    public function test_notes_and_linked_tasks_can_be_planned_on_any_day_of_the_week(): void
    {
        $item = $this->item(['title' => 'Ship the release', 'due_date' => '2026-12-01']);
        $before = $item->only(['column_id', 'due_date', 'assigned_to', 'completed_at']);

        $this->note('2026-11-20', 'Call the bank', ['color' => 'green'])->assertSessionHasNoErrors();
        $this->post(route('my-day.entries.store'), ['plan_date' => '2026-11-20', 'work_type' => 'board', 'work_id' => $item->id])->assertSessionHasNoErrors();
        $this->note('2026-11-16', 'Monday thing');

        $week = $this->week();
        $this->assertSame(['2026-11-16', '2026-11-17', '2026-11-18', '2026-11-19', '2026-11-20', '2026-11-21', '2026-11-22'], array_keys($week));
        $this->assertSame(['Monday thing'], $week['2026-11-16']);
        $this->assertSame(['Call the bank', 'Ship the release'], $week['2026-11-20']);
        $this->assertSame('green', MyDayEntry::where('title', 'Call the bank')->sole()->color);
        $this->assertEquals($before, $item->fresh()->only(['column_id', 'due_date', 'assigned_to', 'completed_at']));
    }

    public function test_any_date_shows_its_monday_to_sunday_week_and_the_default_is_this_week(): void
    {
        $this->note('2026-11-27', 'Next week');

        $this->assertSame('2026-11-23', array_key_first($this->week('plan=week&week=2026-11-27')));
        $this->assertSame(['Next week'], $this->week('plan=week&week=2026-11-27')['2026-11-27']);
        $this->assertSame('2026-11-16', array_key_first($this->week('plan=week&week=nonsense')));

        $this->actingAs($this->editor)->get(route('my-work.index'))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('plan', 'day')->where('myWeek', null));
    }

    public function test_the_week_follows_the_users_time_zone(): void
    {
        $this->editor->update(['timezone' => 'Pacific/Auckland']);
        Carbon::setTestNow(Carbon::parse('2026-11-22 12:00', 'UTC')); // already Monday 23rd in Auckland

        $this->assertSame('2026-11-23', array_key_first($this->week()));
    }

    public function test_notes_can_be_renamed_coloured_ticked_and_deleted(): void
    {
        $this->note('2026-11-18', 'Draft');
        $entry = MyDayEntry::sole();

        $this->patch(route('my-day.entries.update', $entry), ['title' => '  Final  ', 'color' => 'blue', 'done' => true])->assertSessionHasNoErrors();
        $entry->refresh();
        $this->assertSame(['Final', 'blue'], [$entry->title, $entry->color]);
        $this->assertNotNull($entry->completed_at);

        $this->patch(route('my-day.entries.update', $entry), ['done' => false, 'color' => null]);
        $this->assertNull($entry->fresh()->completed_at);
        $this->assertNull($entry->fresh()->color);

        $this->patch(route('my-day.entries.update', $entry), ['title' => '   '])->assertSessionHasErrors('title');
        $this->patch(route('my-day.entries.update', $entry), ['color' => 'chartreuse'])->assertSessionHasErrors('color');

        $this->delete(route('my-day.entries.destroy', $entry));
        $this->assertSame(0, MyDayEntry::count());
    }

    public function test_linked_lines_are_not_edited_through_the_plan(): void
    {
        $item = $this->item();
        $entry = MyDayPlan::add($this->editor, 'board', $item->id, '2026-11-18');

        $this->actingAs($this->editor)->patch(route('my-day.entries.update', $entry), ['done' => true])->assertStatus(422);
        $this->assertNull($item->fresh()->completed_at);
    }

    public function test_lines_move_between_days_in_order_and_a_task_planned_twice_merges(): void
    {
        $item = $this->item(['title' => 'Linked']);
        $this->note('2026-11-17', 'A');
        $this->note('2026-11-18', 'B');
        $tue = MyDayPlan::add($this->editor, 'board', $item->id, '2026-11-17');
        $wed = MyDayPlan::add($this->editor, 'board', $item->id, '2026-11-18');
        [$a, $b] = [MyDayEntry::where('title', 'A')->sole(), MyDayEntry::where('title', 'B')->sole()];

        // drag A and Tuesday's copy of the task onto Wednesday, above B
        $this->post(route('my-day.entries.reorder'), ['plan_date' => '2026-11-18', 'ids' => [$a->id, $tue->id, $b->id, $wed->id]])
            ->assertSessionHasNoErrors();

        $this->assertSame([], $this->week()['2026-11-17']);
        $this->assertSame(['A', 'B', 'Linked'], $this->week()['2026-11-18']);
        $this->assertNull(MyDayEntry::find($tue->id)); // merged into Wednesday's line
    }

    public function test_move_to_puts_any_line_at_the_end_of_another_day(): void
    {
        $item = $this->item(['title' => 'Linked']);
        $this->note('2026-11-25', 'Already there');
        $this->note('2026-11-18', 'Moving note');
        $linked = MyDayPlan::add($this->editor, 'board', $item->id, '2026-11-18');
        $note = MyDayEntry::where('title', 'Moving note')->sole();

        $this->patch(route('my-day.entries.update', $note), ['plan_date' => '2026-11-25'])->assertSessionHasNoErrors();
        $this->patch(route('my-day.entries.update', $linked), ['plan_date' => '2026-11-25'])->assertSessionHasNoErrors();

        $this->assertSame([], $this->week()['2026-11-18']);
        $this->assertSame(['Already there', 'Moving note', 'Linked'], $this->week('plan=week&week=2026-11-25')['2026-11-25']);
    }

    public function test_someday_holds_lines_without_a_day_and_nothing_else_sees_them(): void
    {
        $item = $this->item(['title' => 'Linked later']);
        $this->note('someday', 'Learn Rust')->assertSessionHasNoErrors();
        $this->post(route('my-day.entries.store'), ['plan_date' => 'someday', 'work_type' => 'board', 'work_id' => $item->id])->assertSessionHasNoErrors();
        $this->note('2026-11-18', 'Today thing');
        $today = MyDayEntry::where('title', 'Today thing')->sole();

        // move today's line to Someday, then a Someday line onto Friday
        $this->patch(route('my-day.entries.update', $today), ['plan_date' => 'someday'])->assertSessionHasNoErrors();
        $rust = MyDayEntry::where('title', 'Learn Rust')->sole();
        $this->post(route('my-day.entries.reorder'), ['plan_date' => '2026-11-20', 'ids' => [$rust->id]])->assertSessionHasNoErrors();

        $this->get(route('my-work.index').'?plan=week')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('myWeek.someday', fn ($lines) => collect($lines)->map(fn ($l) => $l['title'] ?? $l['item']['title'])->all() === ['Linked later', 'Today thing'])
            ->where('myDay.rows', [])
            ->where('myDay.notes', [])
            ->where('myDay.carryOverCount', 0));
        $this->assertSame(['Learn Rust'], $this->week()['2026-11-20']);
        $this->assertNull($today->fresh()->plan_date);
        $this->assertSame(0, MyDayPlan::forDate($this->editor, '2026-11-18')->count());
    }

    public function test_a_task_is_on_someday_once_and_a_full_list_takes_no_more_lines(): void
    {
        $item = $this->item();
        foreach ([1, 2] as $_) {
            $this->actingAs($this->editor)->post(route('my-day.entries.store'), ['plan_date' => 'someday', 'work_type' => 'board', 'work_id' => $item->id]);
        }
        $this->assertSame(1, MyDayEntry::whereNull('plan_date')->count());
        // the database refuses a second Someday copy too (partial unique index)
        try {
            // in a savepoint, so the refused insert doesn't abort the test's transaction (PostgreSQL)
            DB::transaction(fn () => MyDayEntry::create(['user_id' => $this->editor->id, 'work_type' => 'board', 'work_id' => $item->id, 'plan_date' => null, 'position' => 9]));
            $this->fail('A second Someday copy was stored.');
        } catch (UniqueConstraintViolationException) {
            // expected
        }

        MyDayEntry::insert(collect(range(1, MyDayPlan::LIST_LIMIT))->map(fn (int $i) => [
            'user_id' => $this->editor->id, 'work_type' => 'note', 'title' => "Line {$i}", 'plan_date' => '2026-11-19', 'position' => $i,
            'created_at' => now(), 'updated_at' => now(),
        ])->all());
        $this->note('2026-11-19', 'One too many')->assertSessionHasErrors('plan_date');
        $this->note('2026-11-18', 'Elsewhere');
        $this->patch(route('my-day.entries.update', MyDayEntry::where('title', 'Elsewhere')->sole()), ['plan_date' => '2026-11-19'])->assertSessionHasErrors('plan_date');
        $this->assertSame(MyDayPlan::LIST_LIMIT, MyDayEntry::where('plan_date', '2026-11-19')->count());
    }

    public function test_only_own_lines_and_openable_work_can_be_planned(): void
    {
        $this->note('2026-11-18', 'Mine');
        $mine = MyDayEntry::sole();

        $this->actingAs($this->viewer)->patch(route('my-day.entries.update', $mine), ['title' => 'Hijacked'])->assertNotFound();
        $this->actingAs($this->viewer)->delete(route('my-day.entries.destroy', $mine))->assertNotFound();
        $this->actingAs($this->viewer)->post(route('my-day.entries.reorder'), ['plan_date' => '2026-11-19', 'ids' => [$mine->id]]);
        $this->assertSame(['Mine', '2026-11-18'], [$mine->fresh()->title, $mine->fresh()->plan_date->toDateString()]);

        $item = $this->item();
        $this->actingAs($this->outsider)->post(route('my-day.entries.store'), ['plan_date' => '2026-11-18', 'work_type' => 'board', 'work_id' => $item->id])->assertForbidden();
        $this->assertSame(1, MyDayEntry::count());
    }

    public function test_dates_must_be_real_and_within_a_year(): void
    {
        foreach (['2026-02-30', 'tomorrow', '2028-01-01', '2025-01-01'] as $date) {
            $this->note($date, 'Nope')->assertSessionHasErrors('plan_date');
        }
        $this->note('2026-11-18', '')->assertSessionHasErrors('title');
        $this->assertSame(0, MyDayEntry::count());
    }

    public function test_the_week_hides_work_the_user_lost_access_to(): void
    {
        $item = $this->item(['title' => 'Secret']);
        MyDayPlan::add($this->editor, 'board', $item->id, '2026-11-18');
        $this->note('2026-11-18', 'Still here');

        $this->project->users()->detach($this->editor->id);
        $this->workspace->users()->detach($this->editor->id);

        $this->assertSame(['Still here'], $this->week()['2026-11-18']);
    }

    public function test_todays_notes_show_on_my_day_and_unfinished_ones_are_moved_over(): void
    {
        $item = $this->item(['title' => 'Task']);
        $this->note('2026-11-18', 'Today note');
        MyDayPlan::add($this->editor, 'board', $item->id, '2026-11-18');
        $this->note('2026-11-17', 'Yesterday open');
        $this->note('2026-11-17', 'Yesterday done');
        MyDayEntry::where('title', 'Yesterday done')->update(['completed_at' => now()]);

        $this->get(route('my-work.index'))->assertInertia(fn (AssertableInertia $p) => $p
            ->where('myDay.notes', fn ($notes) => collect($notes)->pluck('title')->all() === ['Today note'])
            ->where('myDay.rows', fn ($rows) => collect($rows)->pluck('item.title')->all() === ['Task'])
            ->where('myDay.carryOverCount', 1));

        $this->post(route('my-day.carry-over'))->assertSessionHas('success', 'Brought over 1 task.');

        $this->assertSame('2026-11-18', MyDayEntry::where('title', 'Yesterday open')->sole()->plan_date->toDateString());
        $this->assertSame('2026-11-17', MyDayEntry::where('title', 'Yesterday done')->sole()->plan_date->toDateString());
        $this->assertSame(['Today note', 'Task', 'Yesterday open'], $this->week()['2026-11-18']);
    }

    public function test_search_finds_issues_and_list_tasks_the_user_can_open(): void
    {
        $item = $this->item(['title' => 'Quarterly report draft']);
        $list = TaskList::factory()->create(['project_id' => $this->project->id]);
        $task = TaskListItem::factory()->create(['task_list_id' => $list->id, 'title' => 'Quarterly taxes']);

        $results = $this->actingAs($this->editor)->getJson(route('my-day.search', ['q' => 'quarterly']))->assertOk()->json('results');
        $found = collect($results)->map(fn ($r) => "{$r['work_type']}:{$r['work_id']}")->sort()->values()->all();
        $this->assertSame(collect(["board:{$item->id}", "list:{$task->id}"])->sort()->values()->all(), $found);

        $this->actingAs($this->outsider)->getJson(route('my-day.search', ['q' => 'quarterly']))->assertOk()->assertJsonCount(0, 'results');
        $this->actingAs($this->editor)->getJson(route('my-day.search', ['q' => 'q']))->assertOk()->assertJsonCount(0, 'results');
    }
}
