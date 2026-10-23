<?php

namespace Tests\Feature\Boards;

use App\Models\Item;
use App\Models\TaskList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DuplicateItemTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
    }

    public function test_duplicate_an_issue_without_and_with_subtasks(): void
    {
        $label = $this->project->labels()->create(['name' => 'Bug', 'color' => '#f00']);
        $issue = $this->item(['title' => 'Checkout fails', 'assigned_to' => $this->editor->id, 'time_spent' => 90, 'original_estimate' => 120, 'column_id' => $this->column('in-progress')]);
        $issue->labels()->attach($label->id);
        $issue->comments()->create(['user_id' => $this->editor->id, 'body' => '<p>secret comment</p>']);
        $this->item(['title' => 'Fix button', 'parent_id' => $issue->id]);
        $this->item(['title' => 'Write test', 'parent_id' => $issue->id]);

        $this->actingAs($this->editor)->post($this->itemRoute('boards.items.duplicate', $issue))->assertRedirect()->assertSessionHas('success');

        $copy = Item::where('title', 'Copy of Checkout fails')->firstOrFail();
        $this->assertNotSame($issue->key, $copy->key);
        $this->assertSame($this->column('in-progress'), $copy->column_id);
        $this->assertSame($this->editor->id, $copy->assigned_to);
        $this->assertSame(120, (int) $copy->original_estimate);
        $this->assertSame(0, (int) $copy->time_spent);
        $this->assertSame([$label->id], $copy->labels()->pluck('labels.id')->all());
        $this->assertSame(0, $copy->comments()->count());
        $this->assertSame(0, $copy->children()->count());
        $this->assertSame('Checkout fails', $issue->fresh()->title);

        $this->actingAs($this->editor)->post($this->itemRoute('boards.items.duplicate', $issue), ['with_subitems' => true])->assertRedirect();
        $withChildren = Item::where('title', 'Copy of Checkout fails')->latest('id')->firstOrFail();
        $this->assertEqualsCanonicalizing(['Fix button', 'Write test'], $withChildren->children()->pluck('title')->all());
        $this->assertSame(2, $issue->children()->count());
    }

    public function test_viewers_cannot_duplicate(): void
    {
        $issue = $this->item();

        $this->actingAs($this->viewer)->post($this->itemRoute('boards.items.duplicate', $issue))->assertForbidden();
        $this->assertSame(1, Item::count());
    }

    public function test_duplicate_a_task_below_the_original(): void
    {
        $list = TaskList::create(['project_id' => $this->project->id, 'name' => 'Inbox', 'slug' => 'inbox', 'created_by' => $this->editor->id]);
        $first = $list->items()->create(['title' => 'Call supplier', 'status' => 'done', 'position' => 1, 'created_by' => $this->editor->id, 'due_date' => '2026-11-02']);
        $second = $list->items()->create(['title' => 'Pay invoice', 'status' => 'todo', 'position' => 2, 'created_by' => $this->editor->id]);

        $this->actingAs($this->editor)
            ->post(route('task-lists.items.duplicate', [$this->workspace->slug, $this->project->slug, $list->slug, $first->id]))
            ->assertRedirect();

        $this->assertSame(['Call supplier', 'Copy of Call supplier', 'Pay invoice'], $list->items()->orderBy('position')->pluck('title')->all());
        $copy = $list->items()->where('title', 'Copy of Call supplier')->firstOrFail();
        $this->assertSame('todo', $copy->status);
        $this->assertSame('2026-11-02', $copy->due_date->toDateString());

        $this->actingAs($this->viewer)
            ->post(route('task-lists.items.duplicate', [$this->workspace->slug, $this->project->slug, $list->slug, $second->id]))
            ->assertForbidden();
    }
}
