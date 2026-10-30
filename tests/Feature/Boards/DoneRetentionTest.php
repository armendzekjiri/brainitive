<?php

namespace Tests\Feature\Boards;

use App\Mcp\Servers\BrainitiveServer;
use App\Mcp\Tools\GetBoardTool;
use App\Mcp\Tools\UpdateBoardTool;
use App\Models\Board;
use App\Models\Item;
use App\Support\Templates\TemplateDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

/**
 * Done column retention (BRAI-346, like Jira's "hide completed issues older than…"): the board
 * setting, a reliable items.completed_at, the backfill, and the MCP get_board / update_board view.
 * The Kanban filtering itself is client-side (lib/doneRetention.ts, e2e board-done-retention).
 */
class DoneRetentionTest extends TestCase
{
    use InteractsWithBoards, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
    }

    private function retentionRoute(?Board $board = null): string
    {
        return route('boards.done-retention.update', [$this->workspace->slug, $this->project->slug, ($board ?? $this->board)->slug]);
    }

    // ── The setting ──────────────────────────────────────────────────────────

    public function test_new_boards_start_at_14_days_and_boards_without_the_setting_are_off(): void
    {
        $this->assertSame(14, $this->board->fresh()->doneRetentionSetting());

        // a board from before the setting existed (no key) shows every done card
        DB::table('boards')->where('id', $this->board->id)->update(['settings' => json_encode(['swimlanes' => ['mode' => 'none']])]);
        $old = $this->board->fresh();
        $this->assertSame(0, $old->doneRetentionDays());
        $this->assertNull($old->doneCutoff());

        // a caller that brings the setting keeps it
        $off = Board::factory()->create(['project_id' => $this->project->id, 'settings' => ['done_retention_days' => 0]]);
        $this->assertSame(0, $off->doneRetentionSetting());
    }

    public function test_scrum_boards_are_not_affected(): void
    {
        $this->board->update(['type' => 'scrum', 'settings' => ['done_retention_days' => 7]]);

        $this->assertSame(7, $this->board->doneRetentionSetting());
        $this->assertSame(0, $this->board->doneRetentionDays());
        $this->assertNull($this->board->doneCutoff());
    }

    public function test_editors_set_the_retention_and_other_settings_stay(): void
    {
        $this->board->update(['settings' => ['swimlanes' => ['mode' => 'priority'], 'done_retention_days' => 14]]);

        $this->actingAs($this->editor)->put($this->retentionRoute(), ['done_retention_days' => 7])
            ->assertRedirect()->assertSessionHas('success', 'Done cards now stay on the board for 7 days.');
        $settings = $this->board->fresh()->settings;
        $this->assertSame(7, $settings['done_retention_days']);
        $this->assertSame(['mode' => 'priority'], $settings['swimlanes']);

        $this->actingAs($this->editor)->put($this->retentionRoute(), ['done_retention_days' => '0'])->assertSessionHasNoErrors();
        $this->assertSame(0, $this->board->fresh()->doneRetentionSetting());
    }

    public function test_only_the_offered_values_are_accepted(): void
    {
        foreach ([5, -7, 365, 'abc', null, 7.5] as $value) {
            $this->actingAs($this->editor)->put($this->retentionRoute(), ['done_retention_days' => $value])
                ->assertSessionHasErrors('done_retention_days');
        }
        $this->assertSame(14, $this->board->fresh()->doneRetentionSetting());
    }

    public function test_viewers_and_outsiders_cannot_change_it(): void
    {
        $this->actingAs($this->viewer)->put($this->retentionRoute(), ['done_retention_days' => 7])->assertForbidden();
        $this->actingAs($this->outsider)->put($this->retentionRoute(), ['done_retention_days' => 7])->assertForbidden();
        $this->assertSame(14, $this->board->fresh()->doneRetentionSetting());
    }

    public function test_the_board_page_carries_the_setting_and_every_card(): void
    {
        $this->board->update(['settings' => ['done_retention_days' => 7]]);
        $old = $this->item(['column_id' => $this->column('done'), 'completed_at' => now()->subDays(10), 'title' => 'Old']);

        // the Kanban view hides it client-side; the list view on the same page still lists it
        $this->actingAs($this->viewer)->get(route('boards.show', [$this->workspace->slug, $this->project->slug, $this->board->slug]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('board.settings.done_retention_days', 7)
                ->where('board.columns.2.items.0.id', $old->id)
                ->has('board.columns.2.items.0.completed_at'));
    }

    // ── completed_at follows the column ──────────────────────────────────────

    public function test_completed_at_is_set_when_a_card_is_created_in_a_done_column(): void
    {
        Carbon::setTestNow('2026-10-30 10:00:00');
        $item = $this->item(['column_id' => $this->column('done')]);
        $this->assertSame('2026-10-30 10:00:00', $item->fresh()->completed_at->toDateTimeString());

        // a date the caller brings (an import's resolved date) is kept
        $imported = $this->item(['column_id' => $this->column('done'), 'completed_at' => '2024-03-20 12:00:00']);
        $this->assertSame('2024-03-20 12:00:00', $imported->fresh()->completed_at->toDateTimeString());

        $this->assertNull($this->item()->fresh()->completed_at);
    }

    public function test_completed_at_is_set_on_entering_a_done_column_and_cleared_on_leaving_it(): void
    {
        Carbon::setTestNow('2026-10-30 10:00:00');
        $item = $this->item();

        // a plain update (MCP update_board_item, offline sync…) — not only moveToColumn
        $item->update(['column_id' => $this->column('done')]);
        $this->assertSame('2026-10-30 10:00:00', $item->fresh()->completed_at->toDateTimeString());

        $item->update(['column_id' => $this->column('in-progress')]);
        $this->assertNull($item->fresh()->completed_at);
    }

    public function test_done_to_done_keeps_the_completion_date(): void
    {
        $archive = $this->board->columns()->create(['name' => 'Archive', 'slug' => 'archive', 'position' => 3, 'is_done_column' => true]);
        Carbon::setTestNow('2026-10-01 09:00:00');
        $item = $this->item();
        $item->moveToColumn($this->board->columns()->find($this->column('done')));

        Carbon::setTestNow('2026-10-30 09:00:00');
        $item->fresh()->moveToColumn($archive, 5); // another done column
        $this->assertSame('2026-10-01 09:00:00', $item->fresh()->completed_at->toDateTimeString());
        $item->fresh()->moveToColumn($archive, 0); // reorder inside it
        $this->assertSame('2026-10-01 09:00:00', $item->fresh()->completed_at->toDateTimeString());

        // the move endpoint (drag & drop) too
        $this->actingAs($this->editor)->patch($this->itemRoute('boards.items.move', $item), ['column_id' => $this->column('done'), 'position' => 0])->assertRedirect();
        $this->assertSame('2026-10-01 09:00:00', $item->fresh()->completed_at->toDateTimeString());

        // reopened and done again: a new date
        $this->actingAs($this->editor)->patch($this->itemRoute('boards.items.move', $item), ['column_id' => $this->column('to-do'), 'position' => 0])->assertRedirect();
        $this->assertNull($item->fresh()->completed_at);
        $this->actingAs($this->editor)->patch($this->itemRoute('boards.items.move', $item), ['column_id' => $this->column('done'), 'position' => 0])->assertRedirect();
        $this->assertSame('2026-10-30 09:00:00', $item->fresh()->completed_at->toDateTimeString());
    }

    public function test_a_stale_date_from_an_open_column_is_replaced_on_entering_done(): void
    {
        Carbon::setTestNow('2026-10-30 10:00:00');
        $item = $this->item(['completed_at' => '2025-01-01 00:00:00']); // To Do, used as a "done" marker by older flows

        $item->update(['column_id' => $this->column('in-progress')]);
        $this->assertSame('2025-01-01 00:00:00', $item->fresh()->completed_at->toDateTimeString(), 'open → open leaves it alone');

        $item->update(['column_id' => $this->column('done')]);
        $this->assertSame('2026-10-30 10:00:00', $item->fresh()->completed_at->toDateTimeString());
    }

    public function test_flagging_a_column_done_completes_its_cards_and_unflagging_reopens_them(): void
    {
        Carbon::setTestNow('2026-10-30 10:00:00');
        $review = $this->column('in-progress');
        $item = $this->item(['column_id' => $review]);
        $doneEarlier = $this->item(['column_id' => $review, 'completed_at' => '2026-10-01 00:00:00']);

        $this->actingAs($this->editor)->put(route('boards.columns.update', [$this->workspace->slug, $this->project->slug, $this->board->slug, $review]), [
            'name' => 'Shipped', 'is_done_column' => true,
        ])->assertRedirect();
        $this->assertSame('2026-10-30 10:00:00', $item->fresh()->completed_at->toDateTimeString());
        $this->assertSame('2026-10-01 00:00:00', $doneEarlier->fresh()->completed_at->toDateTimeString());

        $this->actingAs($this->editor)->put(route('boards.columns.update', [$this->workspace->slug, $this->project->slug, $this->board->slug, $review]), [
            'name' => 'Shipped', 'is_done_column' => false,
        ])->assertRedirect();
        $this->assertNull($item->fresh()->completed_at);
        $this->assertNull($doneEarlier->fresh()->completed_at);
    }

    public function test_the_backfill_dates_done_cards_without_a_completion_time(): void
    {
        $done = $this->item(['column_id' => $this->column('done')]);
        $open = $this->item();
        $dated = $this->item(['column_id' => $this->column('done'), 'completed_at' => '2026-09-01 00:00:00']);
        // as before this change: a done card without a date (created there by an older path)
        DB::table('items')->where('id', $done->id)->update(['completed_at' => null, 'updated_at' => '2026-08-15 12:00:00']);

        (require database_path('migrations/2026_11_05_134600_backfill_items_completed_at.php'))->up();

        $this->assertSame('2026-08-15 12:00:00', $done->fresh()->completed_at->toDateTimeString());
        $this->assertNull($open->fresh()->completed_at);
        $this->assertSame('2026-09-01 00:00:00', $dated->fresh()->completed_at->toDateTimeString());
    }

    // ── MCP ──────────────────────────────────────────────────────────────────

    private function mcp(string $tool, array $args)
    {
        return BrainitiveServer::actingAs($this->editor)->tool($tool, $args + [
            'workspace' => $this->workspace->slug, 'project' => $this->project->slug, 'board' => $this->board->slug,
        ]);
    }

    private function structured($response): array
    {
        $data = null;
        $response->assertStructuredContent(function (AssertableJson $json) use (&$data) {
            $data = $json->toArray();
            $json->etc();
        });

        return $data;
    }

    public function test_get_board_leaves_older_done_cards_out_like_the_board(): void
    {
        $this->board->update(['settings' => ['done_retention_days' => 7]]);
        $old = $this->item(['column_id' => $this->column('done'), 'completed_at' => now()->subDays(10), 'title' => 'Old']);
        $fresh = $this->item(['column_id' => $this->column('done'), 'title' => 'Fresh']);
        $open = $this->item(['completed_at' => now()->subDays(30), 'title' => 'Open']); // not in a done column: always listed

        $data = $this->structured($this->mcp(GetBoardTool::class, []));
        $this->assertSame(7, $data['done_retention_days']);
        [$todo, , $done] = $data['columns'];
        $this->assertSame([$open->id], array_column($todo['items'], 'id'));
        $this->assertSame([$fresh->id], array_column($done['items'], 'id'));
        $this->assertSame(1, $done['hidden_done_count']);

        $all = $this->structured($this->mcp(GetBoardTool::class, ['include_old_done' => true]));
        $this->assertEqualsCanonicalizing([$old->id, $fresh->id], array_column($all['columns'][2]['items'], 'id'));
        $this->assertSame(0, $all['columns'][2]['hidden_done_count']);
    }

    public function test_update_board_sets_the_retention(): void
    {
        $this->mcp(UpdateBoardTool::class, ['done_retention_days' => 30])->assertOk();
        $this->assertSame(30, $this->board->fresh()->doneRetentionSetting());

        $this->mcp(UpdateBoardTool::class, ['done_retention_days' => 3])->assertHasErrors();
        $this->assertSame(30, $this->board->fresh()->doneRetentionSetting());

        $this->assertSame(0, Item::count());
    }

    // ── Templates ────────────────────────────────────────────────────────────

    public function test_template_documents_keep_valid_values_only(): void
    {
        $doc = TemplateDocument::normalize(['format' => 1, 'boards' => [
            ['ref' => 'b1', 'name' => 'A', 'done_retention_days' => 30],
            ['ref' => 'b2', 'name' => 'B', 'done_retention_days' => 0],
            ['ref' => 'b3', 'name' => 'C', 'done_retention_days' => 5],
            ['ref' => 'b4', 'name' => 'D'],
        ]]);

        $this->assertSame([30, 0, null, null], array_column($doc['boards'], 'done_retention_days'));
    }
}
