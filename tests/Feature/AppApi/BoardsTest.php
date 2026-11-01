<?php

namespace Tests\Feature\AppApi;

use App\Models\Board;
use App\Models\Item;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\ItemStatusChangedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\AppApi\Concerns\CapturesLiveEvents;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * App API v1 boards + columns, read and move cards (BRAI-403): the web's rules (viewers read, editors /
 * admins move), what the board page shows (Scrum: running sprint; Kanban: done retention), boards /
 * projects the user can't open are 404, moves notify watchers and reach open boards live.
 */
class BoardsTest extends TestCase
{
    use ActsAsAppClient;
    use CapturesLiveEvents;
    use ForgetsGuardsBetweenRequests;
    use InteractsWithBoards;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        $this->board->update(['name' => 'Main Board', 'position' => 1]);
    }

    private function headers(?User $user = null): array
    {
        return $this->appHeaders($this->appToken($user ?? $this->editor));
    }

    private function admin(): User
    {
        return User::findOrFail($this->workspace->owner_id);
    }

    // ── boards ──────────────────────────────────────────────────────────────────────────────

    public function test_lists_the_boards_of_a_project_in_order(): void
    {
        $second = Board::factory()->create(['project_id' => $this->project->id, 'name' => 'Bugs', 'type' => 'scrum', 'position' => 2]);
        Board::factory()->create(); // another project

        $response = $this->getJson("/api/v1/projects/{$this->project->id}/boards", $this->headers($this->viewer))
            ->assertOk()
            ->assertJsonPath('next_cursor', null)
            ->assertJsonCount(2, 'data');

        $this->assertSame([$this->board->id, $second->id], array_column($response->json('data'), 'id'));
        $this->assertSame(['id', 'uuid', 'slug', 'name', 'description', 'type', 'position', 'project', 'workspace', 'url', 'created_at', 'updated_at'], array_keys($response->json('data.0')));
        $response->assertJsonPath('data.1.type', 'scrum')
            ->assertJsonPath('data.0.project.key', 'BRAI')
            ->assertJsonPath('data.0.url', route('boards.show', [$this->workspace->slug, $this->project->slug, $this->board->slug]));

        $page = $this->getJson("/api/v1/projects/{$this->project->id}/boards?limit=1", $this->headers())->assertOk();
        $this->assertSame([$this->board->id], array_column($page->json('data'), 'id'));
        $next = $this->getJson("/api/v1/projects/{$this->project->id}/boards?limit=1&cursor=".urlencode($page->json('next_cursor')), $this->headers())->assertOk();
        $this->assertSame([$second->id], array_column($next->json('data'), 'id'));

        // sync: only what changed since
        $this->travel(5)->minutes();
        $second->touch();
        $since = now()->subMinute()->toIso8601ZuluString();
        $changed = $this->getJson("/api/v1/projects/{$this->project->id}/boards?updated_since=".urlencode($since), $this->headers())->assertOk();
        $this->assertSame([$second->id], array_column($changed->json('data'), 'id'));
    }

    public function test_one_board_with_columns_counts_swimlanes_and_rights(): void
    {
        $this->board->update(['settings' => ['done_retention_days' => 14, 'swimlanes' => [
            'mode' => 'custom', 'rest_name' => 'Rest',
            'lanes' => [['id' => 'lane-1', 'name' => 'Urgent', 'color' => '#ef4444', 'rules' => ['priority_ids' => [3], 'overdue' => true]]],
        ]]]);
        $this->item(['column_id' => $this->column('to-do')]);
        $this->item(['column_id' => $this->column('to-do')]);
        $this->item(['column_id' => $this->column('done')]);
        $this->item(['column_id' => $this->column('done'), 'completed_at' => now()->subDays(30)]); // past the retention
        $this->board->columns()->where('slug', 'in-progress')->update(['wip_limit' => 3]);

        $response = $this->getJson("/api/v1/boards/{$this->board->id}", $this->headers($this->viewer))->assertOk();

        $this->assertSame(['To Do', 'In Progress', 'Done'], array_column($response->json('data.columns'), 'name'));
        $this->assertSame([2, 0, 1], array_column($response->json('data.columns'), 'cards_count'));
        $response->assertJsonPath('data.columns.1.wip_limit', 3)
            ->assertJsonPath('data.columns.2.is_done', true)
            ->assertJsonPath('data.done_retention_days', 14)
            ->assertJsonPath('data.swimlanes.mode', 'custom')
            ->assertJsonPath('data.swimlanes.rest_name', 'Rest')
            ->assertJsonPath('data.swimlanes.lanes.0.rules.priority_ids', [3])
            ->assertJsonPath('data.swimlanes.lanes.0.rules.label_ids', [])
            ->assertJsonPath('data.swimlanes.lanes.0.rules.overdue', true)
            ->assertJsonPath('data.active_sprint', null)
            ->assertJsonPath('data.can', ['update' => false, 'create_items' => false, 'edit_items' => false, 'delete_any_item' => false, 'manage_sprints' => false]);

        $this->getJson("/api/v1/boards/{$this->board->id}", $this->headers())->assertOk()
            ->assertJsonPath('data.can.create_items', true)
            ->assertJsonPath('data.can.edit_items', true)
            ->assertJsonPath('data.can.delete_any_item', false);
        $this->getJson("/api/v1/boards/{$this->board->id}", $this->headers($this->admin()))->assertOk()
            ->assertJsonPath('data.can.delete_any_item', true)
            ->assertJsonPath('data.swimlanes.lanes.0.name', 'Urgent');
    }

    public function test_boards_the_user_cant_open_are_not_found(): void
    {
        $foreignProject = Project::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);
        $foreign = Board::factory()->create(['project_id' => $foreignProject->id]);
        $foreignItem = Item::factory()->create(['board_id' => $foreign->id]);

        $this->getJson("/api/v1/projects/{$foreignProject->id}/boards", $this->headers())->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->getJson("/api/v1/boards/{$foreign->id}", $this->headers())->assertNotFound();
        $this->getJson("/api/v1/boards/{$foreign->id}/items", $this->headers())->assertNotFound();
        $this->postJson("/api/v1/items/{$foreignItem->id}/move", ['column_id' => 1], $this->headers())->assertNotFound();

        // a workspace member who isn't in the project
        $member = User::factory()->create();
        $this->workspace->addUser($member, 'member');
        $this->getJson("/api/v1/boards/{$this->board->id}", $this->headers($member))->assertNotFound();
        $this->getJson("/api/v1/projects/{$this->project->id}/boards", $this->headers($member))->assertNotFound();

        // malformed / unknown ids on writes: 404, not 405 / 500
        $this->postJson('/api/v1/items/abc/move', ['column_id' => 1], $this->headers())->assertNotFound();
        $this->postJson('/api/v1/items/99999999999999999999/move', ['column_id' => 1], $this->headers())->assertNotFound();
        $this->getJson('/api/v1/boards/0', $this->headers())->assertNotFound();
    }

    // ── cards ───────────────────────────────────────────────────────────────────────────────

    public function test_lists_the_cards_column_by_column(): void
    {
        $label = $this->project->labels()->create(['name' => 'ui', 'slug' => 'ui', 'color' => '#ff0000']);
        $first = $this->item(['column_id' => $this->column('to-do'), 'position' => 2, 'title' => 'Second in To Do', 'assigned_to' => $this->editor->id, 'due_date' => '2026-11-20', 'due_time' => '09:30:00', 'story_points' => 3]);
        $first->labels()->attach($label->id);
        $top = $this->item(['column_id' => $this->column('to-do'), 'position' => 1, 'title' => 'First in To Do']);
        $sub = $this->item(['column_id' => $this->column('in-progress'), 'parent_id' => $first->id, 'title' => 'A subtask']);
        $first->comments()->create(['user_id' => $this->editor->id, 'body' => 'Hi']);

        $response = $this->getJson("/api/v1/boards/{$this->board->id}/items", $this->headers($this->viewer))->assertOk()->assertJsonPath('next_cursor', null);
        $ids = array_column($response->json('data'), 'id');
        $this->assertSame([$top->id, $first->id], array_slice($ids, 0, 2));
        $this->assertContains($sub->id, $ids); // subtasks are cards too, as on the board

        $card = collect($response->json('data'))->firstWhere('id', $first->id);
        $this->assertSame([
            'id', 'work_id', 'key', 'title', 'board_id', 'column_id', 'status', 'done', 'completed_at', 'type', 'priority', 'assignee', 'labels',
            'start_date', 'start_time', 'due_date', 'due_time', 'story_points', 'parent_id', 'sprint_id', 'position', 'counts', 'url', 'created_at', 'updated_at',
        ], array_keys($card));
        $this->assertSame("issue:{$first->id}", $card['work_id']);
        $this->assertSame('To Do', $card['status']);
        $this->assertSame([['id' => $label->id, 'name' => 'ui', 'color' => '#ff0000']], $card['labels']);
        $this->assertSame(['subtasks' => 1, 'comments' => 1, 'attachments' => 0], $card['counts']);
        $this->assertSame('2026-11-20', $card['due_date']);
        $this->assertSame('09:30', $card['due_time']);
        $this->assertSame($this->editor->id, $card['assignee']['id']);

        // one column; a column of another board → 422
        $inProgress = $this->getJson("/api/v1/boards/{$this->board->id}/items?column={$this->column('in-progress')}", $this->headers())->assertOk();
        $this->assertSame([$sub->id], array_column($inProgress->json('data'), 'id'));
        $other = Board::factory()->create(['project_id' => $this->project->id]);
        $this->getJson("/api/v1/boards/{$this->board->id}/items?column=".$other->columns()->value('id'), $this->headers())
            ->assertUnprocessable()->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors('column');

        // cursor pages
        $page = $this->getJson("/api/v1/boards/{$this->board->id}/items?limit=2", $this->headers())->assertOk();
        $this->assertCount(2, $page->json('data'));
        $rest = $this->getJson("/api/v1/boards/{$this->board->id}/items?limit=2&cursor=".urlencode($page->json('next_cursor')), $this->headers())->assertOk();
        $this->assertSame([$sub->id], array_column($rest->json('data'), 'id'));
    }

    public function test_kanban_leaves_out_old_done_cards_and_scrum_shows_the_running_sprint(): void
    {
        $this->board->update(['settings' => ['done_retention_days' => 7]]);
        $recent = $this->item(['column_id' => $this->column('done'), 'completed_at' => now()->subDays(2)]);
        $old = $this->item(['column_id' => $this->column('done'), 'completed_at' => now()->subDays(20)]);

        $ids = array_column($this->getJson("/api/v1/boards/{$this->board->id}/items", $this->headers())->json('data'), 'id');
        $this->assertSame([$recent->id], $ids);
        $all = array_column($this->getJson("/api/v1/boards/{$this->board->id}/items?include_old_done=1", $this->headers())->json('data'), 'id');
        $this->assertEqualsCanonicalizing([$recent->id, $old->id], $all);

        // Scrum: only the running sprint; no sprint running → no cards
        $scrum = Board::factory()->create(['project_id' => $this->project->id, 'type' => 'scrum']);
        $todo = $scrum->columns()->orderBy('position')->value('id');
        $backlog = Item::factory()->create(['board_id' => $scrum->id, 'column_id' => $todo]);
        $this->getJson("/api/v1/boards/{$scrum->id}/items", $this->headers())->assertOk()->assertJsonCount(0, 'data');

        $sprint = Sprint::factory()->create(['board_id' => $scrum->id, 'name' => 'Sprint 1', 'status' => Sprint::STATUS_ACTIVE, 'start_date' => '2026-11-01', 'end_date' => '2026-11-14']);
        $inSprint = Item::factory()->create(['board_id' => $scrum->id, 'column_id' => $todo, 'sprint_id' => $sprint->id]);
        $ids = array_column($this->getJson("/api/v1/boards/{$scrum->id}/items", $this->headers())->json('data'), 'id');
        $this->assertSame([$inSprint->id], $ids);
        $this->assertNotContains($backlog->id, $ids);
        $this->getJson("/api/v1/boards/{$scrum->id}", $this->headers())->assertOk()
            ->assertJsonPath('data.active_sprint.name', 'Sprint 1')
            ->assertJsonPath('data.active_sprint.end_date', '2026-11-14')
            ->assertJsonPath('data.columns.0.cards_count', 1)
            ->assertJsonPath('data.done_retention_days', 0)
            ->assertJsonPath('data.can.manage_sprints', true);
    }

    // ── move ────────────────────────────────────────────────────────────────────────────────

    public function test_editors_move_cards_like_the_board_watchers_hear_and_open_boards_update(): void
    {
        Notification::fake();
        $this->fakeLive();
        $watcher = $this->projectMember('viewer');
        $item = $this->item(['column_id' => $this->column('to-do')]);
        $item->addWatcher($watcher);
        $open = $this->item(['column_id' => $this->column('to-do'), 'parent_id' => $item->id]);
        $this->settleLive();

        $this->postJson("/api/v1/items/{$item->id}/move", ['column_id' => $this->column('done'), 'position' => 0, 'with_subtasks' => true], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.id', $item->id)
            ->assertJsonPath('data.column_id', $this->column('done'))
            ->assertJsonPath('data.status', 'Done')
            ->assertJsonPath('data.done', true);

        $this->assertNotNull($item->fresh()->completed_at);
        $this->assertSame($this->column('done'), $open->fresh()->column_id); // the open subtask is completed too
        Notification::assertSentTo($watcher, ItemStatusChangedNotification::class);
        $this->assertContains('items.changed', $this->livePublished('board.'.$this->board->id)->pluck('event')->all());

        // back to an open column reopens it
        $this->postJson("/api/v1/items/{$item->id}/move", ['column_id' => $this->column('in-progress')], $this->headers())
            ->assertOk()->assertJsonPath('data.done', false)->assertJsonPath('data.completed_at', null);
    }

    public function test_a_single_move_publishes_item_moved(): void
    {
        $this->fakeLive();
        $item = $this->item(['column_id' => $this->column('to-do')]);
        $this->settleLive();

        $this->postJson("/api/v1/items/{$item->id}/move", ['column_id' => $this->column('in-progress'), 'position' => 3], $this->headers())->assertOk()
            ->assertJsonPath('data.position', 3);
        $this->assertSame(['item.moved'], $this->livePublished('board.'.$this->board->id)->pluck('event')->all());
    }

    public function test_viewers_cant_move_and_columns_must_be_on_the_board(): void
    {
        $item = $this->item(['column_id' => $this->column('to-do')]);
        $other = Board::factory()->create(['project_id' => $this->project->id]);

        $this->postJson("/api/v1/items/{$item->id}/move", ['column_id' => $this->column('done')], $this->headers($this->viewer))
            ->assertForbidden()->assertJsonPath('code', 'forbidden');
        $this->postJson("/api/v1/items/{$item->id}/move", ['column_id' => $other->columns()->value('id')], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('column_id');
        $this->postJson("/api/v1/items/{$item->id}/move", [], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('column_id');
        $this->assertSame($this->column('to-do'), $item->fresh()->column_id);

        // workspace admins who aren't in the project move too
        $this->postJson("/api/v1/items/{$item->id}/move", ['column_id' => $this->column('in-progress')], $this->headers($this->admin()))->assertOk();
    }

    public function test_needs_an_app_token_and_a_verified_active_user(): void
    {
        $this->getJson("/api/v1/boards/{$this->board->id}")->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
        $this->getJson("/api/v1/boards/{$this->board->id}", $this->appHeaders($this->passportToken($this->editor)))->assertUnauthorized();
        $this->postJson('/api/v1/items/1/move', ['column_id' => 1], $this->appHeaders($this->passportToken($this->editor)))->assertUnauthorized();

        $this->editor->forceFill(['suspended_at' => now()])->save();
        $this->getJson("/api/v1/projects/{$this->project->id}/boards", $this->headers())->assertForbidden();
    }
}
