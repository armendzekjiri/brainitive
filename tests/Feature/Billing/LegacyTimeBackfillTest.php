<?php

namespace Tests\Feature\Billing;

use App\Models\ActivityLog;
use App\Models\Item;
use App\Models\TimeEntry;
use App\Support\Billing\LegacyTimeBackfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * Work logged before the ledger (v1.38.0, BRAI-99) becomes `legacy` time entries — once, without
 * touching items.time_spent.
 */
class LegacyTimeBackfillTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
    }

    private function oldLog(Item $item, ?array $metadata, string $at, ?int $userId = null): ActivityLog
    {
        $log = new ActivityLog([
            'user_id' => $userId ?? $this->editor->id,
            'loggable_type' => Item::class,
            'loggable_id' => $item->id,
            'action' => 'work_logged',
            'metadata' => $metadata,
        ]);
        $log->created_at = $at;
        $log->updated_at = $at;
        $log->save();

        return $log;
    }

    public function test_old_work_logs_become_legacy_entries_once_and_totals_stay(): void
    {
        $this->editor->forceFill(['timezone' => 'Europe/Berlin'])->save();
        $item = $this->item(['title' => 'Checkout bug', 'time_spent' => 150]);
        $first = $this->oldLog($item, ['minutes' => 90, 'total_minutes' => 90, 'note' => 'Debugging'], '2026-09-30 22:30:00'); // Oct 1st in Berlin
        $this->oldLog($item, ['minutes' => 60, 'total_minutes' => 150, 'note' => null], '2026-10-02 08:00:00');
        $this->oldLog($item, ['note' => 'no minutes'], '2026-10-02 09:00:00');                  // skipped
        $this->oldLog($item, null, '2026-10-02 09:30:00');                                       // skipped
        $trashed = $this->item(['time_spent' => 30]);
        $this->oldLog($trashed, ['minutes' => 30], '2026-10-03 08:00:00');
        $trashed->delete();                                                                      // skipped: deleted issue
        $item->update(['title' => 'Checkout bug (renamed)']);                                    // history of the rename is not work

        $this->assertSame(2, LegacyTimeBackfill::run());
        $this->assertSame(0, LegacyTimeBackfill::run()); // re-run: nothing new
        $this->artisan('time-entries:backfill-legacy')->expectsOutput('Nothing to backfill.')->assertSuccessful();

        $entries = TimeEntry::orderBy('legacy_log_id')->get();
        $this->assertCount(2, $entries);
        $this->assertSame(150, $item->fresh()->time_spent);

        $entry = $entries->first();
        $this->assertSame([
            TimeEntry::SOURCE_LEGACY, '2026-10-01', 90, 'Debugging', true, null,
            $this->workspace->id, $this->project->id, $this->editor->id, $item->key, 'Checkout bug (renamed)', $first->id,
        ], [
            $entry->source, $entry->spent_on->toDateString(), $entry->minutes, $entry->note, $entry->billable, $entry->billing_activity_id,
            (int) $entry->workspace_id, (int) $entry->project_id, (int) $entry->user_id, $entry->item_key, $entry->item_title, (int) $entry->legacy_log_id,
        ]);
    }

    public function test_logs_written_with_a_ledger_entry_are_not_backfilled_and_an_empty_database_is_fine(): void
    {
        $this->assertSame(0, LegacyTimeBackfill::run());

        $item = $this->item();
        $this->actingAs($this->editor)->post($this->itemRoute('boards.items.work-logs.store', $item), ['time' => '1h'])->assertSessionHasNoErrors();

        $this->assertSame(0, LegacyTimeBackfill::run());
        $this->assertSame([1, 60], [TimeEntry::count(), $item->fresh()->time_spent]);
    }
}
