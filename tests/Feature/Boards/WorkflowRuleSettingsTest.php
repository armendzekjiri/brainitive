<?php

namespace Tests\Feature\Boards;

use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\BoardColumnRule;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Boards\WorkflowGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Board settings → Workflow rules (v1.52.0): who may edit them, what is accepted, and cleanup. */
class WorkflowRuleSettingsTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    private User $max;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        $this->max = $this->projectMember('editor');
    }

    private function url(string $slug, ?Board $board = null): string
    {
        $board ??= $this->board;

        return route('boards.columns.rule.update', [$this->workspace->slug, $this->project->slug, $board->slug, $board->columns()->where('slug', $slug)->value('id')]);
    }

    /** @param array<string, mixed> $data */
    private function save(string $slug, array $data, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->editor)
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

    public function test_board_editors_save_a_rule_and_an_empty_rule_removes_it(): void
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
        $this->assertSame([$this->column('in-progress')], $rule->fromColumns->pluck('id')->all());
        $this->assertTrue($rule->require_subtasks_done);
        $this->assertFalse($rule->require_assignee);
        $this->assertSame($this->board->id, $rule->board_id);

        // saving again replaces it
        $this->save('done', ['require_due_date' => true])->assertSessionHasNoErrors();
        $rule = $this->doneRule();
        $this->assertSame([], $rule->users->all());
        $this->assertSame([], $rule->fromColumns->all());
        $this->assertTrue($rule->require_due_date);
        $this->assertSame(1, BoardColumnRule::count());

        $this->save('done', [])->assertSessionHas('success', 'Done has no workflow rule now.');
        $this->assertNull($this->doneRule());

        $this->save('done', ['require_assignee' => true]);
        $this->actingAs($this->editor)->delete(route('boards.columns.rule.destroy', [$this->workspace->slug, $this->project->slug, $this->board->slug, $this->column('done')]))
            ->assertSessionHas('success');
        $this->assertNull($this->doneRule());
    }

    public function test_only_people_who_may_edit_the_board_change_rules(): void
    {
        $this->save('done', ['require_assignee' => true], $this->viewer)->assertForbidden();
        $this->save('done', ['require_assignee' => true], $this->outsider)->assertForbidden();
        $this->actingAs($this->viewer)->delete(route('boards.columns.rule.destroy', [$this->workspace->slug, $this->project->slug, $this->board->slug, $this->column('done')]))
            ->assertForbidden();
        $this->assertSame(0, BoardColumnRule::count());

        // a column of another board through this board's URL
        $other = Board::factory()->create(['project_id' => $this->project->id]);
        $this->actingAs($this->editor)->put(route('boards.columns.rule.update', [$this->workspace->slug, $this->project->slug, $this->board->slug, $other->columns()->value('id')]), [
            'allowed_user_ids' => [], 'allowed_roles' => [], 'allowed_from_column_ids' => [],
            'require_assignee' => true, 'require_subtasks_done' => false, 'require_due_date' => false,
        ])->assertNotFound();
    }

    public function test_members_columns_and_roles_are_validated_against_this_board(): void
    {
        $stranger = User::factory()->create();
        $foreignWorkspace = Workspace::factory()->create();
        $foreignWorkspace->addUser($stranger, 'admin');
        $other = Board::factory()->create(['project_id' => $this->project->id]);

        $this->save('done', ['allowed_user_ids' => [$stranger->id]])->assertSessionHasErrors('allowed_user_ids.0');
        $this->save('done', ['allowed_from_column_ids' => [$other->columns()->value('id')]])->assertSessionHasErrors('allowed_from_column_ids.0');
        $this->save('done', ['allowed_roles' => ['instance_admin']])->assertSessionHasErrors('allowed_roles.0');
        $this->actingAs($this->editor)->put($this->url('done'), [])->assertSessionHasErrors(['allowed_user_ids', 'allowed_roles', 'require_assignee']);
        $this->assertSame(0, BoardColumnRule::count());

        // workspace admins can be listed (they can open every project)
        $this->save('done', ['allowed_user_ids' => [$this->workspace->owner_id]])->assertSessionHasNoErrors();
    }

    public function test_deleting_a_column_or_a_user_cleans_up_the_rules(): void
    {
        $review = $this->board->columns()->create(['name' => 'Review', 'slug' => 'review', 'position' => 5]);
        $this->save('done', ['allowed_user_ids' => [$this->max->id], 'allowed_from_column_ids' => [$review->id, $this->column('in-progress')]]);
        $this->save('review', ['require_assignee' => true]);
        $this->assertSame(2, BoardColumnRule::count());

        $this->actingAs($this->editor)->delete(route('boards.columns.destroy', [$this->workspace->slug, $this->project->slug, $this->board->slug, $review->id]))
            ->assertSessionHas('success', 'Column deleted. Workflow rules that allowed cards from Review were updated.');
        $this->assertSame(1, BoardColumnRule::count());
        $this->assertSame([$this->column('in-progress')], $this->doneRule()->fromColumns->pluck('id')->all());

        DB::table('users')->where('id', $this->max->id)->delete();
        $this->assertSame([], $this->doneRule()->users->all());
        $this->assertSame(0, DB::table('board_column_rule_user')->count());
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
        $this->assertInstanceOf(BoardColumn::class, $this->doneRule()->column);
    }
}
