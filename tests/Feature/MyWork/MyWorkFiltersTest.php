<?php

namespace Tests\Feature\MyWork;

use App\Models\Label;
use App\Models\TaskList;
use App\Models\TaskListItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * My Work's filter bar (search, project, source, priority, labels, status, sort) runs in the browser
 * on every row of the scope + due chip: the payload carries what it filters and sorts on.
 */
class MyWorkFiltersTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
    }

    public function test_rows_carry_labels_and_updated_at_for_the_filter_bar(): void
    {
        $item = $this->item(['title' => 'Labelled', 'assigned_to' => $this->editor->id]);
        $label = Label::create(['project_id' => $this->project->id, 'name' => 'Frontend', 'slug' => 'frontend', 'color' => '#ff0000']);
        $item->labels()->attach($label->id);
        $list = TaskList::factory()->create(['project_id' => $this->project->id]);
        TaskListItem::factory()->create(['task_list_id' => $list->id, 'title' => 'List task', 'assignee_id' => $this->editor->id]);

        $this->actingAs($this->editor)->get(route('my-work.index'))->assertInertia(fn (AssertableInertia $p) => $p
            ->component('MyWork/Index')
            ->has('projects', 1)
            ->where('projects.0.items', function ($items) use ($label) {
                $rows = collect($items)->keyBy('title');

                return $rows['Labelled']['labels'] === [['id' => $label->id, 'name' => 'Frontend', 'color' => '#ff0000']]
                    && is_string($rows['Labelled']['updated_at'])
                    && $rows['List task']['labels'] === []
                    && is_string($rows['List task']['updated_at']);
            }));
    }

    public function test_every_row_of_the_scope_is_returned_including_done_ones(): void
    {
        // the client filters (status Open / Done / All) need the done rows too, and nothing is paginated
        foreach (range(1, 60) as $n) {
            $this->item(['title' => "Task {$n}", 'assigned_to' => $this->editor->id]);
        }
        $this->item(['title' => 'Finished', 'assigned_to' => $this->editor->id, 'column_id' => $this->column('done'), 'completed_at' => now()]);

        $this->actingAs($this->editor)->get(route('my-work.index', ['q' => 'Task 1', 'status' => 'done', 'sort' => 'priority']))
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('projects.0.items', fn ($items) => count($items) === 61 && collect($items)->contains('title', 'Finished'))
                ->where('counts.all', 61)
                ->where('scope', 'assigned')
                ->where('filter', 'all'));
    }
}
