<?php

namespace Tests\Feature\Automation;

use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Board;
use App\Models\Item;
use App\Models\Project;
use App\Models\User;
use App\Support\Automation\AutomationCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * BRAI-63: managing rules (project admins only), validation scoped to the project, versioning,
 * the on/off switch and the "Test on an issue" dry run, which writes nothing.
 */
class AutomationRulesTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        $this->admin = $this->projectMember('admin');
    }

    private function url(string $name, array $extra = []): string
    {
        return route($name, [$this->workspace->slug, $this->project->slug, ...$extra]);
    }

    private function definition(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Triage new issues',
            'trigger' => 'item.created',
            'trigger_config' => [],
            'conditions' => [['type' => 'board', 'value' => $this->board->id]],
            'actions' => [['type' => 'assign', 'value' => $this->editor->id]],
        ];
    }

    private function storedRule(array $attributes = []): AutomationRule
    {
        return AutomationRule::create($attributes + [
            'project_id' => $this->project->id,
            'name' => 'Existing',
            'enabled' => false,
            'version' => 1,
            'actor_id' => $this->admin->id,
            'trigger' => 'item.created',
            'trigger_config' => [],
            'conditions' => [],
            'actions' => [['type' => 'assign', 'value' => $this->editor->id, 'text' => null]],
        ]);
    }

    public function test_a_project_admin_creates_a_rule_that_starts_switched_off(): void
    {
        $this->actingAs($this->admin)->post($this->url('automations.store'), $this->definition())
            ->assertSessionHasNoErrors()->assertRedirect();

        $rule = AutomationRule::sole();
        $this->assertFalse($rule->enabled);
        $this->assertSame([1, $this->admin->id, $this->project->id], [$rule->version, $rule->actor_id, $rule->project_id]);
        $this->assertSame([['type' => 'board', 'value' => $this->board->id]], $rule->conditions);
        $this->assertSame([['type' => 'assign', 'value' => $this->editor->id, 'text' => null]], $rule->actions);

        // switched off: an issue changes nothing
        $this->item();
        $this->assertSame(0, AutomationRun::count());
    }

    public function test_pages_render_for_admins(): void
    {
        $rule = $this->storedRule();
        AutomationRun::create(['automation_rule_id' => $rule->id, 'project_id' => $this->project->id, 'rule_version' => 1, 'event_id' => 'x', 'trigger' => 'item.created', 'subject_type' => 'item', 'subject_id' => 1, 'subject_label' => 'BRAI-1 Hello', 'status' => 'success']);

        $this->actingAs($this->admin)->get($this->url('automations.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Automations/Index')
                ->where('rules.0.name', 'Existing')
                ->where('rules.0.summary.then.0', "Assign to {$this->editor->name}")
                ->where('rules.0.actor.id', $this->admin->id)
                ->where('runs.0.subject', 'BRAI-1 Hello')
                ->where('runs.0.rule.name', 'Existing'));

        $this->actingAs($this->admin)->get($this->url('automations.create'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Automations/Edit')->where('rule', null)
                ->has('catalog.triggers', count(AutomationCatalog::TRIGGERS))
                ->where('options.boards.0.id', $this->board->id));

        $this->actingAs($this->admin)->get($this->url('automations.edit', [$rule->id]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Automations/Edit')->where('rule.id', $rule->id)->has('runs', 1));

        $this->assertFileExists(resource_path('js/Pages/Automations/Index.tsx'));
        $this->assertFileExists(resource_path('js/Pages/Automations/Edit.tsx'));
    }

    public function test_members_and_outsiders_cannot_manage_rules(): void
    {
        $rule = $this->storedRule();

        foreach ([$this->editor, $this->viewer, $this->outsider] as $user) {
            $this->actingAs($user)->get($this->url('automations.index'))->assertForbidden();
            $this->actingAs($user)->get($this->url('automations.edit', [$rule->id]))->assertForbidden();
            $this->actingAs($user)->post($this->url('automations.store'), $this->definition())->assertForbidden();
            $this->actingAs($user)->put($this->url('automations.update', [$rule->id]), $this->definition())->assertForbidden();
            $this->actingAs($user)->patch($this->url('automations.toggle', [$rule->id]), ['enabled' => true])->assertForbidden();
            $this->actingAs($user)->postJson($this->url('automations.preview'), $this->definition(['subject_key' => 'BRAI-1']))->assertForbidden();
            $this->actingAs($user)->delete($this->url('automations.destroy', [$rule->id]))->assertForbidden();
        }

        $this->assertFalse($rule->fresh()->enabled);
        $this->assertSame(1, AutomationRule::count());
    }

    public function test_a_rule_of_another_project_does_not_bind(): void
    {
        $other = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->admin->id]);
        $foreign = $this->storedRule(['project_id' => $other->id]);

        $this->actingAs($this->admin)->get($this->url('automations.edit', [$foreign->id]))->assertNotFound();
        $this->actingAs($this->admin)->patch($this->url('automations.toggle', [$foreign->id]), ['enabled' => true])->assertNotFound();
    }

    public function test_ids_must_belong_to_the_project_and_fit_the_trigger(): void
    {
        $foreignBoard = Board::factory()->create();
        $foreignUser = User::factory()->create();
        $store = fn (array $definition) => $this->actingAs($this->admin)->post($this->url('automations.store'), $definition);

        $store($this->definition(['conditions' => [['type' => 'board', 'value' => $foreignBoard->id]]]))->assertSessionHasErrors('conditions.0.value');
        $store($this->definition(['actions' => [['type' => 'assign', 'value' => $foreignUser->id]]]))->assertSessionHasErrors('actions.0.value');
        $store($this->definition(['actions' => [['type' => 'move', 'value' => $foreignBoard->columns()->first()->id]]]))->assertSessionHasErrors('actions.0.value');
        $store($this->definition(['actions' => []]))->assertSessionHasErrors('actions');
        $store($this->definition(['actions' => array_fill(0, AutomationCatalog::MAX_ACTIONS + 1, ['type' => 'assign', 'value' => $this->editor->id])]))->assertSessionHasErrors('actions');
        $store($this->definition(['actions' => [['type' => 'comment', 'text' => '  ']]]))->assertSessionHasErrors('actions.0.text');
        $store($this->definition(['actions' => [['type' => 'set_due_date', 'value' => 400]]]))->assertSessionHasErrors('actions.0.value');
        $store($this->definition(['trigger' => 'item.moved', 'trigger_config' => ['column_id' => $foreignBoard->columns()->first()->id]]))->assertSessionHasErrors('trigger_config.column_id');
        // a ticket action on an issue trigger, an issue condition on a ticket trigger
        $store($this->definition(['actions' => [['type' => 'create_issue', 'value' => $this->board->id]]]))->assertSessionHasErrors('actions.0.type');
        $store($this->definition(['trigger' => 'ticket.created']))->assertSessionHasErrors('conditions.0.type');
        $store($this->definition(['trigger' => 'webhook.sent']))->assertSessionHasErrors('trigger');

        $this->assertSame(0, AutomationRule::count());
    }

    public function test_saving_a_changed_definition_bumps_the_version_and_takes_over_the_actor(): void
    {
        $rule = $this->storedRule(['actor_id' => $this->editor->id]);
        $owner = $this->admin;

        $this->actingAs($owner)->put($this->url('automations.update', [$rule->id]), $this->definition(['name' => 'Renamed', 'conditions' => [], 'actions' => [['type' => 'assign', 'value' => $this->editor->id]]]))
            ->assertSessionHasNoErrors();
        $rule->refresh();
        $this->assertSame(['Renamed', 1, $owner->id], [$rule->name, $rule->version, $rule->actor_id], 'a rename is not a new version');

        $this->actingAs($owner)->put($this->url('automations.update', [$rule->id]), $this->definition(['name' => 'Renamed', 'actions' => [['type' => 'assign', 'value' => 'reporter']]]))
            ->assertSessionHasNoErrors();
        $this->assertSame(2, $rule->fresh()->version);
    }

    public function test_the_switch_turns_a_rule_on_and_off_and_delete_removes_it_with_its_runs(): void
    {
        $rule = $this->storedRule();

        $this->actingAs($this->admin)->patch($this->url('automations.toggle', [$rule->id]), ['enabled' => true])->assertSessionHasNoErrors();
        $this->assertTrue($rule->fresh()->enabled);

        $item = $this->item();
        $this->assertSame($this->editor->id, $item->fresh()->assigned_to);
        $this->assertSame(1, AutomationRun::count());

        $this->actingAs($this->admin)->patch($this->url('automations.toggle', [$rule->id]), ['enabled' => false]);
        $this->assertFalse($rule->fresh()->enabled);

        $this->actingAs($this->admin)->delete($this->url('automations.destroy', [$rule->id]))->assertRedirect($this->url('automations.index'));
        $this->assertSame(0, AutomationRule::count());
        $this->assertSame(0, AutomationRun::count());
    }

    public function test_the_dry_run_explains_the_outcome_and_writes_nothing(): void
    {
        $item = $this->item(['title' => 'Checkout broken']);
        $definition = $this->definition([
            'conditions' => [['type' => 'title_contains', 'value' => 'checkout'], ['type' => 'assignee_empty']],
            'actions' => [
                ['type' => 'assign', 'value' => $this->editor->id],
                ['type' => 'move', 'value' => $this->column('done')],
                ['type' => 'comment', 'text' => 'Looking at {{item.key}}'],
                ['type' => 'notify', 'value' => 'assignee'],
            ],
        ]);

        $writes = 0;
        DB::listen(function ($query) use (&$writes) {
            // (instance_settings is created lazily by the first request of a fresh database)
            if (preg_match('/^\s*(insert|update|delete)/i', $query->sql) && ! str_contains($query->sql, '"instance_settings"')) {
                $writes++;
            }
        });

        $response = $this->actingAs($this->admin)->postJson($this->url('automations.preview'), $definition + ['subject_key' => strtolower($item->key)])
            ->assertOk()
            ->assertJsonPath('subject', "{$item->key} Checkout broken")
            ->assertJsonPath('matches', true)
            ->assertJsonPath('conditions.0.label', 'Title contains "checkout"')
            ->assertJsonPath('actions.0.message', "Assigned to {$this->editor->name}.")
            ->assertJsonPath('actions.1.message', 'Moved to Done.')
            ->assertJsonPath('actions.2.message', "Comment added: \"Looking at {$item->key}\"")
            ->assertJsonPath('actions.3.status', 'skipped');

        $this->assertSame(0, $writes, 'the dry run only reads');
        $this->assertNull($item->fresh()->assigned_to);
        $this->assertSame(0, AutomationRule::count());
        $this->assertSame(0, AutomationRun::count());
        $this->assertSame(['ok', 'ok', 'ok', 'skipped'], array_column($response->json('actions'), 'status'));

        $this->actingAs($this->admin)->postJson($this->url('automations.preview'), $definition + ['subject_key' => 'NOPE-1'])
            ->assertStatus(422)->assertJsonValidationErrors('subject_key');
    }

    public function test_the_dry_run_cannot_read_issues_of_other_projects(): void
    {
        $otherBoard = Board::factory()->create();
        $foreign = Item::factory()->create(['board_id' => $otherBoard->id, 'key' => 'BRAI-999']);

        $this->actingAs($this->admin)->postJson($this->url('automations.preview'), $this->definition(['conditions' => []]) + ['subject_key' => $foreign->key])
            ->assertStatus(422)->assertJsonValidationErrors('subject_key');
    }

    public function test_settings_and_the_project_page_offer_automations_to_admins_only(): void
    {
        $this->actingAs($this->admin)->get(route('projects.show', [$this->workspace->slug, $this->project->slug]))
            ->assertInertia(fn (Assert $page) => $page->where('canUpdate', true));
        $this->actingAs($this->editor)->get(route('projects.show', [$this->workspace->slug, $this->project->slug]))
            ->assertInertia(fn (Assert $page) => $page->where('canUpdate', false));
    }
}
