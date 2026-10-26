<?php

namespace Tests\Feature\Live;

use App\Mcp\Servers\BrainitiveServer;
use App\Mcp\Tools\MoveBoardItemTool;
use App\Models\Board;
use App\Models\Comment;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\ItemType;
use App\Models\Label;
use App\Models\Sprint;
use App\Support\Imports\ImportScope;
use App\Support\Live\BoardLive;
use App\Support\Live\Live;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

use function Illuminate\Support\defer;

/**
 * Live boards (BRAI-152): every write path publishes to `board.{id}` — coalesced per request, ids /
 * positions / field names only — and `boards.cards` returns the cards a live board refetches.
 * Plan: docs/ai/tasks/feat-live-boards.md.
 */
class LiveBoardsTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    private int $seen = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        Notification::fake();
        BoardLive::reset();
        Cache::forget('live:publish:paused');
        Http::fake(['127.0.0.1:6299/*' => Http::response(['delivered' => 1], 202)]);
        config(['collab.url' => 'wss://brainitive.test/collab', 'collab.secret' => 'test-collab-secret', 'collab.e2e_opt_in' => false, 'live.enabled' => true, 'live.url' => null, 'live.publish_url' => 'http://127.0.0.1:6299']);
    }

    /** Send what the test's direct (non-HTTP) calls collected and forget it. */
    private function settle(): void
    {
        defer()->invoke();
        $this->seen = count(Http::recorded());
    }

    /**
     * Messages published since the last settle(), deferred callbacks run first.
     *
     * @return Collection<int, array{channel: string, event: string, payload: array<string, mixed>}>
     */
    private function published(?string $channel = null): Collection
    {
        defer()->invoke();
        $messages = collect(Http::recorded())->slice($this->seen)
            ->flatMap(fn (array $pair) => json_decode($pair[0]->body(), true)['messages'] ?? [])
            ->values();
        foreach ($messages as $message) {
            Live::validate($message['channel'], $message['event'], $message['payload']); // the payload rule
        }

        return $channel === null ? $messages : $messages->where('channel', $channel)->values();
    }

    private function boardChannel(?Board $board = null): string
    {
        return 'board.'.($board ?? $this->board)->id;
    }

    private function boardRoute(string $name, ?Board $board = null, array $extra = []): string
    {
        return route($name, [$this->workspace->slug, $this->project->slug, ($board ?? $this->board)->slug, ...$extra]);
    }

    public function test_a_card_moved_on_the_board_publishes_column_position_and_the_sender_tab(): void
    {
        $item = $this->item(['column_id' => $this->column('to-do'), 'title' => 'Secret launch plan']);
        $this->settle();

        $this->actingAs($this->editor)
            ->withHeader('X-Live-Client', 'tab-1a2b3c4d')
            ->patch($this->itemRoute('boards.items.move', $item), ['column_id' => $this->column('in-progress'), 'position' => 0])
            ->assertRedirect();

        $board = $this->published($this->boardChannel());
        $this->assertCount(1, $board, 'one message for the move (column + completed_at changes coalesced)');
        $this->assertSame('item.moved', $board[0]['event']);
        $this->assertSame([
            'client_id' => 'tab-1a2b3c4d',
            'actor_id' => $this->editor->id,
            'id' => $item->id,
            'column_id' => $this->column('in-progress'),
            'from_column_id' => $this->column('to-do'),
            'position' => 0,
        ], collect($board[0]['payload'])->except('fields')->all());
        $this->assertContains('column_id', $board[0]['payload']['fields']);

        // the card's own channel hears it too; nothing anywhere carries the title
        $own = $this->published("item.{$item->id}");
        $this->assertSame(['item.updated'], $own->pluck('event')->all(), 'the issue view’s own event');
        $this->assertContains('column_id', $own[0]['payload']['fields']);
        Http::assertNotSent(fn (Request $request) => str_contains($request->body(), 'Secret launch plan'));
    }

    public function test_create_update_labels_fields_and_delete_publish_names_never_values(): void
    {
        $this->actingAs($this->editor)
            ->withHeader('X-Live-Client', 'not a valid id!')
            ->post($this->boardRoute('boards.items.store'), ['title' => 'Confidential', 'column_id' => $this->column('to-do')])
            ->assertSessionHasNoErrors();
        $item = Item::where('title', 'Confidential')->firstOrFail();
        $created = $this->published($this->boardChannel());
        $this->assertSame([['event' => 'item.created', 'payload' => ['id' => $item->id, 'column_id' => $this->column('to-do')]]],
            $created->map(fn ($m) => ['event' => $m['event'], 'payload' => $m['payload']])->all(), 'an invalid client id is dropped');
        $this->settle();

        $this->actingAs($this->editor)->patch($this->itemRoute('boards.items.update', $item), ['title' => 'Still confidential', 'story_points' => 5])->assertSessionHasNoErrors();
        $updated = $this->published($this->boardChannel());
        $this->assertSame('item.updated', $updated[0]['event']);
        $this->assertEqualsCanonicalizing(['title', 'story_points'], $updated[0]['payload']['fields']);
        Http::assertNotSent(fn (Request $request) => str_contains($request->body(), 'confidential'));
        $this->settle();

        // a title-only edit: the item channel hears it once (Item's own item.updated), not twice
        $this->actingAs($this->editor)->patch($this->itemRoute('boards.items.update', $item), ['title' => 'Renamed again'])->assertSessionHasNoErrors();
        $this->assertSame([['item.updated', ['title']]], $this->published("item.{$item->id}")->map(fn ($m) => [$m['event'], $m['payload']['fields']])->all());
        $this->settle();

        $label = Label::create(['project_id' => $this->project->id, 'name' => 'urgent', 'slug' => 'urgent', 'color' => '#ef4444']);
        $this->actingAs($this->editor)->patch($this->itemRoute('boards.items.labels', $item), ['labels' => [$label->id]])->assertSessionHasNoErrors();
        $this->assertSame([['item.updated', ['labels']]], $this->published($this->boardChannel())->map(fn ($m) => [$m['event'], $m['payload']['fields']])->all());
        $this->settle();

        // comments only change the card's count; deleting takes the subtree along in one message
        Comment::create(['item_id' => $item->id, 'user_id' => $this->editor->id, 'body' => 'Private remark']);
        $this->assertSame(['comments'], $this->published($this->boardChannel())[0]['payload']['fields']);
        $this->settle();

        $child = $this->item(['parent_id' => $item->id, 'item_type_id' => ItemType::where('slug', 'subtask')->value('id')]);
        $this->settle();
        $this->actingAs($this->editor)->delete($this->itemRoute('boards.items.destroy', $item))->assertRedirect();
        $deleted = $this->published($this->boardChannel());
        $this->assertCount(1, $deleted);
        $this->assertSame('items.changed', $deleted[0]['event']);
        $this->assertSame([], $deleted[0]['payload']['ids']);
        $this->assertEqualsCanonicalizing([$item->id, $child->id], $deleted[0]['payload']['deleted_ids']);
    }

    public function test_bulk_edit_is_one_batched_event_with_the_ids(): void
    {
        $items = collect(range(1, 5))->map(fn () => $this->item(['column_id' => $this->column('to-do')]));
        $this->settle();

        $this->actingAs($this->editor)
            ->from($this->boardRoute('boards.show'))
            ->patch($this->boardRoute('boards.items.bulk.update'), ['item_ids' => $items->pluck('id')->all(), 'changes' => ['column_id' => $this->column('done')]])
            ->assertSessionHasNoErrors();

        $board = $this->published($this->boardChannel());
        $this->assertSame(['items.changed'], $board->pluck('event')->all());
        $this->assertEqualsCanonicalizing($items->pluck('id')->all(), $board[0]['payload']['ids']);
        $this->assertSame(5, $board[0]['payload']['count']);
        $this->assertContains('column_id', $board[0]['payload']['fields']);
        $this->assertCount(5, $this->published()->filter(fn ($m) => str_starts_with($m['channel'], 'item.')), 'a handful of cards still reach their own channels');
    }

    public function test_column_create_rename_reorder_and_delete(): void
    {
        $this->actingAs($this->editor)->post($this->boardRoute('boards.columns.store'), ['name' => 'Review'])->assertSessionHasNoErrors();
        $column = $this->board->columns()->where('name', 'Review')->firstOrFail();
        $this->assertSame([['column.created', ['id' => $column->id]]], $this->published($this->boardChannel())->map(fn ($m) => [$m['event'], $m['payload']])->all());
        $this->settle();

        $this->actingAs($this->editor)->put($this->boardRoute('boards.columns.update', extra: [$column->id]), ['name' => 'QA review'])->assertSessionHasNoErrors();
        $this->assertSame([['column.updated', ['id' => $column->id, 'fields' => ['name']]]], $this->published($this->boardChannel())->map(fn ($m) => [$m['event'], $m['payload']])->all());
        $this->settle();

        $ids = $this->board->columns()->orderByDesc('position')->pluck('id')->all();
        $this->actingAs($this->editor)->postJson($this->boardRoute('boards.columns.reorder'), [
            'columns' => collect($ids)->map(fn ($id, $position) => ['id' => $id, 'position' => $position])->all(),
        ])->assertOk();
        $reordered = $this->published($this->boardChannel());
        $this->assertSame(['columns.reordered'], $reordered->pluck('event')->all());
        $this->assertEqualsCanonicalizing($ids, $reordered[0]['payload']['ids']);
        $this->settle();

        $this->actingAs($this->editor)->delete($this->boardRoute('boards.columns.destroy', extra: [$column->id]))->assertSessionHasNoErrors();
        $this->assertSame([['column.deleted', ['id' => $column->id]]], $this->published($this->boardChannel())->map(fn ($m) => [$m['event'], $m['payload']])->all());
    }

    public function test_a_card_moved_to_another_board_leaves_one_and_arrives_on_the_other(): void
    {
        $target = Board::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id]);
        $item = $this->item();
        $child = $this->item(['parent_id' => $item->id]);
        $this->settle();

        $this->actingAs($this->editor)->patch($this->itemRoute('boards.items.move-to-board', $item), ['target_board_id' => $target->id])->assertSessionHasNoErrors();

        $left = $this->published($this->boardChannel());
        $this->assertSame(['items.changed'], $left->pluck('event')->all());
        $this->assertEqualsCanonicalizing([$item->id, $child->id], $left[0]['payload']['deleted_ids']);
        $arrived = $this->published($this->boardChannel($target));
        $this->assertSame(['items.changed'], $arrived->pluck('event')->all());
        $this->assertEqualsCanonicalizing([$item->id, $child->id], $arrived[0]['payload']['ids']);
    }

    public function test_sprints_and_backlog_ranking_reach_backlog_views(): void
    {
        $this->board->update(['type' => 'scrum']);
        [$a, $b] = [$this->item(), $this->item()];
        $this->settle();

        $sprint = Sprint::factory()->create(['board_id' => $this->board->id, 'status' => Sprint::STATUS_PLANNED]);
        $this->assertSame([['sprint.changed', ['id' => $sprint->id, 'action' => 'created', 'status' => 'planned']]],
            $this->published($this->boardChannel())->map(fn ($m) => [$m['event'], $m['payload']])->all());
        $this->settle();

        $this->actingAs($this->editor)->post($this->boardRoute('boards.backlog.rank'), ['sprint_id' => $sprint->id, 'item_ids' => [$b->id, $a->id]])->assertSessionHasNoErrors();
        $ranked = $this->published($this->boardChannel());
        $this->assertSame(['items.changed'], $ranked->pluck('event')->all());
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $ranked[0]['payload']['ids']);
        $this->assertEqualsCanonicalizing(['sprint_id', 'backlog_position'], $ranked[0]['payload']['fields']);
    }

    public function test_mcp_and_offline_sync_publish_like_the_ui(): void
    {
        $item = $this->item(['column_id' => $this->column('to-do')]);
        $this->settle();

        BrainitiveServer::actingAs($this->editor)->tool(MoveBoardItemTool::class, [
            'workspace' => $this->workspace->slug, 'project' => $this->project->slug, 'board' => $this->board->slug,
            'item_id' => $item->id, 'column_id' => $this->column('done'),
        ])->assertOk();
        $this->assertSame(['item.moved'], $this->published($this->boardChannel())->pluck('event')->all());
        $this->settle();

        $this->actingAs($this->editor)->postJson(route('offline.sync'), [
            'id' => (string) Str::uuid(), 'type' => 'issue.update',
            'target' => ['id' => $item->id],
            'base' => ['fields' => ['title' => $item->title]],
            'data' => ['title' => 'Edited offline'],
        ])->assertOk();
        $offline = $this->published($this->boardChannel());
        $this->assertSame('item.updated', $offline[0]['event']);
        $this->assertContains('title', $offline[0]['payload']['fields']);
    }

    public function test_imports_publish_one_refresh_per_board_instead_of_one_event_per_record(): void
    {
        $other = Board::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id]);
        $this->settle();

        ImportScope::run(function () use ($other) {
            $item = $this->item();
            $item->update(['title' => 'Imported twice']);
            Item::factory()->create(['board_id' => $other->id, 'created_by' => $this->editor->id]);
            $this->assertCount(0, $this->published(), 'nothing while the import runs');
        });

        $messages = $this->published();
        $this->assertEqualsCanonicalizing([
            ['channel' => $this->boardChannel(), 'event' => 'board.refreshed'],
            ['channel' => $this->boardChannel($other), 'event' => 'board.refreshed'],
        ], $messages->map(fn ($m) => ['channel' => $m['channel'], 'event' => $m['event']])->all());
    }

    public function test_nothing_is_collected_while_publishing_is_off(): void
    {
        config(['live.enabled' => false]);
        $item = $this->item();
        $item->update(['title' => 'Quiet']);

        $this->assertCount(0, $this->published());
        Http::assertNothingSent();
    }

    public function test_a_rolled_back_change_publishes_nothing(): void
    {
        $item = $this->item();
        $target = Board::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id]);
        $this->settle();

        foreach ([
            fn () => $item->update(['title' => 'Never saved']),
            fn () => $item->delete(),
            fn () => $item->update(['board_id' => $target->id]),
            fn () => $this->board->delete(),
        ] as $change) {
            try {
                DB::transaction(function () use ($change) {
                    $change();
                    throw new \RuntimeException('rolled back');
                });
            } catch (\RuntimeException) {
            }
        }

        $this->assertCount(0, $this->published(), 'nothing for a change that never happened');
    }

    public function test_writes_that_bypass_model_events_still_reach_the_board(): void
    {
        $this->board->update(['type' => 'scrum']);
        $sprint = Sprint::factory()->create(['board_id' => $this->board->id, 'status' => Sprint::STATUS_PLANNED]);
        [$a, $b] = [$this->item(), $this->item()];
        $child = $this->item(['parent_id' => $a->id]);
        $this->settle();
        $fields = fn () => $this->published($this->boardChannel())->map(fn ($m) => [$m['event'], $m['payload']['fields'] ?? null, $m['payload']['ids'] ?? [$m['payload']['id'] ?? null]])->all();

        // timeline reorder (query builder)
        $this->actingAs($this->editor)->post($this->boardRoute('boards.items.reorder'), ['items' => [['id' => $a->id, 'position' => 2], ['id' => $b->id, 'position' => 1]]])->assertRedirect();
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $fields()[0][2]);
        $this->settle();

        // the subtree follows its issue into the sprint (mass update)
        $a->moveToSprint($sprint);
        $this->assertSame([['items.changed', ['sprint_id', 'backlog_position'], [$a->id, $child->id]]], $fields());
        $this->settle();

        // watchers (pivot), links (both cards), restore
        $a->addWatcher($this->viewer);
        $this->assertSame([['item.updated', ['watchers'], [$a->id]]], $fields());
        $this->settle();
        ItemLink::create(['source_item_id' => $a->id, 'target_item_id' => $b->id, 'link_type' => 'blocks', 'created_by' => $this->editor->id]);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $fields()[0][2]);
        $this->settle();
        $b->delete();
        $this->settle();
        $b->restore();
        $this->assertSame('item.restored', $this->published($this->boardChannel())[0]['event']);
    }

    public function test_a_card_moved_away_and_back_in_one_request_is_refetched_not_removed(): void
    {
        $target = Board::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id]);
        $item = $this->item();
        $this->settle();

        $home = $this->column('to-do');
        $item->update(['board_id' => $target->id, 'column_id' => $target->columns()->value('id')]);
        $item->update(['board_id' => $this->board->id, 'column_id' => $home]);

        $this->assertSame(['item.moved'], $this->published($this->boardChannel())->pluck('event')->all());
    }

    // ── boards.cards: what a live board refetches ───────────────────────────

    public function test_cards_returns_card_data_of_this_board_for_members_only(): void
    {
        $item = $this->item(['title' => 'Visible card']);
        $foreign = Item::factory()->create(['created_by' => $this->editor->id]); // another workspace's board
        $otherBoard = Board::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id]);
        $sibling = Item::factory()->create(['board_id' => $otherBoard->id, 'created_by' => $this->editor->id]);
        $gone = $this->item();
        $gone->delete();
        $url = $this->boardRoute('boards.cards').'?'.http_build_query(['ids' => [$item->id, $foreign->id, $sibling->id, $gone->id]]);
        $this->getJson($url)->assertUnauthorized();

        $this->actingAs($this->viewer)->getJson($url)
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $item->id)
            ->assertJsonPath('items.0.title', 'Visible card')
            ->assertJsonStructure(['items' => [['id', 'key', 'column_id', 'position', 'labels', 'assignee', 'item_type', 'priority', 'comments_count', 'attachments_count', 'children_count', 'field_values', 'watchers']]]);

        $this->actingAs($this->outsider)->getJson($url)->assertForbidden();
        $this->actingAs($this->viewer)->getJson($this->boardRoute('boards.cards'))->assertUnprocessable();
        $this->actingAs($this->viewer)->getJson($this->boardRoute('boards.cards').'?'.http_build_query(['ids' => range(1, 101)]))->assertUnprocessable();
    }

    public function test_cards_on_a_scrum_board_are_the_running_sprints_like_the_board(): void
    {
        $this->board->update(['type' => 'scrum']);
        $sprint = Sprint::factory()->create(['board_id' => $this->board->id, 'status' => Sprint::STATUS_ACTIVE]);
        $inSprint = $this->item(['sprint_id' => $sprint->id]);
        $backlog = $this->item(['sprint_id' => null]);

        $this->actingAs($this->viewer)
            ->getJson($this->boardRoute('boards.cards').'?'.http_build_query(['ids' => [$inSprint->id, $backlog->id]]))
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $inSprint->id);
    }

    public function test_cards_needs_the_same_queries_for_one_card_or_many(): void
    {
        $label = Label::create(['project_id' => $this->project->id, 'name' => 'x', 'slug' => 'x', 'color' => '#000000']);
        $items = collect(range(1, 12))->map(function () use ($label) {
            $item = $this->item(['assigned_to' => $this->editor->id]);
            $item->labels()->attach($label->id);
            Comment::create(['item_id' => $item->id, 'user_id' => $this->editor->id, 'body' => 'hi']);

            return $item;
        });
        $count = function (array $ids): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->viewer)->getJson($this->boardRoute('boards.cards').'?'.http_build_query(['ids' => $ids]))->assertOk()->assertJsonCount(count($ids), 'items');
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $count([$items[0]->id]); // warm-up (session, auth)
        $this->assertSame($count([$items[0]->id]), $count($items->pluck('id')->all()), 'no N+1');
    }
}
