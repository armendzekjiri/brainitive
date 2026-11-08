<?php

namespace Tests\Feature\Boards;

use App\Mcp\Servers\BrainitiveServer;
use App\Mcp\Tools\DeleteBoardColumnTool;
use App\Models\ActivityLog;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\BoardColumnRule;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Admin\UserDeletion;
use App\Support\Boards\WorkflowGuard;
use App\Support\Boards\WorkflowRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Board settings → Workflow rules (v1.52.0): who may edit them, what is accepted, history and cleanup. */
class WorkflowRuleSettingsTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    private User $max;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        $this->max = $this->projectMember('editor');
        $this->admin = $this->projectMember('admin');
    }

    private function url(string $slug, ?Board $board = null): string
    {
        $board ??= $this->board;

        return route('boards.columns.rule.update', [$this->workspace->slug, $this->project->slug, $board->slug, $board->columns()->where('slug', $slug)->value('id')]);
    }

    /** @param array<string, mixed> $data */
    private function save(string $slug, array $data, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->admin)
            ->from(route('boards.edit', [$this->workspace->slug, $this->project->slug, $this->board->slug]))
            ->put($this->url($slug), $data + [
                'allowed_user_ids' => [], 'allowed_roles' => [], 'allowed_from_column_ids' => [],
                'require_assignee' => false, 'require_subtasks_done' => false, 'require_due_date' => false,
            ]);
    }

    private function doneRule(): ?BoardColumnRule
    {
        return BoardColumnRule::with(['users', 'fromColumns'])->where('board_column_id', $this->column('done'))->first();
    }

    private function deleteColumn(BoardColumn $column, User $user)
    {
        return $this->actingAs($user)->from(route('boards.edit', [$this->workspace->slug, $this->project->slug, $this->board->slug]))
            ->delete(route('boards.columns.destroy', [$this->workspace->slug, $this->project->slug, $this->board->slug, $column->id]));
    }

    public function test_project_admins_save_a_rule_and_an_empty_rule_removes_it(): void
    {
        $this->save('done', [
            'allowed_user_ids' => [$this->max->id],
            'allowed_roles' => ['project_admin'],
            'allowed_from_column_ids' => [$this->column('in-progress'), $this->column('done')], // itself is dropped
            'require_subtasks_done' => true,
        ])->assertSessionHasNoErrors()->assertSessionHas('success', 'Workflow rule for Done saved.');

        $rule = $this->doneRule();
        $this->assertSame([$this->max->id], $rule->users->pluck('id')->all());
        $this->assertSame(['project_admin'], $rule->allowed_roles);
        $this->assertTrue($rule->restrict_who);
        $this->assertSame([$this->column('in-progress')], $rule->fromColumns->pluck('id')->all());
        $this->assertTrue($rule->require_subtasks_done);
        $this->assertSame($this->board->id, $rule->board_id);

        // saving again replaces it
        $this->save('done', ['require_due_date' => true])->assertSessionHasNoErrors();
        $rule = $this->doneRule();
        $this->assertSame([], $rule->users->all());
        $this->assertFalse($rule->restrict_who);
        $this->assertTrue($rule->require_due_date);
        $this->assertSame(1, BoardColumnRule::count());

        $this->save('done', [])->assertSessionHas('success', 'Done has no workflow rule now.');
        $this->assertNull($this->doneRule());

        $this->save('done', ['require_assignee' => true]);
        $this->actingAs($this->owner())->delete(route('boards.columns.rule.destroy', [$this->workspace->slug, $this->project->slug, $this->board->slug, $this->column('done')]))
            ->assertSessionHas('success');
        $this->assertNull($this->doneRule());
    }

    public function test_only_project_and_workspace_admins_change_rules_editors_cant_lift_them(): void
    {
        // the people a rule restricts (project editors) can neither write nor delete it
        $this->save('done', ['require_assignee' => true], $this->editor)->assertForbidden();
        $this->save('done', ['require_assignee' => true], $this->viewer)->assertForbidden();
        $this->save('done', ['require_assignee' => true], $this->outsider)->assertForbidden();
        $this->assertSame(0, BoardColumnRule::count());

        $this->save('done', ['allowed_user_ids' => [$this->max->id]])->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->delete(route('boards.columns.rule.destroy', [$this->workspace->slug, $this->project->slug, $this->board->slug, $this->column('done')]))
            ->assertForbidden();
        $this->save('done', [], $this->editor)->assertForbidden();
        $this->assertNotNull($this->doneRule());

        // workspace admins may
        $this->save('done', [], $this->owner())->assertSessionHasNoErrors();
        $this->assertNull($this->doneRule());

        // a column of another board through this board's URL
        $other = Board::factory()->create(['project_id' => $this->project->id]);
        $this->actingAs($this->admin)->put(route('boards.columns.rule.update', [$this->workspace->slug, $this->project->slug, $this->board->slug, $other->columns()->value('id')]), [
            'allowed_user_ids' => [], 'allowed_roles' => [], 'allowed_from_column_ids' => [],
            'require_assignee' => true, 'require_subtasks_done' => false, 'require_due_date' => false,
        ])->assertNotFound();
    }

    public function test_every_rule_change_is_logged_on_the_board_and_listed_in_the_settings(): void
    {
        $this->save('done', ['allowed_from_column_ids' => [$this->column('in-progress')]]);
        $this->save('done', ['allowed_from_column_ids' => [$this->column('in-progress')], 'require_assignee' => true]);
        $this->save('done', ['allowed_from_column_ids' => [$this->column('in-progress')], 'require_assignee' => true]); // unchanged: no entry
        $this->save('done', []);

        $logs = ActivityLog::where('loggable_type', Board::class)->where('loggable_id', $this->board->id)->orderBy('id')->get();
        $this->assertSame(['workflow_rule_created', 'workflow_rule_updated', 'workflow_rule_deleted'], $logs->pluck('action')->all());
        $this->assertSame($this->admin->id, $logs[0]->user_id);
        $this->assertSame('Done', $logs[1]->metadata['column']);
        $this->assertSame(['old' => 'Cards can enter Done only from In Progress.', 'new' => 'Cards can enter Done only from In Progress. Requires an assignee.'], $logs[1]->changes['rule']);
        $this->assertSame('', $logs[2]->changes['rule']['new']);

        $this->actingAs($this->editor)->get(route('boards.edit', [$this->workspace->slug, $this->project->slug, $this->board->slug]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Boards/Edit')
                ->where('canManageRules', false)
                ->has('ruleHistory', 3)
                ->where('ruleHistory.0.action', 'workflow_rule_deleted')
                ->where('ruleHistory.0.user', $this->admin->name)
                ->where('ruleHistory.2.after', 'Cards can enter Done only from In Progress.'));
        $this->actingAs($this->admin)->get(route('boards.edit', [$this->workspace->slug, $this->project->slug, $this->board->slug]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canManageRules', true));
    }

    public function test_editors_cant_delete_a_column_a_rule_depends_on(): void
    {
        $review = $this->board->columns()->create(['name' => 'Review', 'slug' => 'review', 'position' => 5]);
        $qa = $this->board->columns()->create(['name' => 'QA', 'slug' => 'qa', 'position' => 6]);
        $spare = $this->board->columns()->create(['name' => 'Spare', 'slug' => 'spare', 'position' => 7]);
        $this->save('done', ['allowed_from_column_ids' => [$review->id]]);
        $this->save('qa', ['require_assignee' => true]);

        $this->deleteColumn($review, $this->editor)
            ->assertSessionHasErrors(['column' => 'The workflow rule of Done allows cards from Review. Only project admins can delete it.']);
        $this->deleteColumn($qa, $this->editor)
            ->assertSessionHasErrors(['column' => 'QA has a workflow rule. Only project admins can delete it.']);
        $this->assertNotNull(BoardColumn::find($review->id));
        $this->assertNotNull(BoardColumn::find($qa->id));

        // a column no rule mentions: editors may, as before
        $this->deleteColumn($spare, $this->editor)->assertSessionHasNoErrors();

        // MCP refuses the same way
        BrainitiveServer::actingAs($this->editor)->tool(DeleteBoardColumnTool::class, [
            'workspace' => $this->workspace->slug, 'project' => $this->project->slug, 'board' => $this->board->slug, 'column_id' => $review->id,
        ])->assertHasErrors(['The workflow rule of Done allows cards from Review. Only project admins can delete it.']);

        // admins may; the rule that only allowed cards from Review restricts nothing now → removed
        $this->deleteColumn($review, $this->admin)
            ->assertSessionHas('success', 'Column deleted. Workflow rules that allowed cards from Review were updated.');
        $this->assertNull($this->doneRule());
        $this->assertSame(1, BoardColumnRule::count()); // QA's
    }

    public function test_members_columns_and_roles_are_validated_against_this_board(): void
    {
        $stranger = User::factory()->create();
        $foreignWorkspace = Workspace::factory()->create();
        $foreignWorkspace->addUser($stranger, 'admin');
        $other = Board::factory()->create(['project_id' => $this->project->id]);

        $this->save('done', ['allowed_user_ids' => [$stranger->id]])->assertSessionHasErrors('allowed_user_ids.0');
        $this->save('done', ['allowed_roles' => ['instance_admin']])->assertSessionHasErrors('allowed_roles.0');
        $this->actingAs($this->admin)->put($this->url('done'), [])->assertSessionHasErrors(['allowed_user_ids', 'allowed_roles', 'require_assignee']);
        // more ids than the board has columns
        $this->save('done', ['allowed_from_column_ids' => range(1, 10)])->assertSessionHasErrors('allowed_from_column_ids');
        $this->assertSame(0, BoardColumnRule::count());

        // columns of another board are dropped (intersected with this board's), so nothing is left to restrict
        $this->save('done', ['allowed_from_column_ids' => [$other->columns()->value('id')]])->assertSessionHasNoErrors();
        $this->assertSame(0, BoardColumnRule::count());
        $this->save('done', ['allowed_from_column_ids' => [$other->columns()->value('id'), $this->column('in-progress')]]);
        $this->assertSame([$this->column('in-progress')], $this->doneRule()->fromColumns->pluck('id')->all());

        // workspace admins can be listed (they can open every project)
        $this->save('done', ['allowed_user_ids' => [$this->workspace->owner_id]])->assertSessionHasNoErrors();
    }

    public function test_an_emptied_who_rule_means_nobody_admins_included_until_an_admin_edits_it(): void
    {
        $this->save('done', ['allowed_user_ids' => [$this->max->id]]);
        UserDeletion::delete($this->max);
        WorkflowGuard::instance()->forget($this->board->id);

        $rule = $this->doneRule();
        $this->assertNotNull($rule, 'the rule stays');
        $this->assertSame([], $rule->users->all());
        $this->assertTrue($rule->restrict_who);
        $done = BoardColumn::find($this->column('done'));
        $this->assertSame('Nobody can move cards here right now (the rule lists nobody).', WorkflowRules::sentence(WorkflowGuard::instance()->ruleFor($done)));

        // nobody may move cards in: editors, project admins and the workspace owner alike (no admin fallback)
        $item = $this->item(['column_id' => $this->column('in-progress')]);
        foreach ([$this->editor, $this->admin, $this->owner()] as $user) {
            $this->actingAs($user)->from(route('boards.show', [$this->workspace->slug, $this->project->slug, $this->board->slug]))
                ->patch($this->itemRoute('boards.items.move', $item), ['column_id' => $done->id, 'position' => 0])
                ->assertSessionHasErrors(['column_id' => 'Nobody can move cards to Done right now (the rule lists nobody).']);
        }
        $this->assertSame($this->column('in-progress'), $item->fresh()->column_id);

        // the board tells everyone the same
        $this->actingAs($this->admin)->get(route('boards.show', [$this->workspace->slug, $this->project->slug, $this->board->slug]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('board.columns.2.workflow.actor_allowed', false)
                ->where('board.columns.2.workflow.actor_reason', 'Nobody can move cards to Done right now (the rule lists nobody).')
                ->where('board.columns.2.workflow.summary', ['Nobody can move cards here right now (the rule lists nobody).']));

        $this->actingAs($this->admin)->get(route('boards.edit', [$this->workspace->slug, $this->project->slug, $this->board->slug]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where("workflowRules.{$done->id}.locked", true)
                ->where("workflowRules.{$done->id}.restrict_who", true)
                ->where('canManageRules', true));

        // admins can still edit it: re-saving locked keeps it locked; picking someone fixes it; clearing on purpose opens it
        $this->save('done', ['restrict_who' => true])->assertSessionHasNoErrors();
        $this->assertTrue($this->doneRule()->restrict_who);
        $this->save('done', ['allowed_roles' => ['project_admin']])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->patch($this->itemRoute('boards.items.move', $item), ['column_id' => $done->id, 'position' => 0])->assertSessionHasNoErrors();
        $this->assertSame($done->id, $item->fresh()->column_id);
        $this->save('done', ['restrict_who' => false]);
        $this->assertNull($this->doneRule());
    }

    public function test_workspace_admins_who_are_not_project_members_can_edit_rules(): void
    {
        $wsAdmin = User::factory()->create();
        $this->workspace->addUser($wsAdmin, 'admin');
        $this->assertNull($wsAdmin->projectRole($this->project));

        $this->save('done', ['require_assignee' => true], $wsAdmin)->assertSessionHasNoErrors();
        $this->assertTrue($this->doneRule()->require_assignee);
        $this->actingAs($wsAdmin)->delete(route('boards.columns.rule.destroy', [$this->workspace->slug, $this->project->slug, $this->board->slug, $this->column('done')]))
            ->assertSessionHas('success');
        $this->assertNull($this->doneRule());
    }

    public function test_people_who_left_are_left_out_of_summaries_and_offered_as_former(): void
    {
        $this->save('done', ['allowed_user_ids' => [$this->max->id, $this->editor->id]]);
        $this->project->users()->detach($this->max->id); // left the project, account stays
        WorkflowGuard::instance()->forget($this->board->id);

        $column = BoardColumn::find($this->column('done'));
        $this->assertSame("Only {$this->editor->name} can move cards here.", WorkflowRules::sentence(WorkflowGuard::instance()->ruleFor($column)));

        $this->actingAs($this->admin)->get(route('boards.edit', [$this->workspace->slug, $this->project->slug, $this->board->slug]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where("workflowRules.{$column->id}.allowed_user_ids", [$this->editor->id])
                ->where("workflowRules.{$column->id}.former_user_ids", [$this->max->id])
                ->where('ruleMembers', fn ($members) => collect($members)->contains(fn ($m) => $m['id'] === $this->max->id && $m['former'])));
    }

    public function test_deleting_a_user_listed_with_others_removes_only_them(): void
    {
        $this->save('done', ['allowed_user_ids' => [$this->max->id, $this->editor->id]]);
        UserDeletion::delete($this->max);
        $this->assertSame([$this->editor->id], $this->doneRule()->users->pluck('id')->all());
    }

    public function test_the_settings_page_lists_rules_people_and_roles(): void
    {
        $this->save('done', ['allowed_user_ids' => [$this->max->id], 'allowed_from_column_ids' => [$this->column('in-progress')]]);
        WorkflowGuard::instance()->forget($this->board->id);

        $this->actingAs($this->editor)->get(route('boards.edit', [$this->workspace->slug, $this->project->slug, $this->board->slug]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Boards/Edit')
                ->where("workflowRules.{$this->column('done')}.allowed_user_ids", [$this->max->id])
                ->where("workflowRules.{$this->column('done')}.allowed_from_column_ids", [$this->column('in-progress')])
                ->where("workflowRules.{$this->column('done')}.require_assignee", false)
                ->has('ruleRoles', 4)
                ->where('ruleRoles.2', ['key' => 'project_admin', 'label' => 'Project admins'])
                ->where('ruleMembers', fn ($members) => collect($members)->contains(fn ($m) => $m['id'] === $this->max->id && $m['role'] === 'Project editor' && $m['can_move'])
                    && collect($members)->contains(fn ($m) => $m['id'] === $this->viewer->id && ! $m['can_move'])
                    && collect($members)->contains(fn ($m) => $m['id'] === $this->workspace->owner_id && $m['role'] === 'Workspace owner')
                    && ! collect($members)->contains(fn ($m) => $m['id'] === $this->outsider->id)));

        $this->actingAs($this->viewer)->get(route('boards.edit', [$this->workspace->slug, $this->project->slug, $this->board->slug]))->assertForbidden();
    }

    public function test_a_column_rule_reaches_open_boards_as_a_board_update(): void
    {
        $before = $this->board->fresh()->updated_at;
        $this->travel(1)->minute();
        $this->save('done', ['require_assignee' => true]);
        $this->assertTrue($this->board->fresh()->updated_at->greaterThan($before));
    }

    private function owner(): User
    {
        return User::findOrFail($this->workspace->owner_id);
    }
}
