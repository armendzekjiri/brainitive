<?php

namespace Tests\Feature\Billing;

use App\Models\BillingActivity;
use App\Models\BillingReport;
use App\Models\TimeEntry;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * The shared v1.38.0 foundation (docs/ai/tasks/release-v1.38.0-billing.md): the time ledger keeps
 * items.time_spent in sync, billed entries are locked, used activities can't be deleted and billing
 * is for workspace admins only.
 */
class TimeTrackingFoundationTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
    }

    private function entry(array $attributes = []): TimeEntry
    {
        return TimeEntry::create($attributes + [
            'workspace_id' => $this->workspace->id,
            'project_id' => $this->project->id,
            'user_id' => $this->editor->id,
            'spent_on' => '2026-10-03',
            'minutes' => 90,
            'billable' => true,
        ]);
    }

    public function test_entries_keep_the_items_total_in_sync_and_keep_older_totals(): void
    {
        $item = $this->item(['time_spent' => 30]); // logged before the ledger existed
        $other = $this->item();

        $entry = $this->entry(['item_id' => $item->id]);
        $this->assertSame(120, $item->fresh()->time_spent);

        $entry->update(['minutes' => 60]);
        $this->assertSame(90, $item->fresh()->time_spent);

        $entry->update(['item_id' => $other->id]);
        $this->assertSame(30, $item->fresh()->time_spent);
        $this->assertSame(60, $other->fresh()->time_spent);

        $entry->delete();
        $this->assertSame(0, $other->fresh()->time_spent);
    }

    public function test_legacy_entries_never_change_the_total(): void
    {
        $item = $this->item(['time_spent' => 45]);
        $entry = $this->entry(['item_id' => $item->id, 'minutes' => 45, 'source' => TimeEntry::SOURCE_LEGACY]);
        $this->assertSame(45, $item->fresh()->time_spent);

        $entry->delete();
        $this->assertSame(45, $item->fresh()->time_spent);
    }

    public function test_billed_entries_are_locked_and_unbilled_scope_skips_them(): void
    {
        $report = BillingReport::create(['workspace_id' => $this->workspace->id, 'period_from' => '2026-10-01', 'period_to' => '2026-10-31', 'status' => 'finalized']);
        $billed = $this->entry();
        $billed->forceFill(['billing_report_id' => $report->id])->save();
        $open = $this->entry();

        $this->assertTrue($billed->fresh()->isLocked());
        $this->assertFalse($open->isLocked());
        $this->assertSame([$open->id], TimeEntry::unbilled()->pluck('id')->all());
    }

    public function test_a_used_activity_cannot_be_deleted(): void
    {
        $activity = BillingActivity::create(['workspace_id' => $this->workspace->id, 'name' => 'Programming', 'pricing_type' => BillingActivity::HOURLY, 'rate_cents' => 12000]);
        $this->entry(['billing_activity_id' => $activity->id]);

        $this->expectException(QueryException::class);
        $activity->delete();
    }

    public function test_billing_is_for_workspace_admins_only(): void
    {
        $admin = $this->workspace->owner;

        $this->assertTrue(Gate::forUser($admin)->allows('manageBilling', $this->workspace));
        $this->assertFalse(Gate::forUser($this->editor)->allows('manageBilling', $this->workspace));
        $this->assertFalse(Gate::forUser($this->outsider)->allows('manageBilling', $this->workspace));

        $this->actingAs($this->editor)->get(route('billing.index', $this->workspace->slug))->assertForbidden();
        $this->actingAs($admin)->get(route('billing.index', $this->workspace->slug))->assertNotFound(); // no billing tab built yet
    }
}
