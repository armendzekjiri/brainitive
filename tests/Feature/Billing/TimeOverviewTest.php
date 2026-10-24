<?php

namespace Tests\Feature\Billing;

use App\Models\BillingActivity;
use App\Models\BillingReport;
use App\Models\Board;
use App\Models\Client;
use App\Models\Item;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Billing\TimeOverview;
use App\Support\Projects\ProjectTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * Billing → Time (BRAI-100): the month-end overview of everyone's logged time — filters, grouping,
 * the indicative amount (fixed prices once per ticket) and the streamed Excel export. Admins only.
 */
class TimeOverviewTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    private User $admin;

    private Project $other;

    private Board $otherBoard;

    private BillingActivity $programming;

    private BillingActivity $setup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        $this->travelTo('2026-11-15 12:00:00');

        $this->admin = $this->workspace->owner;
        $this->other = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->admin->id, 'key' => 'APP', 'name' => 'App']);
        $this->otherBoard = Board::factory()->create(['project_id' => $this->other->id, 'created_by' => $this->admin->id]);
        $this->project->update(['name' => 'Website']);

        $this->programming = BillingActivity::create(['workspace_id' => $this->workspace->id, 'name' => 'Programming', 'pricing_type' => 'hourly', 'rate_cents' => 12000]);
        $this->setup = BillingActivity::create(['workspace_id' => $this->workspace->id, 'name' => 'Setup', 'pricing_type' => 'fixed', 'rate_cents' => 45000]);
    }

    private function entry(array $attributes = []): TimeEntry
    {
        $item = $attributes['item'] ?? null;
        unset($attributes['item']);

        return TimeEntry::create($attributes + [
            'workspace_id' => $this->workspace->id,
            'project_id' => $item?->project_id ?? $this->project->id,
            'item_id' => $item?->id,
            'item_key' => $item?->key,
            'item_title' => $item?->title,
            'user_id' => $this->editor->id,
            'billing_activity_id' => $this->programming->id,
            'spent_on' => '2026-10-10',
            'minutes' => 60,
            'billable' => true,
        ]);
    }

    private function page(array $query = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->admin)->get(route('billing.time.index', ['workspace' => $this->workspace->slug] + $query));
    }

    private function summary(array $query = []): array
    {
        return $this->page($query)->assertOk()->viewData('page')['props']['summary'];
    }

    private function group(array $summary, string $label): array
    {
        $group = collect($summary['groups'])->firstWhere('label', $label);
        $this->assertNotNull($group, "group {$label}");

        return $group;
    }

    private static function pick(array $values, array $keys): array
    {
        return array_intersect_key($values, array_flip($keys));
    }

    public function test_members_and_outsiders_get_403(): void
    {
        foreach ([$this->editor, $this->viewer, $this->outsider] as $user) {
            $this->page([], $user)->assertForbidden();
            $this->actingAs($user)->get(route('billing.time.export', $this->workspace->slug))->assertForbidden();
        }
        $this->page()->assertOk()->assertInertia(fn ($page) => $page->component('Billing/Time/Index'));
    }

    public function test_the_default_period_is_last_month_in_the_viewers_time_zone(): void
    {
        $this->entry(['spent_on' => '2026-10-01', 'minutes' => 30]);
        $this->entry(['spent_on' => '2026-10-31', 'minutes' => 45]);
        $this->entry(['spent_on' => '2026-11-02', 'minutes' => 90]); // this month
        $this->entry(['spent_on' => '2026-09-30', 'minutes' => 15]);

        $props = $this->page()->assertOk()->viewData('page')['props'];
        $this->assertSame(['period' => 'last_month', 'from' => '2026-10-01', 'to' => '2026-10-31'], self::pick($props['filters'], ['period', 'from', 'to']));
        $this->assertSame(75, $props['summary']['totals']['minutes']);
        $this->assertSame(['from' => '2026-11-01', 'to' => '2026-11-30'], $props['presets']['this_month']);

        $this->assertSame(90, $this->summary(['period' => 'this_month'])['totals']['minutes']);
        $this->assertSame(135, $this->summary(['period' => 'custom', 'from' => '2026-10-31', 'to' => '2026-11-02'])['totals']['minutes']);
        // reversed dates are swapped; nonsense falls back to last month
        $this->assertSame(135, $this->summary(['period' => 'custom', 'from' => '2026-11-02', 'to' => '2026-10-31'])['totals']['minutes']);
        $this->assertSame(75, $this->summary(['period' => 'custom', 'from' => '2026-02-31', 'to' => 'x'])['totals']['minutes']);

        // a viewer in UTC+14 is already in December
        $this->admin->update(['timezone' => 'Pacific/Kiritimati']);
        $this->travelTo('2026-11-30 12:00:00');
        $this->assertSame(90, $this->summary()['totals']['minutes']);
    }

    public function test_filters_by_project_client_person_activity_and_billable(): void
    {
        $client = Client::create(['workspace_id' => $this->workspace->id, 'name' => 'Muster GmbH']);
        $this->other->forceFill(['client_id' => $client->id])->save();
        $member = $this->projectMember('editor');

        $this->entry(['minutes' => 60]);
        $this->entry(['minutes' => 30, 'user_id' => $member->id, 'billable' => false]);
        $this->entry(['minutes' => 120, 'project_id' => $this->other->id, 'billing_activity_id' => $this->setup->id]);
        $this->entry(['minutes' => 15, 'project_id' => $this->other->id, 'billing_activity_id' => null, 'user_id' => $member->id]);

        $this->assertSame(225, $this->summary()['totals']['minutes']);
        $this->assertSame(90, $this->summary(['projects' => [$this->project->id]])['totals']['minutes']);
        $this->assertSame(225, $this->summary(['projects' => [$this->project->id, $this->other->id]])['totals']['minutes']);
        $this->assertSame(135, $this->summary(['client' => $client->id])['totals']['minutes']);
        $this->assertSame(45, $this->summary(['person' => $member->id])['totals']['minutes']);
        $this->assertSame(120, $this->summary(['activity' => $this->setup->id])['totals']['minutes']);
        $this->assertSame(15, $this->summary(['activity' => 'none'])['totals']['minutes']);
        $this->assertSame(30, $this->summary(['billable' => 'non_billable'])['totals']['minutes']);
        $this->assertSame(
            ['minutes' => 195, 'billable_minutes' => 195, 'entries' => 3, 'non_billable_minutes' => 0],
            self::pick($this->summary(['billable' => 'billable'])['totals'], ['minutes', 'billable_minutes', 'entries', 'non_billable_minutes']),
        );
    }

    public function test_work_logged_on_an_issue_shows_up_this_month(): void
    {
        $item = $this->item(['title' => 'Checkout bug', 'time_spent' => 30]);
        $this->actingAs($this->editor)->post($this->itemRoute('boards.items.work-logs.store', $item), ['time' => '1h 30m'])->assertSessionHasNoErrors();

        $this->assertSame(120, $item->fresh()->time_spent); // the ledger keeps the running total
        $summary = $this->summary(['period' => 'this_month', 'group' => 'person']);
        $this->assertSame([$this->editor->name, 90, 1], [$summary['groups'][0]['label'], $summary['groups'][0]['minutes'], $summary['totals']['entries']]);
    }

    public function test_entries_of_other_workspaces_never_show_up(): void
    {
        $this->entry(['minutes' => 60]);
        $foreign = Project::factory()->create(['key' => 'FOR']);
        TimeEntry::create(['workspace_id' => $foreign->workspace_id, 'project_id' => $foreign->id, 'spent_on' => '2026-10-10', 'minutes' => 500]);

        $this->assertSame(60, $this->summary()['totals']['minutes']);
        $this->assertSame(0, $this->summary(['projects' => [$foreign->id]])['totals']['minutes']);
    }

    public function test_entries_of_moved_or_trashed_projects_show_nothing_live_from_them(): void
    {
        $client = Client::create(['workspace_id' => $this->workspace->id, 'name' => 'Muster GmbH']);
        $this->other->forceFill(['client_id' => $client->id])->save();
        $item = Item::factory()->create(['board_id' => $this->otherBoard->id, 'created_by' => $this->admin->id, 'title' => 'Logged here']);
        $this->entry(['item' => $item, 'minutes' => 60]);
        $this->entry(['minutes' => 30]);

        // moved to a workspace this admin doesn't see: no live project name, client or title from there
        $target = Workspace::factory()->create();
        ProjectTransfer::move($this->other, $target);
        $this->other->update(['name' => 'Secret new name']);
        $item->update(['title' => 'Secret new title']);
        $this->assertNull($this->other->fresh()->client_id); // clients stay in their workspace

        $summary = $this->summary();
        $this->assertSame(90, $summary['totals']['minutes']); // the logged work stays
        $gone = $this->group($summary, TimeOverview::PROJECT_GONE);
        $this->assertNull($gone['client']);
        $this->assertSame('Logged here', $gone['rows'][0]['label']);
        $this->assertSame(TimeOverview::PROJECT_GONE, end($summary['groups'])['label']); // listed last
        $this->assertStringNotContainsString('Secret', json_encode($summary));
        $this->assertSame(0, $this->summary(['client' => $client->id])['totals']['minutes']);

        $rows = $this->readXlsx($this->actingAs($this->admin)->get(route('billing.time.export', $this->workspace->slug))->streamedContent());
        $this->assertSame([TimeOverview::PROJECT_GONE, 'Logged here'], [$rows[1][3], $rows[1][5]]);

        // a project in the trash: same
        $this->project->delete();
        $this->assertSame([TimeOverview::PROJECT_GONE, TimeOverview::PROJECT_GONE], array_column($this->summary()['groups'], 'label')); // still one group per project
    }

    public function test_group_by_project_and_ticket_with_live_titles_and_snapshots(): void
    {
        $checkout = $this->item(['title' => 'Checkout bug']);
        $gone = $this->item(['title' => 'Old title']);
        $trashed = $this->item(['title' => 'Trashed title']);
        $app = Item::factory()->create(['board_id' => $this->otherBoard->id, 'created_by' => $this->admin->id, 'title' => 'App login']);

        $this->entry(['item' => $checkout, 'minutes' => 60]);
        $this->entry(['item' => $checkout, 'minutes' => 30, 'billable' => false]);
        $this->entry(['item' => $gone, 'minutes' => 45]);
        $this->entry(['item' => $trashed, 'minutes' => 20]);
        $this->entry(['minutes' => 15]); // no ticket
        $this->entry(['item' => $app, 'minutes' => 120]);

        $checkout->update(['title' => 'Checkout bug (renamed)']); // the live title wins
        $goneKey = $gone->key;
        $gone->forceDelete();                                     // item_id → null, the snapshot stays
        $trashed->delete();                                       // in the trash counts as gone too

        $summary = $this->summary();
        $this->assertSame(['App', 'Website'], array_column($summary['groups'], 'label'));

        $website = $this->group($summary, 'Website');
        $this->assertSame(['minutes' => 170, 'billable_minutes' => 140, 'entries' => 5], self::pick($website, ['minutes', 'billable_minutes', 'entries']));
        $rows = collect($website['rows'])->keyBy(fn ($row) => $row['ticket_key'] ?? 'none');
        $this->assertSame($checkout->key, $website['rows'][0]['ticket_key']); // most hours first
        $this->assertSame('Checkout bug (renamed)', $rows[$checkout->key]['label']);
        $this->assertSame([90, 60], [$rows[$checkout->key]['minutes'], $rows[$checkout->key]['billable_minutes']]);
        $this->assertSame('Old title', $rows[$goneKey]['label']);
        $this->assertSame('Trashed title', $rows[$trashed->key]['label']);
        $this->assertSame('No ticket', $rows['none']['label']);

        $this->assertSame(120, $this->group($summary, 'App')['minutes']);
        $this->assertSame(
            ['minutes' => 290, 'billable_minutes' => 260, 'entries' => 6, 'non_billable_minutes' => 30],
            self::pick($summary['totals'], ['minutes', 'billable_minutes', 'entries', 'non_billable_minutes']),
        );
    }

    public function test_group_by_person_activity_and_day(): void
    {
        $member = $this->projectMember('editor');
        $member->update(['name' => 'Aaron Member']);
        $this->editor->update(['name' => 'Erin Editor']);
        $this->entry(['minutes' => 60, 'spent_on' => '2026-10-02']);
        $this->entry(['minutes' => 30, 'user_id' => $member->id, 'spent_on' => '2026-10-01']);
        $this->entry(['minutes' => 45, 'user_id' => $member->id, 'billing_activity_id' => null, 'spent_on' => '2026-10-02', 'billable' => false]);

        $person = $this->summary(['group' => 'person']);
        $this->assertSame(['Aaron Member', 'Erin Editor'], array_column($person['groups'], 'label'));
        $this->assertSame([75, 30], [$person['groups'][0]['minutes'], $person['groups'][0]['billable_minutes']]);
        $this->assertSame(60, $person['groups'][1]['minutes']);
        $this->assertArrayNotHasKey('rows', $person['groups'][0]);

        $activity = $this->summary(['group' => 'activity']);
        $this->assertSame(['Programming', 'No activity'], array_column($activity['groups'], 'label'));
        $this->assertSame([90, 45], array_column($activity['groups'], 'minutes'));

        $day = $this->summary(['group' => 'day']);
        $this->assertSame(['2026-10-01', '2026-10-02'], array_column($day['groups'], 'label'));
        $this->assertSame([30, 105], array_column($day['groups'], 'minutes'));
    }

    public function test_the_estimate_counts_hourly_rates_and_a_fixed_price_once_per_ticket_and_activity(): void
    {
        $member = $this->projectMember('editor');
        $member->update(['name' => 'Zed Member']);
        $this->editor->update(['name' => 'Erin Editor']);
        $ticket = $this->item(['title' => 'SSM setup']);
        $second = $this->item(['title' => 'Mail setup']);

        $this->entry(['item' => $ticket, 'minutes' => 90]);                                                                         // 1.5 h × 120 € = 180 €
        $this->entry(['item' => $ticket, 'minutes' => 60, 'billing_activity_id' => $this->setup->id, 'spent_on' => '2026-10-03']); // 450 €, the earliest
        $this->entry(['item' => $ticket, 'minutes' => 120, 'billing_activity_id' => $this->setup->id, 'user_id' => $member->id]);  // same ticket: 0
        $this->entry(['item' => $second, 'minutes' => 30, 'billing_activity_id' => $this->setup->id, 'user_id' => $member->id]);   // other ticket: 450 €
        $this->entry(['minutes' => 10, 'billing_activity_id' => $this->setup->id]);                                                // no ticket: 450 € once
        $this->entry(['minutes' => 20, 'billing_activity_id' => $this->setup->id]);                                                // no ticket again: 0
        $this->entry(['item' => $ticket, 'minutes' => 30, 'billable' => false]);                                                   // not billable: 0
        $this->entry(['item' => $ticket, 'minutes' => 25, 'billing_activity_id' => null]);                                         // no activity: 0

        $summary = $this->summary();
        $this->assertSame(18000 + 3 * 45000, $summary['totals']['amount_cents']);
        $this->assertTrue($summary['totals']['priced']);
        $rows = collect($this->group($summary, 'Website')['rows'])->keyBy('label');
        $this->assertSame(18000 + 45000, $rows['SSM setup']['amount_cents']);
        $this->assertSame(45000, $rows['Mail setup']['amount_cents']);
        $this->assertSame(45000, $rows['No ticket']['amount_cents']);

        // grouped by person, a flat price lands on whoever logged the earliest entry; groups add up
        $person = $this->summary(['group' => 'person']);
        $this->assertSame(18000 + 2 * 45000, $this->group($person, 'Erin Editor')['amount_cents']);
        $this->assertSame(45000, $this->group($person, 'Zed Member')['amount_cents']);
        $this->assertSame($summary['totals']['amount_cents'], array_sum(array_column($person['groups'], 'amount_cents')));

        // hourly amounts round once per leaf: 3 × 10 min at 1 ct/h = 0.5 ct → 1 ct
        TimeEntry::query()->delete();
        $this->programming->update(['rate_cents' => 1]);
        foreach (range(1, 3) as $i) {
            $this->entry(['minutes' => 10]);
        }
        $this->assertSame(1, $this->summary()['totals']['amount_cents']);
    }

    public function test_entry_detail_shows_people_notes_and_billed_entries(): void
    {
        $ticket = $this->item(['title' => 'Checkout bug']);
        $report = BillingReport::create(['workspace_id' => $this->workspace->id, 'number' => '2026-014', 'period_from' => '2026-10-01', 'period_to' => '2026-10-31', 'status' => 'finalized']);
        $billed = $this->entry(['item' => $ticket, 'minutes' => 60, 'note' => 'Fixed rounding']);
        $billed->forceFill(['billing_report_id' => $report->id])->save();
        $this->entry(['item' => $ticket, 'minutes' => 30, 'spent_on' => '2026-10-11']);

        $this->assertSame([], $this->group($this->summary(), 'Website')['rows'][0]['detail']);

        $detail = $this->group($this->summary(['detail' => 1]), 'Website')['rows'][0]['detail'];
        $this->assertCount(2, $detail);
        $this->assertSame(
            ['date' => '2026-10-10', 'person' => $this->editor->name, 'activity' => 'Programming', 'note' => 'Fixed rounding', 'minutes' => 60, 'locked' => true, 'report' => '2026-014'],
            self::pick($detail[0], ['date', 'person', 'activity', 'note', 'minutes', 'locked', 'report']),
        );
        $this->assertFalse($detail[1]['locked']);
    }

    public function test_the_excel_export_has_one_row_per_filtered_entry(): void
    {
        $client = Client::create(['workspace_id' => $this->workspace->id, 'name' => 'Muster GmbH']);
        $this->project->forceFill(['client_id' => $client->id])->save();
        $ticket = $this->item(['title' => 'Checkout bug']);
        $report = BillingReport::create(['workspace_id' => $this->workspace->id, 'period_from' => '2026-10-01', 'period_to' => '2026-10-31', 'status' => 'finalized']);

        $billed = $this->entry(['item' => $ticket, 'minutes' => 90, 'note' => '=HYPERLINK("https://evil.test","x")']);
        $billed->forceFill(['billing_report_id' => $report->id])->save();
        $this->entry(['minutes' => 45, 'billable' => false, 'billing_activity_id' => $this->setup->id, 'spent_on' => '2026-10-12']);
        $this->entry(['minutes' => 30, 'project_id' => $this->other->id]);
        $this->entry(['minutes' => 15, 'spent_on' => '2026-11-03']); // other month

        $response = $this->actingAs($this->admin)->get(route('billing.time.export', ['workspace' => $this->workspace->slug, 'projects' => [$this->project->id]]));
        $response->assertOk();
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('time-2026-10.xlsx', (string) $response->headers->get('Content-Disposition'));

        $rows = $this->readXlsx($response->streamedContent());
        $this->assertCount(3, $rows); // header + 2 entries
        $this->assertSame(['Date', 'Person', 'Client', 'Project', 'Ticket key', 'Ticket title', 'Activity', 'Pricing type', 'Hours', 'Minutes', 'Billable', 'Billed (report)', 'Note'], $rows[0]);
        $this->assertSame(['2026-10-10', $this->editor->name, 'Muster GmbH', 'Website', $ticket->key, 'Checkout bug', 'Programming', 'hourly'], array_slice($rows[1], 0, 8));
        $this->assertEquals([1.5, 90, 'Yes', '#'.$report->id], array_slice($rows[1], 8, 4));
        $this->assertSame('=HYPERLINK("https://evil.test","x")', $rows[1][12]); // text, never a formula
        $this->assertSame(['Setup', 'fixed'], array_slice($rows[2], 6, 2));
        $this->assertEquals([0.75, 45, 'No'], array_slice($rows[2], 8, 3));
        $this->assertSame('', (string) ($rows[2][11] ?? ''));

        $custom = $this->actingAs($this->admin)->get(route('billing.time.export', ['workspace' => $this->workspace->slug, 'period' => 'custom', 'from' => '2026-10-01', 'to' => '2026-11-15']));
        $this->assertStringContainsString('time-2026-10-01_2026-11-15.xlsx', (string) $custom->headers->get('Content-Disposition'));
        $this->assertCount(5, $this->readXlsx($custom->streamedContent()));
    }

    public function test_the_page_runs_a_fixed_number_of_queries(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->page(['detail' => 1])->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $seed = function (int $n): void {
            $people = [$this->editor, $this->projectMember('editor'), $this->projectMember('editor')];
            $items = Item::factory()->count(5)->create(['board_id' => $this->board->id, 'created_by' => $this->admin->id]);
            foreach (range(1, $n) as $i) {
                $this->entry([
                    'item' => $i % 7 === 0 ? null : $items[$i % 5],
                    'user_id' => $people[$i % 3]->id,
                    'billing_activity_id' => $i % 4 === 0 ? $this->setup->id : $this->programming->id,
                    'spent_on' => sprintf('2026-10-%02d', 1 + $i % 28),
                    'minutes' => 15 + $i % 60,
                ]);
            }
        };

        $seed(5);
        $count(); // warm-up: the first request also fills per-request caches
        $small = $count();
        $seed(300);
        $large = $count();

        $this->assertSame($small, $large, "queries grew from {$small} to {$large} with the number of entries");
        $this->assertSame(305, $this->summary()['totals']['entries']);
    }

    /** @return list<list<mixed>> */
    private function readXlsx(string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $content);
        $reader = new Reader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
            break;
        }
        $reader->close();
        unlink($path);

        return $rows;
    }
}
