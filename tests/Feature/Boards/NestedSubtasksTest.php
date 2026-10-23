<?php

namespace Tests\Feature\Boards;

use App\Models\Board;
use App\Models\Item;
use App\Models\Sprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NestedSubtasksTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
    }

    /** @return array{0: Item, 1: Item, 2: Item} issue → subtask → sub-subtask */
    private function tree(): array
    {
        $issue = $this->item(['title' => 'Checkout']);
        $sub = $this->item(['title' => 'Payment step', 'parent_id' => $issue->id]);
        $subSub = $this->item(['title' => 'Card form', 'parent_id' => $sub->id]);

        return [$issue, $sub, $subSub];
    }

    private function storeRoute(): string
    {
        return route('boards.items.store', [$this->workspace->slug, $this->project->slug, $this->board->slug]);
    }

    public function test_subtasks_nest_up_to_three_levels(): void
    {
        [$issue, $sub, $subSub] = $this->tree();
        $column = $this->column('to-do');

        $this->actingAs($this->editor)->post($this->storeRoute(), ['title' => 'Validate CVC', 'column_id' => $column, 'parent_id' => $sub->id])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, Item::where('title', 'Validate CVC')->firstOrFail()->depth());

        // A 4th level is refused.
        $this->actingAs($this->editor)->post($this->storeRoute(), ['title' => 'Too deep', 'column_id' => $column, 'parent_id' => $subSub->id])
            ->assertSessionHasErrors('parent_id');
        $this->assertDatabaseMissing('items', ['title' => 'Too deep']);
    }

    public function test_reparenting_respects_depth_and_cycles(): void
    {
        [$issue, $sub, $subSub] = $this->tree();
        $other = $this->item(['title' => 'Other']);
        $otherChild = $this->item(['title' => 'Other child', 'parent_id' => $other->id]);

        // Moving a 2-level subtree under a subtask would make 4 levels.
        $this->actingAs($this->editor)->patch($this->itemRoute('boards.items.update', $sub), ['parent_id' => $otherChild->id])
            ->assertSessionHasErrors('parent_id');
        // Under its own descendant: a loop.
        $this->actingAs($this->editor)->patch($this->itemRoute('boards.items.update', $issue), ['parent_id' => $subSub->id])
            ->assertSessionHasErrors('parent_id');
        // Under another top-level issue: fine.
        $this->actingAs($this->editor)->patch($this->itemRoute('boards.items.update', $sub), ['parent_id' => $other->id])
            ->assertSessionHasNoErrors();
        $this->assertSame($other->id, $sub->fresh()->parent_id);
    }

    public function test_deleting_an_issue_removes_its_whole_subtree(): void
    {
        [$issue, $sub, $subSub] = $this->tree();

        $this->actingAs($this->editor)->delete($this->itemRoute('boards.items.destroy', $issue))->assertRedirect();

        $this->assertSoftDeleted($issue);
        $this->assertSoftDeleted($sub);
        $this->assertSoftDeleted($subSub);
    }

    public function test_completing_a_parent_can_complete_all_open_subtasks(): void
    {
        [$issue, $sub, $subSub] = $this->tree();
        $done = $this->column('done');

        $this->actingAs($this->editor)->patch($this->itemRoute('boards.items.move', $issue), ['column_id' => $done, 'position' => 0])->assertRedirect();
        $this->assertNotSame($done, $sub->fresh()->column_id, 'children untouched without the option');

        $this->actingAs($this->editor)->patch($this->itemRoute('boards.items.move', $issue), ['column_id' => $this->column('to-do'), 'position' => 0]);
        $this->actingAs($this->editor)->patch($this->itemRoute('boards.items.move', $issue), ['column_id' => $done, 'position' => 0, 'with_subtasks' => true]);

        $this->assertSame($done, $sub->fresh()->column_id);
        $this->assertSame($done, $subSub->fresh()->column_id);
        $this->assertNotNull($subSub->fresh()->completed_at);
    }

    public function test_sprint_and_board_moves_take_the_whole_subtree(): void
    {
        [$issue, $sub, $subSub] = $this->tree();
        $this->board->update(['type' => 'scrum']);
        $sprint = Sprint::factory()->create(['board_id' => $this->board->id]);

        $issue->moveToSprint($sprint);
        $this->assertSame($sprint->id, $subSub->fresh()->sprint_id);

        $target = Board::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id]);
        $this->actingAs($this->editor)->patch($this->itemRoute('boards.items.move-to-board', $sub), ['target_board_id' => $target->id])->assertRedirect();

        $this->assertSame($target->id, $sub->fresh()->board_id);
        $this->assertNull($sub->fresh()->parent_id);
        $this->assertSame($target->id, $subSub->fresh()->board_id);
        $this->assertSame($sub->id, $subSub->fresh()->parent_id);
        $this->assertNull($subSub->fresh()->sprint_id);
    }
}
