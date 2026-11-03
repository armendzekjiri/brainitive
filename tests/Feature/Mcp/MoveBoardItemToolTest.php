<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\BrainitiveServer;
use App\Mcp\Tools\MoveBoardItemTool;
use App\Models\Item;
use App\Notifications\ItemStatusChangedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/** MCP move_board_item goes through the board's drag & drop (WorkCompletion::moveIssue). */
class MoveBoardItemToolTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
    }

    private function move(Item $item, int $columnId, array $extra = [], $user = null)
    {
        return BrainitiveServer::actingAs($user ?? $this->editor)->tool(MoveBoardItemTool::class, [
            'workspace' => $this->workspace->slug, 'project' => $this->project->slug, 'board' => $this->board->slug,
            'item_id' => $item->id, 'column_id' => $columnId,
        ] + $extra);
    }

    public function test_a_move_notifies_the_watchers_like_the_board(): void
    {
        Notification::fake();
        $watcher = $this->projectMember('viewer');
        $item = $this->item(['column_id' => $this->column('to-do')]);
        $item->addWatcher($watcher);
        $item->addWatcher($this->editor);

        $this->move($item, $this->column('in-progress'))
            ->assertStructuredContent(fn (AssertableJson $j) => $j->where('moved', true)->where('column', 'In Progress')->etc());

        $this->assertSame($this->column('in-progress'), $item->fresh()->column_id);
        Notification::assertSentTo($watcher, ItemStatusChangedNotification::class);
        Notification::assertNotSentTo($this->editor, ItemStatusChangedNotification::class); // not the mover

        // same column again: no new status, no notification
        Notification::fake();
        $this->move($item, $this->column('in-progress'), ['position' => 2])->assertOk();
        Notification::assertNothingSent();
    }

    public function test_with_subtasks_completes_the_open_subtasks_into_a_done_column(): void
    {
        $parent = $this->item(['column_id' => $this->column('to-do')]);
        $child = $this->item(['column_id' => $this->column('to-do'), 'parent_id' => $parent->id]);
        $grandchild = $this->item(['column_id' => $this->column('in-progress'), 'parent_id' => $child->id]);
        $other = $this->item(['column_id' => $this->column('to-do')]);
        $otherChild = $this->item(['column_id' => $this->column('to-do'), 'parent_id' => $other->id]);

        $this->move($parent, $this->column('done'), ['with_subtasks' => true])->assertOk();
        $this->assertNotNull($parent->fresh()->completed_at);
        $this->assertSame($this->column('done'), $child->fresh()->column_id);
        $this->assertSame($this->column('done'), $grandchild->fresh()->column_id);

        // without it (the default) the subtasks stay where they are, as on the board
        $this->move($other, $this->column('done'))->assertOk();
        $this->assertSame($this->column('to-do'), $otherChild->fresh()->column_id);
    }

    public function test_viewers_cant_move(): void
    {
        $item = $this->item(['column_id' => $this->column('to-do')]);

        $this->move($item, $this->column('done'), [], $this->viewer)->assertHasErrors();
        $this->assertSame($this->column('to-do'), $item->fresh()->column_id);
    }
}
