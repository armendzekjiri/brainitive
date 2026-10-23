<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\BrainitiveServer;
use App\Mcp\Tools\AddItemCommentTool;
use App\Mcp\Tools\ListItemCommentsTool;
use App\Models\ActivityLog;
use App\Models\Board;
use App\Models\Comment;
use App\Notifications\MentionNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

class ItemCommentToolsTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
    }

    private function args(int $itemId, array $extra = []): array
    {
        return ['workspace' => $this->workspace->slug, 'project' => $this->project->slug, 'board' => $this->board->slug, 'item_id' => $itemId] + $extra;
    }

    public function test_add_a_comment_once_with_history_and_mentions(): void
    {
        Notification::fake();
        $item = $this->item();
        $markdown = "Implemented in **PR #12**. @{$this->viewer->email} please check. @nobody@example.com stays text.";
        $args = $this->args($item->id, ['markdown' => $markdown, 'request_id' => 'req-1']);

        BrainitiveServer::actingAs($this->editor)->tool(AddItemCommentTool::class, $args)
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $j) => $j->where('created', true)->has('comment_id')->has('location')->etc());

        // Retry with the same request: nothing new.
        BrainitiveServer::actingAs($this->editor)->tool(AddItemCommentTool::class, $args)
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $j) => $j->where('created', false)->etc());

        $this->assertSame(1, Comment::count());
        $this->assertStringContainsString('<strong>PR #12</strong>', Comment::first()->body);
        $this->assertStringContainsString('data-mention-user-id="'.$this->viewer->id.'"', Comment::first()->body);
        $this->assertStringContainsString('@nobody@example.com', Comment::first()->body);
        $this->assertSame(1, ActivityLog::where('action', 'commented')->count());
        Notification::assertSentToTimes($this->viewer, MentionNotification::class, 1);

        // Same request_id, different text: refused.
        BrainitiveServer::actingAs($this->editor)->tool(AddItemCommentTool::class, $this->args($item->id, ['markdown' => 'other', 'request_id' => 'req-1']))
            ->assertHasErrors(['already used']);
        $this->assertSame(1, Comment::count());
    }

    public function test_permissions_match_the_ui(): void
    {
        $item = $this->item();
        $item->comments()->create(['user_id' => $this->editor->id, 'body' => '<p>Visible to viewers</p>']);

        // Viewers read but can't comment (the board UI needs edit rights).
        BrainitiveServer::actingAs($this->viewer)->tool(ListItemCommentsTool::class, $this->args($item->id))->assertOk()->assertSee('Visible to viewers');
        BrainitiveServer::actingAs($this->viewer)->tool(AddItemCommentTool::class, $this->args($item->id, ['markdown' => 'x', 'request_id' => 'r']))->assertHasErrors();
        BrainitiveServer::actingAs($this->outsider)->tool(ListItemCommentsTool::class, $this->args($item->id))->assertHasErrors();

        // An item from another board can't be reached through this board.
        $otherBoard = Board::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id]);
        $foreign = $this->item(['board_id' => $otherBoard->id]);
        BrainitiveServer::actingAs($this->editor)->tool(ListItemCommentsTool::class, $this->args($foreign->id))->assertHasErrors(['No item with that id']);

        $this->assertSame(1, Comment::count());
    }

    public function test_pagination_is_chronological_without_gaps_or_duplicates(): void
    {
        $item = $this->item();
        $ids = [];
        foreach (range(1, 5) as $n) {
            $ids[] = $item->comments()->create(['user_id' => $this->editor->id, 'body' => "<p>Comment {$n}</p>"])->id;
        }

        $seen = [];
        $cursor = null;
        do {
            $response = BrainitiveServer::actingAs($this->editor)->tool(ListItemCommentsTool::class, $this->args($item->id, array_filter(['limit' => 2, 'cursor' => $cursor])));
            $data = $response->assertOk();
            $content = (fn () => $this->response->toArray()['result']['structuredContent'])->call($data);
            foreach ($content['comments'] as $c) {
                $seen[] = trim($c['markdown']);
            }
            $cursor = $content['next_cursor'] !== '' ? (int) $content['next_cursor'] : null;
            if ($cursor === $ids[3]) { // ids, not positions: Postgres sequences don't restart per test
                // A comment added while paginating appears at the end, not in the middle.
                $item->comments()->create(['user_id' => $this->editor->id, 'body' => '<p>Comment 6</p>']);
            }
        } while ($cursor);

        $this->assertSame(['Comment 1', 'Comment 2', 'Comment 3', 'Comment 4', 'Comment 5', 'Comment 6'], $seen);
    }
}
