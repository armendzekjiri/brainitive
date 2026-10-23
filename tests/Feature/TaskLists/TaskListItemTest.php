<?php

namespace Tests\Feature\TaskLists;

use App\Models\Project;
use App\Models\TaskList;
use App\Models\TaskListItem;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class TaskListItemTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Project $project;

    private TaskList $list;

    private User $editor;

    private User $viewer;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $owner = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $this->workspace->addUser($owner, 'admin');
        $this->project = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $owner->id]);
        $this->editor = $this->member('editor');
        $this->viewer = $this->member('viewer');
        $this->outsider = User::factory()->create();
        $this->list = TaskList::factory()->create(['project_id' => $this->project->id, 'created_by' => $owner->id, 'slug' => 'groceries']);
    }

    private function member(string $role): User
    {
        $user = User::factory()->create();
        $this->workspace->addUser($user, 'member');
        $this->project->users()->attach($user->id, ['role' => $role]);

        return $user;
    }

    private function url(string $name, array $extra = []): string
    {
        return route($name, [$this->workspace->slug, $this->project->slug, $this->list->slug, ...$extra]);
    }

    private function item(array $attributes = []): TaskListItem
    {
        return TaskListItem::factory()->create($attributes + ['task_list_id' => $this->list->id, 'created_by' => $this->editor->id]);
    }

    public function test_editor_adds_a_task_with_due_date_and_reminder(): void
    {
        $this->actingAs($this->editor)->post($this->url('task-lists.items.store'), [
            'title' => 'Buy milk',
            'due_date' => '2026-11-05',
            'due_time' => '14:00',
            'reminder_minutes' => 60,
            'assignee_id' => $this->viewer->id,
        ])->assertSessionHasNoErrors();

        $item = $this->list->items()->sole();
        $this->assertSame(['Buy milk', 60, $this->viewer->id], [$item->title, $item->reminder_minutes, $item->assignee_id]);

        $this->patch($this->url('task-lists.items.update', [$item->id]), ['reminder_minutes' => null])->assertSessionHasNoErrors();
        $this->assertNull($item->fresh()->reminder_minutes);
    }

    public function test_reminder_and_assignee_are_validated(): void
    {
        $this->actingAs($this->editor)->post($this->url('task-lists.items.store'), ['title' => 'x', 'reminder_minutes' => 30])
            ->assertSessionHasErrors('reminder_minutes');
        $this->post($this->url('task-lists.items.store'), ['title' => 'x', 'assignee_id' => $this->outsider->id])
            ->assertSessionHasErrors('assignee_id');
        $this->assertSame(0, $this->list->items()->count());
    }

    public function test_viewers_and_outsiders_cannot_change_tasks(): void
    {
        $item = $this->item();

        $this->actingAs($this->viewer)->patch($this->url('task-lists.items.update', [$item->id]), ['status' => 'done'])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->outsider)->get($this->url('task-lists.show'))->assertForbidden();
        $this->assertSame('todo', $item->fresh()->status);
    }

    public function test_a_task_of_another_list_is_not_reachable_through_this_list(): void
    {
        $other = TaskListItem::factory()->create(['task_list_id' => TaskList::factory()->create(['project_id' => $this->project->id])->id]);

        $this->actingAs($this->editor)->patch($this->url('task-lists.items.update', [$other->id]), ['title' => 'hijack'])->assertNotFound();
        $this->assertNotSame('hijack', $other->fresh()->title);
    }

    public function test_the_page_gets_plain_dates_for_the_date_inputs(): void
    {
        $this->item(['due_date' => '2026-11-05', 'start_date' => '2026-11-01']);

        $this->actingAs($this->viewer)->get($this->url('task-lists.show'))
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Lists/Show')
                ->where('taskList.items.0.due_date', '2026-11-05')
                ->where('taskList.items.0.start_date', '2026-11-01'));
    }

    public function test_completing_a_recurring_task_moves_it_to_the_next_date(): void
    {
        $item = $this->item(['due_date' => '2026-11-05', 'recurrence' => 'weekly']);

        $this->actingAs($this->editor)->patch($this->url('task-lists.items.update', [$item->id]), ['status' => 'done'])->assertSessionHasNoErrors();

        $item->refresh();
        $this->assertSame(['todo', '2026-11-12'], [$item->status, $item->due_date->toDateString()]);
    }

    public function test_a_monthly_task_on_the_31st_moves_to_the_end_of_next_month(): void
    {
        $item = $this->item(['due_date' => '2027-01-31', 'recurrence' => 'monthly']);

        $this->actingAs($this->editor)->patch($this->url('task-lists.items.update', [$item->id]), ['status' => 'done']);

        $this->assertSame('2027-02-28', $item->fresh()->due_date->toDateString());
    }

    public function test_a_repeating_task_without_a_due_date_is_simply_done(): void
    {
        $item = $this->item(['recurrence' => 'daily']);

        $this->actingAs($this->editor)->patch($this->url('task-lists.items.update', [$item->id]), ['status' => 'done']);

        $this->assertSame('done', $item->fresh()->status);
    }

    public function test_tasks_are_reordered(): void
    {
        $a = $this->item(['position' => 0]);
        $b = $this->item(['position' => 1]);

        $this->actingAs($this->editor)->postJson($this->url('task-lists.items.reorder'), [
            'items' => [['id' => $b->id, 'position' => 0], ['id' => $a->id, 'position' => 1]],
        ])->assertOk();

        $this->assertSame([1, 0], [$a->fresh()->position, $b->fresh()->position]);
    }

    public function test_time_zone_and_daily_summary_are_saved_as_preferences(): void
    {
        $this->actingAs($this->editor)->patchJson(route('profile.preferences'), ['timezone' => 'Europe/Berlin'])->assertNoContent();
        $this->patchJson(route('profile.preferences'), ['timezone' => 'Mars/Olympus'])->assertJsonValidationErrors('timezone');
        $this->patchJson(route('profile.preferences'), ['task_digest' => false])->assertNoContent();

        $this->editor->refresh();
        $this->assertSame(['Europe/Berlin', false], [$this->editor->timezone, $this->editor->task_digest]);
        $this->assertSame('Europe/Berlin', $this->editor->localTimezone());

        // Chrome still reports old names like this one.
        $this->patchJson(route('profile.preferences'), ['timezone' => 'Asia/Calcutta'])->assertNoContent();
        $this->assertSame('Asia/Calcutta', $this->editor->fresh()->localTimezone());

        // The profile page posts through Inertia and expects a redirect back.
        $this->from(route('profile.edit'))->patch(route('profile.preferences'), ['task_digest' => true])->assertRedirect(route('profile.edit'));
        $this->assertTrue($this->editor->fresh()->task_digest);
    }
}
