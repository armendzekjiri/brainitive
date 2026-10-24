<?php

namespace Tests\Feature\MyDay;

use App\Models\Item;
use App\Models\MyDayEntry;
use App\Models\TaskList;
use App\Models\TaskListItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

class MyDayTest extends TestCase
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

    private function add(string $type, int $id)
    {
        return $this->actingAs($this->editor)->post(route('my-day.store'), ['work_type' => $type, 'work_id' => $id]);
    }

    public function test_adding_to_my_day_never_changes_the_task_and_is_idempotent(): void
    {
        $item = $this->item(['due_date' => '2026-12-01', 'assigned_to' => $this->viewer->id]);
        $before = $item->only(['column_id', 'due_date', 'assigned_to', 'completed_at']);

        $this->add('board', $item->id)->assertSessionHasNoErrors();
        $this->add('board', $item->id);

        $this->assertSame(1, MyDayEntry::count());
        $this->assertEquals($before, $item->fresh()->only(['column_id', 'due_date', 'assigned_to', 'completed_at']));
    }

    public function test_the_plan_date_is_the_users_local_day(): void
    {
        $this->editor->update(['timezone' => 'Pacific/Auckland']); // UTC+13 in November
        Carbon::setTestNow(Carbon::parse('2026-11-05 12:00', 'UTC'));

        $this->add('board', $this->item()->id);

        $this->assertSame('2026-11-06', MyDayEntry::sole()->plan_date->toDateString());
    }

    public function test_my_work_shows_the_plan_in_order_and_hides_work_the_user_lost_access_to(): void
    {
        $a = $this->item(['title' => 'First']);
        $b = $this->item(['title' => 'Second']);
        $list = TaskList::factory()->create(['project_id' => $this->project->id]);
        $task = TaskListItem::factory()->create(['task_list_id' => $list->id, 'title' => 'Buy milk']);
        $this->add('board', $a->id);
        $this->add('board', $b->id);
        $this->add('list', $task->id);

        $this->post(route('my-day.reorder'), ['items' => [
            ['work_type' => 'list', 'work_id' => $task->id],
            ['work_type' => 'board', 'work_id' => $b->id],
            ['work_type' => 'board', 'work_id' => $a->id],
        ]])->assertSessionHasNoErrors();

        $this->get(route('my-work.index'))->assertInertia(fn (AssertableInertia $p) => $p
            ->where('myDay.rows', fn ($rows) => collect($rows)->pluck('item.title')->all() === ['Buy milk', 'Second', 'First'])
            ->where('myDay.keys', fn ($keys) => collect($keys)->sort()->values()->all() === collect(["board:{$a->id}", "board:{$b->id}", "list:{$task->id}"])->sort()->values()->all()));

        $this->project->users()->detach($this->editor->id);
        $this->get(route('my-work.index'))->assertInertia(fn (AssertableInertia $p) => $p->where('myDay.rows', []));
        $this->assertSame(3, MyDayEntry::count()); // hidden, not deleted
    }

    public function test_board_items_carry_the_columns_the_circle_completes_and_reopens_into(): void
    {
        $item = $this->item(['column_id' => $this->column('to-do')]);
        $this->add('board', $item->id);

        $this->get(route('my-work.index'))->assertInertia(fn (AssertableInertia $p) => $p
            ->where('myDay.rows.0.item.board.done_column_id', $this->column('done'))
            ->where('myDay.rows.0.item.board.open_column_id', $this->column('to-do')));

        // the circle uses the normal move endpoint: done, then reopened
        $this->patch($this->itemRoute('boards.items.move', $item), ['column_id' => $this->column('done'), 'position' => 0])->assertSessionHasNoErrors();
        $this->assertNotNull($item->fresh()->completed_at);
        $this->patch($this->itemRoute('boards.items.move', $item), ['column_id' => $this->column('to-do'), 'position' => 0])->assertSessionHasNoErrors();
        $this->assertNull($item->fresh()->completed_at);
    }

    public function test_only_work_the_user_can_open_can_be_planned_and_removing_works(): void
    {
        $item = $this->item();

        $task = TaskListItem::factory()->create(['task_list_id' => TaskList::factory()->create(['project_id' => $this->project->id])->id]);

        $this->actingAs($this->outsider)->post(route('my-day.store'), ['work_type' => 'board', 'work_id' => $item->id])->assertForbidden();
        $this->post(route('my-day.store'), ['work_type' => 'list', 'work_id' => $task->id])->assertForbidden();
        $this->post(route('my-day.store'), ['work_type' => 'page', 'work_id' => 1])->assertSessionHasErrors('work_type');

        $this->app['auth']->forgetGuards();
        $this->add('board', $item->id);
        // Another user's plan for the same task is untouched by my remove / reorder.
        $other = MyDayEntry::create(['user_id' => $this->viewer->id, 'work_type' => 'board', 'work_id' => $item->id, 'plan_date' => now()->toDateString(), 'position' => 7]);
        $this->post(route('my-day.reorder'), ['items' => [['work_type' => 'board', 'work_id' => $item->id]]]);
        $this->delete(route('my-day.destroy'), ['work_type' => 'board', 'work_id' => $item->id])->assertSessionHasNoErrors();
        $this->assertSame([$other->id], MyDayEntry::pluck('id')->all());
        $this->assertSame(7, $other->fresh()->position);
    }

    public function test_unfinished_work_carries_over_only_when_asked(): void
    {
        $open = $this->item(['title' => 'Still open']);
        $done = $this->item(['title' => 'Finished', 'completed_at' => now()]);
        $yesterday = now()->subDay()->toDateString();
        $elsewhere = Item::factory()->create(['title' => 'Other project']); // no access
        foreach ([$open, $done, $elsewhere] as $i => $item) {
            MyDayEntry::create(['user_id' => $this->editor->id, 'work_type' => 'board', 'work_id' => $item->id, 'plan_date' => $yesterday, 'position' => $i]);
        }

        $this->actingAs($this->editor)->get(route('my-work.index'))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('myDay.rows', [])->where('myDay.carryOverCount', 1));

        $this->post(route('my-day.carry-over'))->assertSessionHas('success', 'Brought over 1 task.');

        $this->get(route('my-work.index'))->assertInertia(fn (AssertableInertia $p) => $p
            ->where('myDay.rows', fn ($rows) => collect($rows)->pluck('item.title')->all() === ['Still open'])
            ->where('myDay.carryOverCount', 0));
    }
}
