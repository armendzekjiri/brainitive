<?php

namespace Tests\Feature\Plans;

use App\Models\Board;
use App\Models\Item;
use App\Models\Plan;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PlanIssueLinkTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Project $project;

    private Board $board;

    private User $editor;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Notification::fake();

        $owner = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $this->workspace->addUser($owner, 'admin');
        $this->project = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $owner->id, 'key' => 'WEB']);
        $this->board = Board::factory()->create(['project_id' => $this->project->id, 'created_by' => $owner->id, 'name' => 'Delivery']);

        $this->editor = $this->member('editor', 'Nora Imeri');
        $this->viewer = $this->member('viewer', 'Vera Viewer');
    }

    public function test_rows_become_issues_with_dates_and_a_matching_assignee(): void
    {
        $plan = $this->plan();

        $response = $this->actingAs($this->editor)
            ->postJson($this->planRoute('plans.issues.store', $plan), [
                'board_id' => $this->board->id,
                'rows' => [
                    ['title' => 'Wireframes', 'start_date' => '2026-11-02', 'due_date' => '2026-11-13', 'who' => ' nora imeri '],
                    ['title' => 'Launch', 'due_date' => '2026-12-04', 'who' => 'Somebody external'],
                ],
            ])
            ->assertCreated()
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.title', 'Wireframes')
            ->assertJsonPath('items.0.start_date', '2026-11-02')
            ->assertJsonPath('items.0.due_date', '2026-11-13')
            ->assertJsonPath('items.0.assignee.name', 'Nora Imeri')
            ->assertJsonPath('items.0.status_category', 'todo')
            ->assertJsonPath('items.1.title', 'Launch')
            ->assertJsonPath('items.1.assignee', null);

        $items = Item::orderBy('id')->get();
        $this->assertCount(2, $items->pluck('key')->unique());
        $this->assertTrue($items->every(fn (Item $item) => str_starts_with($item->key, 'WEB-')));
        $this->assertNull($items[1]->start_date);
        $this->assertSame('2026-12-04', $items[1]->due_date->toDateString());
        $this->assertTrue($items->every(fn (Item $item) => $item->sprint_id === null && $item->column_id === $this->board->columns()->value('id')));
        $this->assertStringContainsString("/items/{$items[0]->id}", $response->json('items.0.url'));
    }

    public function test_creating_issues_needs_edit_rights_valid_dates_and_a_board_of_the_project(): void
    {
        $plan = $this->plan();
        $foreignBoard = Board::factory()->create();

        $this->actingAs($this->viewer)
            ->postJson($this->planRoute('plans.issues.store', $plan), ['board_id' => $this->board->id, 'rows' => [['title' => 'A']]])
            ->assertForbidden();

        $this->actingAs($this->editor)
            ->postJson($this->planRoute('plans.issues.store', $plan), ['board_id' => $foreignBoard->id, 'rows' => [['title' => 'A']]])
            ->assertJsonValidationErrors('board_id');

        $this->actingAs($this->editor)
            ->postJson($this->planRoute('plans.issues.store', $plan), ['board_id' => $this->board->id, 'rows' => [['title' => 'A', 'start_date' => '2026-11-10', 'due_date' => '2026-11-02']]])
            ->assertJsonValidationErrors('rows.0.due_date');

        $this->assertSame(0, Item::count());
    }

    public function test_issue_search_and_refresh_stay_inside_the_project(): void
    {
        $plan = $this->plan();
        $mine = $this->item(['title' => 'Homepage hero', 'original_estimate' => 600, 'time_spent' => 300]);
        $foreignBoard = Board::factory()->create();
        $foreign = Item::factory()->create(['board_id' => $foreignBoard->id, 'project_id' => $foreignBoard->project_id, 'title' => 'Homepage elsewhere']);

        $this->actingAs($this->viewer)
            ->getJson($this->planRoute('plans.issues', $plan).'?q=Homepage')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $mine->id)
            ->assertJsonPath('items.0.progress', 50);

        $this->actingAs($this->viewer)
            ->getJson($this->planRoute('plans.issues', $plan).'?'.http_build_query(['ids' => [$mine->id, $foreign->id]]))
            ->assertOk()
            ->assertJsonCount(1, 'items');
    }

    public function test_the_planner_gets_the_linked_issues_and_the_boards_to_create_on(): void
    {
        $item = $this->item(['title' => 'Login', 'column_id' => $this->board->columns()->where('is_done_column', true)->value('id')]);
        $plan = $this->plan(['data' => $this->data([$this->row('r1', 'Login', ['itemId' => $item->id])])]);

        $this->actingAs($this->viewer)
            ->get($this->planRoute('plans.show', $plan))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where("issues.{$item->id}.key", $item->key)
                ->where("issues.{$item->id}.status_category", 'done')
                ->where("issues.{$item->id}.progress", 100)
                ->where('boards', []));

        $this->actingAs($this->editor)
            ->get($this->planRoute('plans.show', $plan))
            ->assertInertia(fn (Assert $page) => $page->where('boards', [['id' => $this->board->id, 'name' => 'Delivery']]));
    }

    public function test_renaming_or_moving_a_linked_row_updates_the_issue(): void
    {
        $item = $this->item(['title' => 'Wireframes', 'start_date' => '2026-11-02', 'due_date' => '2026-11-06']);
        $plan = $this->plan(['data' => $this->data([$this->row('r1', 'Wireframes', ['itemId' => $item->id], [$this->bar('b1', '2026-11-02', '2026-11-06')])])]);

        $this->save($plan, [$this->row('r1', 'Wireframes v2', ['itemId' => $item->id], [$this->bar('b1', '2026-11-09', '2026-11-20')])])
            ->assertOk();

        $item->refresh();
        $this->assertSame('Wireframes v2', $item->title);
        $this->assertSame('2026-11-09', $item->start_date->toDateString());
        $this->assertSame('2026-11-20', $item->due_date->toDateString());
        $this->assertSame($item->id, $plan->fresh()->data['rows'][0]['itemId']);
    }

    public function test_a_lone_milestone_is_the_due_date_and_removing_bars_clears_the_dates(): void
    {
        $item = $this->item(['start_date' => '2026-11-02', 'due_date' => '2026-11-06']);
        $plan = $this->plan(['data' => $this->data([$this->row('r1', $item->title, ['itemId' => $item->id], [$this->bar('b1', '2026-11-02', '2026-11-06')])])]);

        $this->save($plan, [$this->row('r1', $item->title, ['itemId' => $item->id], [$this->bar('m1', '2026-12-01', '2026-12-01', 'milestone')])])->assertOk();
        $item->refresh();
        $this->assertNull($item->start_date);
        $this->assertSame('2026-12-01', $item->due_date->toDateString());

        $this->save($plan->fresh(), [$this->row('r1', $item->title, ['itemId' => $item->id])])->assertOk();
        $this->assertNull($item->fresh()->due_date);
    }

    public function test_saving_unchanged_rows_never_reverts_a_newer_board_change(): void
    {
        $item = $this->item(['title' => 'Wireframes', 'start_date' => '2026-11-02', 'due_date' => '2026-11-06']);
        $linked = $this->row('r1', 'Wireframes', ['itemId' => $item->id], [$this->bar('b1', '2026-11-02', '2026-11-06')]);
        $plan = $this->plan(['data' => $this->data([$linked])]);

        // Someone moves the issue on the board; the plan still has the old dates
        $item->update(['title' => 'Wireframes (mobile)', 'due_date' => '2026-11-13']);

        $this->save($plan, [$linked, $this->row('r2', 'Unrelated edit')])->assertOk();

        $item->refresh();
        $this->assertSame('Wireframes (mobile)', $item->title);
        $this->assertSame('2026-11-13', $item->due_date->toDateString());
    }

    public function test_links_to_other_projects_and_duplicate_links_are_dropped(): void
    {
        $mine = $this->item();
        $foreignBoard = Board::factory()->create();
        $foreign = Item::factory()->create(['board_id' => $foreignBoard->id, 'project_id' => $foreignBoard->project_id, 'title' => 'Not yours']);
        $plan = $this->plan();

        $this->save($plan, [
            $this->row('r1', 'Mine', ['itemId' => $mine->id]),
            $this->row('r2', 'Again', ['itemId' => $mine->id]),
            $this->row('r3', 'Foreign', ['itemId' => $foreign->id]),
        ])->assertOk();

        $rows = $plan->fresh()->data['rows'];
        $this->assertSame($mine->id, $rows[0]['itemId']);
        $this->assertArrayNotHasKey('itemId', $rows[1]);
        $this->assertArrayNotHasKey('itemId', $rows[2]);
        $this->assertSame('Not yours', $foreign->fresh()->title);
    }

    public function test_write_through_respects_item_permissions(): void
    {
        $item = $this->item(['title' => 'Locked']);
        $plan = $this->plan(['data' => $this->data([$this->row('r1', 'Locked', ['itemId' => $item->id])])]);
        // Workspace editor who is only a project viewer: can edit the plan (workspace role) but not
        // this item (item edits need a project editor role). Without project access they can't do either.
        $workspaceEditor = User::factory()->create();
        $this->workspace->addUser($workspaceEditor, 'editor');
        $this->project->users()->attach($workspaceEditor->id, ['role' => 'viewer']);

        $this->actingAs($workspaceEditor)
            ->putJson($this->planRoute('plans.save', $plan), ['data' => $this->data([$this->row('r1', 'Renamed', ['itemId' => $item->id])])])
            ->assertOk();

        $this->assertFalse($workspaceEditor->can('update', $item));
        $this->assertSame('Locked', $item->fresh()->title);
        $this->assertSame('Renamed', $plan->fresh()->data['rows'][0]['name']);
    }

    private function member(string $projectRole, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        $this->workspace->addUser($user, 'member');
        $this->project->users()->attach($user->id, ['role' => $projectRole]);

        return $user;
    }

    private function plan(array $attributes = []): Plan
    {
        return Plan::factory()->create($attributes + ['project_id' => $this->project->id, 'created_by' => $this->editor->id]);
    }

    private function item(array $attributes = []): Item
    {
        return Item::factory()->create($attributes + [
            'board_id' => $this->board->id,
            'project_id' => $this->project->id,
            'column_id' => $this->board->columns()->value('id'),
            'created_by' => $this->editor->id,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function save(Plan $plan, array $rows)
    {
        return $this->actingAs($this->editor)->putJson($this->planRoute('plans.save', $plan), ['data' => $this->data($rows)]);
    }

    private function planRoute(string $name, Plan $plan): string
    {
        return route($name, [$this->workspace->slug, $this->project->slug, $plan->slug]);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{rows: list<array<string, mixed>>, dependencies: list<mixed>}
     */
    private function data(array $rows): array
    {
        return ['rows' => $rows, 'dependencies' => []];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @param  list<array<string, mixed>>  $bars
     * @return array<string, mixed>
     */
    private function row(string $id, string $name, array $extra = [], array $bars = []): array
    {
        return ['id' => $id, 'name' => $name, 'level' => 0, 'bars' => $bars] + $extra;
    }

    /**
     * @return array<string, mixed>
     */
    private function bar(string $id, string $start, string $end, string $type = 'bar'): array
    {
        return ['id' => $id, 'start' => $start, 'end' => $end, 'type' => $type, 'color' => '#0E83DC'];
    }
}
