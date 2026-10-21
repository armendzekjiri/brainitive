<?php

namespace Tests\Feature\Plans;

use App\Models\Plan;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Plans\PlanTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PlanTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Project $project;

    private User $editor;

    private User $otherEditor;

    private User $viewer;

    private User $admin;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Notification::fake();

        $owner = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $this->workspace->addUser($owner, 'admin');
        $this->project = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $owner->id]);

        $this->editor = $this->member('editor');
        $this->otherEditor = $this->member('editor');
        $this->viewer = $this->member('viewer');
        $this->admin = $this->member('admin');
        $this->outsider = User::factory()->create();
    }

    public function test_editors_create_plans_from_a_template_with_unique_slugs(): void
    {
        $this->actingAs($this->editor)
            ->post($this->projectRoute('plans.store'), ['name' => 'Roadmap 2027', 'template' => 'project'])
            ->assertRedirect($this->planUrl('roadmap-2027'));

        $this->actingAs($this->editor)
            ->post($this->projectRoute('plans.store'), ['name' => 'Roadmap 2027'])
            ->assertRedirect($this->planUrl('roadmap-2027-1'));

        $plan = Plan::where('slug', 'roadmap-2027')->firstOrFail();
        $this->assertSame('Initiation', $plan->data['rows'][0]['name']);
        $this->assertNotEmpty($plan->data['dependencies']);
        $this->assertSame('week', $plan->settings['zoom']);
        $this->assertSame(1, $plan->current_version);
        $this->assertSame(2, $this->project->plans()->count());
    }

    public function test_viewers_and_outsiders_cannot_create_plans_and_unknown_templates_are_rejected(): void
    {
        $this->actingAs($this->viewer)->post($this->projectRoute('plans.store'), ['name' => 'X'])->assertForbidden();
        $this->actingAs($this->outsider)->post($this->projectRoute('plans.store'), ['name' => 'X'])->assertForbidden();
        $this->actingAs($this->editor)->post($this->projectRoute('plans.store'), ['name' => 'X', 'template' => 'nope'])->assertSessionHasErrors('template');
    }

    public function test_every_template_builds_a_valid_document_starting_this_week(): void
    {
        foreach (PlanTemplates::all() as $template) {
            $data = PlanTemplates::build($template['key']);
            $this->assertNotEmpty($data['rows'], $template['key']);
            $this->assertSame(0, $data['rows'][0]['level']);

            $starts = collect($data['rows'])->flatMap(fn ($row) => $row['bars'])->pluck('start');
            if ($starts->isNotEmpty()) {
                $this->assertSame(now()->startOfWeek()->toDateString(), $starts->min(), $template['key']);
            }
        }
    }

    public function test_members_see_the_planner_and_outsiders_do_not(): void
    {
        $plan = $this->plan();

        $this->actingAs($this->viewer)
            ->get($this->planUrl($plan->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Plans/Show')
                ->where('plan.name', $plan->name)
                ->where('plan.edit_revision', 0)
                ->has('plan.data.rows', 4)
                ->where('can.update', false)
                ->where('can.delete', false)
                ->has('members'));

        $this->actingAs($this->outsider)->get($this->planUrl($plan->slug))->assertForbidden();
    }

    public function test_save_stores_the_document_and_bumps_the_revision(): void
    {
        $plan = $this->plan();

        $this->actingAs($this->editor)
            ->putJson($this->planRoute('plans.save', $plan), $this->document([$this->row('a', 'Kick-off', 0, [$this->bar('x', '2026-11-02', '2026-11-06')])]) + ['base_revision' => 0])
            ->assertOk()
            ->assertJson(['edit_revision' => 1]);

        $plan->refresh();
        $this->assertSame('Kick-off', $plan->data['rows'][0]['name']);
        $this->assertSame('2026-11-06', $plan->data['rows'][0]['bars'][0]['end']);
        $this->assertSame($this->editor->id, $plan->last_edited_by);
        $this->assertSame('day', $plan->settings['zoom']);
    }

    public function test_a_stale_revision_gets_a_conflict_unless_forced(): void
    {
        $plan = $this->plan();
        $plan->saveDocument($this->otherEditor, Plan::emptyData(), []);

        $this->actingAs($this->editor)
            ->putJson($this->planRoute('plans.save', $plan), $this->document([$this->row('a', 'Mine')]) + ['base_revision' => 0])
            ->assertStatus(409)
            ->assertJsonPath('conflict.updated_by.name', $this->otherEditor->name)
            ->assertJsonPath('conflict.edit_revision', 1);
        $this->assertSame([], $plan->fresh()->data['rows']);

        $this->actingAs($this->editor)
            ->putJson($this->planRoute('plans.save', $plan), $this->document([$this->row('a', 'Mine')]) + ['base_revision' => 0, 'force' => true])
            ->assertOk();
        $this->assertSame('Mine', $plan->fresh()->data['rows'][0]['name']);
    }

    public function test_save_normalizes_the_document(): void
    {
        $plan = $this->plan();
        $rows = [
            $this->row('a', 'Group', 0, [$this->bar('x', '2026-11-10', '2026-11-02')]),
            $this->row('b', 'Too deep', 4, [$this->bar('m', '2026-11-12', '2026-11-20', 'milestone')]),
            $this->row('a', 'Duplicate row id'),
        ];
        $dependencies = [
            ['id' => 'd1', 'from' => 'x', 'to' => 'm'],
            ['id' => 'd2', 'from' => 'x', 'to' => 'missing'],
            ['id' => 'd3', 'from' => 'x', 'to' => 'x'],
            ['id' => 'd4', 'from' => 'x', 'to' => 'm'],
        ];

        $this->actingAs($this->editor)
            ->putJson($this->planRoute('plans.save', $plan), $this->document($rows, $dependencies))
            ->assertOk();

        $data = $plan->fresh()->data;
        $this->assertCount(2, $data['rows']);
        $this->assertSame(['2026-11-02', '2026-11-10'], [$data['rows'][0]['bars'][0]['start'], $data['rows'][0]['bars'][0]['end']]);
        $this->assertSame(1, $data['rows'][1]['level']);
        $this->assertSame('2026-11-12', $data['rows'][1]['bars'][0]['end']);
        $this->assertSame([['id' => 'd1', 'from' => 'x', 'to' => 'm']], $data['dependencies']);
    }

    public function test_save_rejects_invalid_documents_and_viewers(): void
    {
        $plan = $this->plan();

        $this->actingAs($this->editor)
            ->putJson($this->planRoute('plans.save', $plan), $this->document([$this->row('a', 'Bad', 0, [$this->bar('x', '02.11.2026', '2026-11-06')])]))
            ->assertJsonValidationErrors('data.rows.0.bars.0.start');

        $this->actingAs($this->editor)
            ->putJson($this->planRoute('plans.save', $plan), $this->document([$this->row('a', 'Bad', 0, [array_merge($this->bar('x', '2026-11-02', '2026-11-06'), ['color' => 'red'])])]))
            ->assertJsonValidationErrors('data.rows.0.bars.0.color');

        $this->actingAs($this->viewer)
            ->putJson($this->planRoute('plans.save', $plan), $this->document([]))
            ->assertForbidden();
    }

    public function test_checkpoints_follow_the_interval_explicit_saves_and_other_users(): void
    {
        $plan = $this->plan();
        $this->assertNotNull($plan->saveDocument($this->editor, Plan::emptyData(), []));
        $this->assertNull($plan->fresh()->saveDocument($this->editor, Plan::emptyData(), []));
        $this->assertNotNull($plan->fresh()->saveDocument($this->editor, Plan::emptyData(), [], checkpoint: true));
        $this->assertNotNull($plan->fresh()->saveDocument($this->otherEditor, Plan::emptyData(), []));

        $this->travel(Plan::VERSION_INTERVAL_MINUTES + 1)->minutes();
        $this->assertNotNull($plan->fresh()->saveDocument($this->otherEditor, Plan::emptyData(), []));
        $this->assertSame(4, $plan->versions()->count());
    }

    public function test_history_lists_versions_and_restore_brings_an_old_document_back(): void
    {
        $plan = $this->plan();
        $original = $plan->data;
        $plan->recordVersion($this->editor, 'Created');
        $plan->saveDocument($this->editor, Plan::emptyData(), [], checkpoint: true);

        $this->actingAs($this->viewer)
            ->getJson($this->planRoute('plans.versions.index', $plan))
            ->assertOk()
            ->assertJsonPath('current_version', 2)
            ->assertJsonCount(2, 'versions')
            ->assertJsonPath('versions.1.message', 'Created');

        $this->actingAs($this->viewer)
            ->getJson($this->planRoute('plans.versions.show', $plan, [1]))
            ->assertOk()
            ->assertJsonCount(4, 'data.rows');

        $this->actingAs($this->viewer)->postJson($this->planRoute('plans.versions.restore', $plan, [1]))->assertForbidden();

        $this->actingAs($this->editor)
            ->postJson($this->planRoute('plans.versions.restore', $plan, [1]))
            ->assertOk()
            ->assertJsonPath('restored_as', 3)
            ->assertJsonCount(4, 'data.rows');

        $this->assertSame($original, $plan->fresh()->data);
        $this->assertSame('Restored from version 1', $plan->versions()->first()->message);
    }

    public function test_rename_keeps_the_slug(): void
    {
        $plan = $this->plan();

        $this->actingAs($this->editor)
            ->patch($this->planRoute('plans.update', $plan), ['name' => 'Renamed', 'description' => 'Q1'])
            ->assertRedirect();

        $plan->refresh();
        $this->assertSame('Renamed', $plan->name);
        $this->assertSame('Q1', $plan->description);
        $this->assertSame($plan->getOriginal('slug'), $plan->slug);
    }

    public function test_duplicate_copies_the_document(): void
    {
        $plan = $this->plan(['name' => 'Launch', 'slug' => 'launch']);

        $this->actingAs($this->editor)
            ->post($this->planRoute('plans.duplicate', $plan))
            ->assertRedirect($this->planUrl('launch-copy'));

        $copy = Plan::where('slug', 'launch-copy')->firstOrFail();
        $this->assertSame('Launch (copy)', $copy->name);
        $this->assertSame($plan->data, $copy->data);
        $this->assertNull($copy->share_token);
    }

    public function test_only_admins_delete_plans(): void
    {
        $plan = $this->plan();

        $this->actingAs($this->editor)->delete($this->planRoute('plans.destroy', $plan))->assertForbidden();
        $this->actingAs($this->admin)->delete($this->planRoute('plans.destroy', $plan))->assertRedirect();

        $this->assertSoftDeleted($plan);
    }

    public function test_share_link_shows_a_read_only_plan_until_it_is_turned_off_or_replaced(): void
    {
        $plan = $this->plan();

        $this->actingAs($this->viewer)->postJson($this->planRoute('plans.share', $plan))->assertForbidden();

        $url = $this->actingAs($this->editor)->postJson($this->planRoute('plans.share', $plan))->assertOk()->json('share_url');
        auth()->logout();

        $this->get($url)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Plans/Public')
                ->where('plan.name', $plan->name)
                ->has('plan.data.rows', 4)
                ->missing('plan.share_token')
                ->missing('plan.id'));

        $newUrl = $this->actingAs($this->editor)->postJson($this->planRoute('plans.share', $plan))->json('share_url');
        $this->assertNotSame($url, $newUrl);
        auth()->logout();
        $this->get($url)->assertNotFound();

        $this->actingAs($this->editor)->deleteJson($this->planRoute('plans.unshare', $plan))->assertOk()->assertJson(['share_url' => null]);
        auth()->logout();
        $this->get($newUrl)->assertNotFound();
    }

    public function test_load_latest_returns_the_current_document(): void
    {
        $plan = $this->plan();
        $plan->saveDocument($this->otherEditor, Plan::emptyData(), ['zoom' => 'month']);

        $this->actingAs($this->editor)
            ->getJson($this->planRoute('plans.document', $plan))
            ->assertOk()
            ->assertJson(['edit_revision' => 1, 'settings' => ['zoom' => 'month'], 'last_edited_by' => ['name' => $this->otherEditor->name]]);
    }

    public function test_plans_show_in_the_sidebar_and_on_the_project_page(): void
    {
        $plan = $this->plan();

        $this->actingAs($this->viewer)
            ->get(route('projects.show', [$this->workspace->slug, $this->project->slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('project.plans.0.slug', $plan->slug)
                ->where('currentWorkspace.projects.0.plans.0.name', $plan->name));
    }

    private function member(string $projectRole): User
    {
        $user = User::factory()->create();
        $this->workspace->addUser($user, 'member');
        $this->project->users()->attach($user->id, ['role' => $projectRole]);

        return $user;
    }

    private function plan(array $attributes = []): Plan
    {
        return Plan::factory()->create($attributes + ['project_id' => $this->project->id, 'created_by' => $this->editor->id]);
    }

    private function projectRoute(string $name): string
    {
        return route($name, [$this->workspace->slug, $this->project->slug]);
    }

    private function planUrl(string $slug): string
    {
        return route('plans.show', [$this->workspace->slug, $this->project->slug, $slug]);
    }

    /**
     * @param  array<int, mixed>  $extra
     */
    private function planRoute(string $name, Plan $plan, array $extra = []): string
    {
        return route($name, [$this->workspace->slug, $this->project->slug, $plan->slug, ...$extra]);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, string>>  $dependencies
     * @return array{data: array<string, mixed>, settings: array<string, mixed>}
     */
    private function document(array $rows, array $dependencies = []): array
    {
        return ['data' => ['rows' => $rows, 'dependencies' => $dependencies], 'settings' => ['zoom' => 'day']];
    }

    /**
     * @param  list<array<string, mixed>>  $bars
     * @return array<string, mixed>
     */
    private function row(string $id, string $name, int $level = 0, array $bars = []): array
    {
        return ['id' => $id, 'name' => $name, 'level' => $level, 'bars' => $bars];
    }

    /**
     * @return array<string, mixed>
     */
    private function bar(string $id, string $start, string $end, string $type = 'bar'): array
    {
        return ['id' => $id, 'start' => $start, 'end' => $end, 'type' => $type, 'color' => '#0E83DC'];
    }
}
