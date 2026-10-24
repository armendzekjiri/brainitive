<?php

namespace Tests\Feature\Insights;

use App\Http\Controllers\InsightsController;
use App\Models\Board;
use App\Models\Helpdesk;
use App\Models\Item;
use App\Models\ItemType;
use App\Models\LeadForm;
use App\Models\Project;
use App\Models\ProjectHealthReport;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Insights\InsightsFilters;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * Admin insights (BRAI-105): numbers per tab on seeded data, the filters, admin-only access,
 * a fixed query count per tab and the CSV export.
 */
class InsightsTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    private const NOW = '2026-10-24 10:00:00';

    private const RANGE = ['period' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-24'];

    private User $admin;

    private Project $second;

    private Board $secondBoard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        $this->travelTo(CarbonImmutable::parse(self::NOW, 'UTC'));
        $this->admin = User::find($this->workspace->owner_id);
        $this->admin->update(['timezone' => 'UTC']);
        $this->second = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->admin->id, 'name' => 'Second', 'key' => 'SEC']);
        $this->secondBoard = Board::factory()->create(['project_id' => $this->second->id, 'created_by' => $this->admin->id]);
    }

    // ── seed helpers ─────────────────────────────────────────────────────────

    private function issue(array $attributes = [], ?Board $board = null): Item
    {
        $board ??= $this->board;
        $done = ! empty($attributes['completed_at']);
        $doneColumn = $board->columns()->where('is_done_column', true)->value('id');
        $firstColumn = $board->columns()->orderBy('position')->value('id');

        return Item::factory()->create($attributes + [
            'board_id' => $board->id,
            'column_id' => $done ? $doneColumn : $firstColumn,
            'created_by' => $this->editor->id,
        ]);
    }

    private function timeEntry(Project $project, ?User $user, string $date, int $minutes): void
    {
        DB::table('time_entries')->insert([
            'workspace_id' => $this->workspace->id, 'project_id' => $project->id, 'user_id' => $user?->id,
            'spent_on' => $date, 'minutes' => $minutes, 'billable' => true, 'source' => 'manual',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** The scenario every "numbers" test reads (see the comments for the expected values). */
    private function seedScenario(): array
    {
        $defect = ItemType::create(['project_id' => $this->project->id, 'name' => 'Defect', 'slug' => 'defect']);

        // project 1: A, B completed in range (2 d / 4 d), C open + overdue, D created before the range but
        // completed in it (27 d), E open not due yet; a deleted issue never counts
        $a = $this->issue(['title' => 'A', 'item_type_id' => $defect->id, 'assigned_to' => $this->editor->id, 'created_at' => '2026-10-02 08:00:00', 'completed_at' => '2026-10-04 08:00:00']);
        $this->issue(['title' => 'B', 'assigned_to' => $this->editor->id, 'created_at' => '2026-10-05 08:00:00', 'completed_at' => '2026-10-09 08:00:00']);
        $this->issue(['title' => 'C', 'item_type_id' => $defect->id, 'assigned_to' => $this->viewer->id, 'created_at' => '2026-10-10 08:00:00', 'due_date' => '2026-10-20']);
        $this->issue(['title' => 'D', 'assigned_to' => $this->editor->id, 'created_at' => '2026-09-15 08:00:00', 'completed_at' => '2026-10-12 08:00:00']);
        $this->issue(['title' => 'E', 'created_at' => '2026-10-20 08:00:00', 'due_date' => '2026-10-30']);
        $this->issue(['title' => 'Deleted', 'created_at' => '2026-10-06 08:00:00'])->delete();

        // project 2: G open and overdue; an issue on a deleted board never counts
        $g = $this->issue(['title' => 'G', 'created_at' => '2026-10-03 08:00:00', 'due_date' => '2026-10-01'], $this->secondBoard);
        $gone = Board::factory()->create(['project_id' => $this->second->id, 'created_by' => $this->admin->id]);
        $this->issue(['title' => 'On deleted board', 'created_at' => '2026-10-07 08:00:00'], $gone);
        $gone->delete();

        // 1.5 h + 0.5 h in range; 1 h the day before the range
        $this->timeEntry($this->project, $this->editor, '2026-10-05', 90);
        $this->timeEntry($this->second, $this->viewer, '2026-10-06', 30);
        $this->timeEntry($this->project, $this->editor, '2026-09-30', 60);

        // comments: editor 2 in range (+1 before), viewer 1
        foreach ([['2026-10-03', $this->editor, $a], ['2026-10-04', $this->editor, $a], ['2026-09-20', $this->editor, $a], ['2026-10-05', $this->viewer, $g]] as [$day, $user, $item]) {
            DB::table('comments')->insert(['item_id' => $item->id, 'user_id' => $user->id, 'body' => 'x', 'created_at' => "{$day} 09:00:00", 'updated_at' => "{$day} 09:00:00"]);
        }

        // activity: only this workspace's issues count (activity_logs spans every workspace)
        DB::table('activity_logs')->delete();
        $elsewhere = $this->foreignIssue();
        DB::table('activity_logs')->insert([
            ['user_id' => $this->editor->id, 'loggable_type' => Item::class, 'loggable_id' => $a->id, 'action' => 'updated', 'created_at' => '2026-10-15 12:00:00', 'updated_at' => '2026-10-15 12:00:00'],
            ['user_id' => $this->editor->id, 'loggable_type' => Item::class, 'loggable_id' => $elsewhere->id, 'action' => 'updated', 'created_at' => '2026-10-20 12:00:00', 'updated_at' => '2026-10-20 12:00:00'],
        ]);
        $this->editor->forceFill(['last_login_at' => '2026-10-10 07:00:00'])->save();

        // health: the latest report of project 1 says at risk
        foreach (['on_track' => '2026-10-01', 'at_risk' => '2026-10-15'] as $status => $day) {
            ProjectHealthReport::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->admin->id, 'status' => $status, 'created_at' => "{$day} 10:00:00"]);
        }

        return ['a' => $a, 'g' => $g];
    }

    private function foreignIssue(): Item
    {
        $other = Workspace::factory()->create(['owner_id' => $this->admin->id]);
        $project = Project::factory()->create(['workspace_id' => $other->id, 'created_by' => $this->admin->id]);
        $board = Board::factory()->create(['project_id' => $project->id, 'created_by' => $this->admin->id]);

        return Item::factory()->create(['board_id' => $board->id, 'created_by' => $this->admin->id]);
    }

    /** @return array<string, mixed> the tab's `insights` prop */
    private function tab(string $tab, array $query = self::RANGE, ?User $user = null): array
    {
        $props = null;
        $this->actingAs($user ?? $this->admin)->get(route("insights.{$tab}", [$this->workspace->slug, ...$query]))->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$props, $tab) {
                $page->component(InsightsController::TABS[$tab][0]);
                $props = $page->toArray()['props'];
            });

        return $props['insights'] + ['_props' => $props];
    }

    // ── access ───────────────────────────────────────────────────────────────

    public function test_pages_exist_and_index_redirects_to_overview(): void
    {
        foreach (InsightsController::TABS as [$component]) {
            $this->assertFileExists(resource_path("js/Pages/{$component}.tsx"));
        }
        $this->assertFileExists(resource_path('js/Layouts/InsightsLayout.tsx'));

        $this->actingAs($this->admin)->get(route('insights.index', [$this->workspace->slug, 'period' => 'last_90']))
            ->assertRedirect(route('insights.overview', [$this->workspace->slug, 'period' => 'last_90']));
    }

    public function test_members_and_outsiders_get_403(): void
    {
        foreach ([$this->editor, $this->viewer, $this->outsider] as $user) {
            $this->actingAs($user)->get(route('insights.index', $this->workspace->slug))->assertForbidden();
            foreach (array_keys(InsightsController::TABS) as $tab) {
                $this->actingAs($user)->get(route("insights.{$tab}", $this->workspace->slug))->assertForbidden();
                $this->actingAs($user)->get(route('insights.export', [$this->workspace->slug, $tab]))->assertForbidden();
            }
        }
        $this->get(route('insights.overview', $this->workspace->slug))->assertForbidden();
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get(route('insights.overview', $this->workspace->slug))->assertRedirect(route('login'));
    }

    // ── numbers ──────────────────────────────────────────────────────────────

    public function test_overview_numbers(): void
    {
        $this->seedScenario();
        $data = $this->tab('overview');

        $this->assertEquals([
            'created' => 5,            // A B C E G
            'completed' => 3,          // A B D
            'completion_rate' => 40,   // A B of the 5 created
            'median_cycle_days' => 4.0, // 2, 4, 27
            'open' => 3,               // C E G
            'overdue' => 2,            // C G
            'hours' => 2.0,
        ], $data['kpis']);

        $this->assertSame([
            ['week' => '2026-09-28', 'created' => 2, 'completed' => 1],
            ['week' => '2026-10-05', 'created' => 2, 'completed' => 1],
            ['week' => '2026-10-12', 'created' => 0, 'completed' => 1],
            ['week' => '2026-10-19', 'created' => 1, 'completed' => 0],
        ], $data['weeks']);

        $this->assertSame([[$this->project->id, 2, 1], [$this->second->id, 1, 1]], array_map(fn ($p) => [$p['id'], $p['open'], $p['overdue']], $data['projects']));
        $this->assertSame([['name' => null, 'created' => 3, 'completed' => 1], ['name' => 'Defect', 'created' => 2, 'completed' => 1]], $data['types']);
    }

    public function test_project_filter_and_presets(): void
    {
        $this->seedScenario();
        $data = $this->tab('overview', self::RANGE + ['project' => $this->second->id]);
        $this->assertSame(1, $data['kpis']['created']);
        $this->assertSame(0.5, $data['kpis']['hours']);
        $this->assertSame($this->second->id, $data['_props']['filters']['project']);

        // a project of another workspace is ignored
        $foreign = $this->foreignIssue();
        $data = $this->tab('overview', self::RANGE + ['project' => $foreign->project_id]);
        $this->assertNull($data['_props']['filters']['project']);
        $this->assertSame(5, $data['kpis']['created']);

        $filters = $this->tab('overview', ['period' => 'this_month'])['_props'];
        $this->assertSame(['from' => '2026-10-01', 'to' => '2026-10-24'], array_intersect_key($filters['filters'], ['from' => 1, 'to' => 1]));
        $this->assertSame(['from' => '2026-09-01', 'to' => '2026-09-30'], $filters['presets']['last_month']);
        $this->assertSame(['from' => '2026-09-25', 'to' => '2026-10-24'], $filters['presets']['last_30']);
        $this->assertSame('2026-07-27', $filters['presets']['last_90']['from']);

        // swapped custom dates are put in order, and a range is at most 366 days
        $props = $this->tab('overview', ['period' => 'custom', 'from' => '2026-10-10', 'to' => '2026-10-01'])['_props'];
        $this->assertSame(['2026-10-01', '2026-10-10'], [$props['filters']['from'], $props['filters']['to']]);
        $props = $this->tab('overview', ['period' => 'custom', 'from' => '2020-01-01', 'to' => '2026-10-01'])['_props'];
        $this->assertSame('2020-12-31', $props['filters']['to']);
        // nonsense falls back to the default
        $props = $this->tab('overview', ['period' => 'custom', 'from' => '2026-02-31', 'to' => 'x'])['_props'];
        $this->assertSame(InsightsFilters::DEFAULT_PERIOD, $props['filters']['period']);
    }

    public function test_ranges_follow_the_viewers_time_zone(): void
    {
        // 23:30 UTC on Sep 30 is already Oct 1 in Berlin
        $this->issue(['created_at' => '2026-09-30 23:30:00']);
        $this->assertSame(0, $this->tab('overview')['kpis']['created']);

        $this->admin->update(['timezone' => 'Europe/Berlin']);
        $data = $this->tab('overview');
        $this->assertSame(1, $data['kpis']['created']);
        $this->assertSame(1, $data['weeks'][0]['created']);
    }

    public function test_team_numbers(): void
    {
        $this->seedScenario();
        $members = collect($this->tab('team')['members'])->keyBy('id');

        $this->assertCount(3, $members);
        $editor = $members[$this->editor->id];
        $this->assertSame([3, 0, 0, 1.5, 2], [$editor['completed'], $editor['open'], $editor['overdue'], $editor['hours'], $editor['comments']]);
        $this->assertSame('2026-10-10T07:00:00+00:00', $editor['last_login_at']);
        $this->assertSame('2026-10-15T12:00:00+00:00', $editor['last_activity_at']); // not the other workspace's 10-20
        $this->assertSame('2026-10-15T12:00:00+00:00', $editor['last_active_at']);
        $this->assertSame(route('workspaces.members.profile', [$this->workspace->slug, $this->editor->id]), $editor['profile_url']);

        $viewer = $members[$this->viewer->id];
        $this->assertSame([0, 1, 1, 0.5, 1], [$viewer['completed'], $viewer['open'], $viewer['overdue'], $viewer['hours'], $viewer['comments']]);
        $this->assertNull($viewer['last_active_at']);
        $this->assertSame('admin', $members[$this->admin->id]['role']);
    }

    public function test_project_numbers(): void
    {
        $this->seedScenario();
        $projects = collect($this->tab('projects')['projects'])->keyBy('id');

        $this->assertCount(2, $projects);
        $first = $projects[$this->project->id];
        $this->assertSame('at_risk', $first['health']);
        $this->assertSame([5, 2, 1, 3, 1.5], [$first['issues'], $first['open'], $first['overdue'], $first['done_in_range'], $first['hours']]);
        $this->assertNotNull($first['last_activity_at']);
        $this->assertSame(route('projects.health', [$this->workspace->slug, $this->project->slug]), $first['health_url']);

        $second = $projects[$this->second->id];
        $this->assertNull($second['health']);
        $this->assertSame([1, 1, 1, 0, 0.5], [$second['issues'], $second['open'], $second['overdue'], $second['done_in_range'], $second['hours']]);
    }

    private function seedHelpdeskAndLeads(): void
    {
        $helpdesk = Helpdesk::create(['project_id' => $this->project->id, 'name' => 'Support', 'slug' => 'support', 'created_by' => $this->admin->id]);
        $column = $helpdesk->columns()->orderBy('position')->value('id');
        $contact = DB::table('ticket_contacts')->insertGetId(['uuid' => (string) Str::uuid(), 'email' => 'c@example.test', 'created_at' => now(), 'updated_at' => now()]);
        // resolved in 5 h, 24 h and 360 h (created before the range); one still open
        foreach ([['2026-10-02 08:00:00', '2026-10-02 13:00:00'], ['2026-10-03 10:00:00', '2026-10-04 10:00:00'], ['2026-09-20 10:00:00', '2026-10-05 10:00:00'], ['2026-10-10 10:00:00', null]] as $i => [$created, $completed]) {
            $ticket = DB::table('tickets')->insertGetId([
                'uuid' => (string) Str::uuid(), 'helpdesk_id' => $helpdesk->id, 'column_id' => $column, 'ticket_contact_id' => $contact,
                'key' => "SUP-{$i}", 'subject' => "Ticket {$i}", 'position' => $i, 'created_at' => $created, 'updated_at' => $created, 'completed_at' => $completed,
            ]);
        }
        DB::table('ticket_attachments')->insert(['ticket_id' => $ticket, 'filename' => 'f', 'original_filename' => 'f', 'path' => 'p', 'size' => 500, 'created_at' => now(), 'updated_at' => now()]);

        $form = LeadForm::create(['project_id' => $this->project->id, 'name' => 'Contact us', 'slug' => 'contact-us', 'status' => 'published', 'created_by' => $this->admin->id]);
        $new = DB::table('lead_form_columns')->insertGetId(['lead_form_id' => $form->id, 'name' => 'New', 'slug' => 'new-'.$form->id, 'position' => 0, 'is_done' => false]);
        $won = DB::table('lead_form_columns')->insertGetId(['lead_form_id' => $form->id, 'name' => 'Won', 'slug' => 'won-'.$form->id, 'position' => 1, 'is_done' => true]);
        foreach ([[$new, '2026-10-02'], [$new, '2026-10-03'], [$won, '2026-10-04'], [$won, '2026-09-04']] as $n => [$col, $day]) {
            DB::table('leads')->insert(['uuid' => (string) Str::uuid(), 'lead_form_id' => $form->id, 'column_id' => $col, 'number' => $n + 1, 'created_at' => "{$day} 10:00:00", 'updated_at' => "{$day} 10:00:00"]);
        }
        foreach ([['s1', 'view'], ['s1', 'view'], ['s2', 'view'], ['s3', 'view'], ['s1', 'start'], ['s2', 'start'], ['s1', 'submit']] as [$session, $type]) {
            DB::table('lead_form_events')->insert(['lead_form_id' => $form->id, 'session_id' => $session, 'type' => $type, 'created_at' => '2026-10-05 10:00:00']);
        }
        DB::table('lead_form_events')->insert(['lead_form_id' => $form->id, 'session_id' => 'old', 'type' => 'view', 'created_at' => '2026-09-05 10:00:00']);
    }

    public function test_helpdesk_and_lead_numbers(): void
    {
        $this->seedHelpdeskAndLeads();
        $data = $this->tab('helpdesk');

        $this->assertEquals(['created' => 3, 'resolved' => 3, 'open' => 1, 'median_resolution_hours' => 24.0], $data['tickets']);
        $this->assertSame([['Support', 3, 3, 1]], array_map(fn ($h) => [$h['name'], $h['created'], $h['resolved'], $h['open']], $data['helpdesks']));
        $this->assertSame(['total' => 3, 'views' => 3, 'starts' => 2, 'submits' => 1], $data['leads']);
        $form = $data['forms'][0];
        $this->assertSame([3, 1, 3, 2, 1, 33.3], [$form['leads'], $form['closed'], $form['views'], $form['starts'], $form['submits'], $form['conversion']]);
    }

    public function test_usage_numbers(): void
    {
        $issues = $this->seedScenario();
        $this->seedHelpdeskAndLeads();
        DB::table('attachments')->insert(['item_id' => $issues['a']->id, 'user_id' => $this->editor->id, 'filename' => 'f', 'original_filename' => 'f', 'path' => 'p', 'size' => 1000, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([[null, '2026-11-01'], [null, '2026-10-01'], ['2026-10-02', '2026-11-01']] as $i => [$accepted, $expires]) {
            DB::table('workspace_invitations')->insert(['workspace_id' => $this->workspace->id, 'email' => "i{$i}@example.test", 'role' => 'member', 'token' => Str::random(40), 'invited_by' => $this->admin->id, 'expires_at' => $expires, 'accepted_at' => $accepted, 'created_at' => now(), 'updated_at' => now()]);
        }

        $data = $this->tab('usage');
        $this->assertSame(3, $data['members']);
        $this->assertSame(['admin' => 1, 'member' => 2], collect($data['roles'])->sortKeys()->all());
        $this->assertSame(1, $data['pending_invites']);
        $this->assertSame(2, $data['projects']);
        $this->assertSame(1500, $data['storage_bytes']);
        $this->assertSame([$this->project->id, 2, 1000, 500], [$data['storage'][0]['id'], $data['storage'][0]['files'], $data['storage'][0]['issue_bytes'], $data['storage'][0]['ticket_bytes']]);
        $this->assertSame(Schema::hasTable('webhooks') ? 0 : null, $data['webhooks']);
    }

    // ── query count ──────────────────────────────────────────────────────────

    public function test_every_tab_runs_a_fixed_number_of_queries(): void
    {
        $count = function (string $tab): int {
            $request = Request::create('/', 'GET', self::RANGE);
            $request->setUserResolver(fn () => $this->admin);
            DB::flushQueryLog();
            DB::enableQueryLog();
            InsightsController::compute($tab, InsightsFilters::fromRequest($request, $this->workspace));
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->seedScenario();
        $this->seedHelpdeskAndLeads();
        $small = array_map($count, array_combine(array_keys(InsightsController::TABS), array_keys(InsightsController::TABS)));

        // many more projects, members, issues, comments, time and health reports
        foreach (range(1, 6) as $p) {
            $project = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->admin->id]);
            $board = Board::factory()->create(['project_id' => $project->id, 'created_by' => $this->admin->id]);
            ProjectHealthReport::factory()->create(['project_id' => $project->id, 'created_by' => $this->admin->id]);
            Helpdesk::create(['project_id' => $project->id, 'name' => "Desk {$p}", 'slug' => "desk-{$p}", 'created_by' => $this->admin->id]);
            $member = $this->projectMember('editor');
            foreach (range(1, 15) as $i) {
                $item = $this->issue(['assigned_to' => $member->id, 'created_at' => '2026-10-0'.(($i % 9) + 1).' 10:00:00', 'completed_at' => $i % 3 ? null : '2026-10-15 10:00:00', 'due_date' => '2026-10-1'.($i % 10)], $board);
                DB::table('comments')->insert(['item_id' => $item->id, 'user_id' => $member->id, 'body' => 'x', 'created_at' => '2026-10-05 10:00:00', 'updated_at' => '2026-10-05 10:00:00']);
            }
            $this->timeEntry($project, $member, '2026-10-05', 45);
        }
        $large = array_map($count, array_combine(array_keys(InsightsController::TABS), array_keys(InsightsController::TABS)));

        $this->assertSame($small, $large);
        // projects list + the tab's own aggregates
        $this->assertSame(['overview' => 8, 'team' => 6, 'projects' => 4, 'helpdesk' => 7, 'usage' => Schema::hasTable('webhooks') ? 7 : 6], $large);
    }

    // ── CSV ──────────────────────────────────────────────────────────────────

    public function test_csv_export_per_tab(): void
    {
        $this->seedScenario();
        $this->seedHelpdeskAndLeads();
        $this->project->update(['name' => '=HYPERLINK("http://evil")']);

        // rows that must be in each file (row prefixes, as parsed by a CSV reader)
        $expect = [
            'overview' => [['Issues created', '5'], ['Median cycle time (days)', '4'], ['Week starting', 'Created', 'Completed'], ['2026-09-28', '2', '1'], ['No type', '3', '1']],
            'team' => [['Member', 'Role', 'Completed'], [$this->editor->name, 'member', '3', '0', '0', '1.5', '2']],
            'projects' => [['Project', 'Key', 'Health'], ["'=HYPERLINK(\"http://evil\")", 'BRAI', 'at_risk', '5', '2', '1', '3', '1.5']],
            'helpdesk' => [['Tickets resolved', '3'], ['Support', "'=HYPERLINK(\"http://evil\")", '3', '3', '1'], ['Contact us']],
            'usage' => [['Members', '3'], ['Pending invitations', '0']],
        ];
        foreach ($expect as $tab => $lines) {
            $response = $this->actingAs($this->admin)->get(route('insights.export', [$this->workspace->slug, $tab, ...self::RANGE]));
            $response->assertOk();
            $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
            $this->assertStringContainsString("insights-{$tab}-2026-10-01-2026-10-24.csv", $response->headers->get('Content-Disposition'));
            $csv = $response->streamedContent();
            $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
            $rows = array_map(fn (string $line) => str_getcsv($line, ',', '"', ''), explode("\n", trim(substr($csv, 3))));
            foreach ($lines as $line) {
                $found = collect($rows)->contains(fn (array $row) => array_slice($row, 0, count($line)) === $line);
                $this->assertTrue($found, "{$tab}: ".implode(',', $line));
            }
        }

        $this->actingAs($this->admin)->get("/workspaces/{$this->workspace->slug}/insights/nope/export")->assertNotFound();
    }
}
