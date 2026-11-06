<?php

namespace Tests\Feature\Boards;

use App\Mcp\Servers\BrainitiveServer;
use App\Mcp\Tools\CreateBoardItemTool;
use App\Mcp\Tools\GetBoardTool;
use App\Mcp\Tools\MoveBoardItemTool;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\BoardColumnRule;
use App\Models\Item;
use App\Models\RecurringIssue;
use App\Models\User;
use App\Support\Boards\WorkflowGuard;
use App\Support\Boards\WorkflowRules;
use App\Support\Boards\WorkflowRuleViolation;
use App\Support\RecurringIssues\RecurringIssueRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\Fluent\AssertableJson;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Workflow rules for boards (v1.52.0): who may move cards into a column, from where, conditions — enforced on
 * every path that changes an issue's column or creates one (WorkflowGuard), with no bypass for admins.
 */
class WorkflowRulesTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    private User $owner;

    private User $max;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        Notification::fake();
        $this->owner = User::findOrFail($this->workspace->owner_id);
        $this->max = $this->projectMember('editor');
    }

    /** @param array<string, mixed> $rule */
    private function rule(string $slug, array $rule): void
    {
        $column = BoardColumn::findOrFail($this->column($slug));
        WorkflowRules::save($column, $rule + [
            'allowed_user_ids' => [], 'allowed_roles' => [], 'allowed_from_column_ids' => [],
            'require_assignee' => false, 'require_subtasks_done' => false, 'require_due_date' => false,
        ]);
    }

    private function boardUrl(): string
    {
        return route('boards.show', [$this->workspace->slug, $this->project->slug, $this->board->slug]);
    }

    private function move(Item $item, string $slug, ?User $user = null, bool $withSubtasks = false)
    {
        return $this->actingAs($user ?? $this->editor)->from($this->boardUrl())
            ->patch($this->itemRoute('boards.items.move', $item), ['column_id' => $this->column($slug), 'position' => 0, 'with_subtasks' => $withSubtasks]);
    }

    private function columnOf(Item $item): string
    {
        return (string) BoardColumn::whereKey($item->fresh()->column_id)->value('slug');
    }

    // ── no rules ────────────────────────────────────────────────────────────────────────────

    public function test_boards_without_rules_behave_as_before(): void
    {
        $item = $this->item(['column_id' => $this->column('to-do')]);

        $this->move($item, 'done')->assertSessionHasNoErrors();
        $this->assertSame('done', $this->columnOf($item));
        $this->move($item, 'to-do', $this->max)->assertSessionHasNoErrors();
        $this->assertSame('to-do', $this->columnOf($item));
        $this->assertSame(0, BoardColumnRule::count());
    }

    // ── who ─────────────────────────────────────────────────────────────────────────────────

    public function test_only_listed_members_may_move_cards_into_the_column(): void
    {
        $this->rule('done', ['allowed_user_ids' => [$this->max->id]]);
        $item = $this->item(['column_id' => $this->column('in-progress')]);

        $this->move($item, 'done')->assertRedirect($this->boardUrl())
            ->assertSessionHasErrors(['column_id' => "Only {$this->max->name} can move cards to Done."]);
        $this->assertSame('in-progress', $this->columnOf($item));

        $this->move($item, 'done', $this->max)->assertSessionHasNoErrors();
        $this->assertSame('done', $this->columnOf($item));

        // leaving the column and other columns are not restricted
        $this->move($item, 'to-do')->assertSessionHasNoErrors();
        $this->assertSame('to-do', $this->columnOf($item));
    }

    public function test_roles_allow_moving_and_admins_get_no_bypass(): void
    {
        $projectAdmin = $this->projectMember('admin');
        $this->rule('done', ['allowed_roles' => ['project_admin']]);
        $item = $this->item(['column_id' => $this->column('in-progress')]);

        // the workspace owner / admin is checked like everyone else
        $this->move($item, 'done', $this->owner)->assertSessionHasErrors(['column_id' => 'Only project admins can move cards to Done.']);
        $this->move($item, 'done', $this->editor)->assertSessionHasErrors('column_id');
        $this->assertSame('in-progress', $this->columnOf($item));

        $this->move($item, 'done', $projectAdmin)->assertSessionHasNoErrors();
        $this->assertSame('done', $this->columnOf($item));

        // members OR roles
        $this->rule('in-progress', ['allowed_user_ids' => [$this->max->id], 'allowed_roles' => ['workspace_owner']]);
        $this->move($item, 'in-progress', $this->editor)
            ->assertSessionHasErrors(['column_id' => "Only {$this->max->name} or the workspace owner can move cards to In Progress."]);
        $this->move($item, 'in-progress', $this->owner)->assertSessionHasNoErrors();
        $this->assertSame('in-progress', $this->columnOf($item));
    }

    // ── from ────────────────────────────────────────────────────────────────────────────────

    public function test_a_column_can_only_be_entered_from_the_listed_columns(): void
    {
        $this->rule('done', ['allowed_from_column_ids' => [$this->column('in-progress')]]);
        $item = $this->item(['column_id' => $this->column('to-do')]);

        $this->move($item, 'done')->assertSessionHasErrors(['column_id' => 'Cards can only move to Done from In Progress.']);
        $this->assertSame('to-do', $this->columnOf($item));

        $this->move($item, 'in-progress')->assertSessionHasNoErrors();
        $this->move($item, 'done')->assertSessionHasNoErrors();
        $this->assertSame('done', $this->columnOf($item));

        // a reorder inside the column isn't entering it
        $this->move($item, 'done')->assertSessionHasNoErrors();
    }

    // ── conditions ──────────────────────────────────────────────────────────────────────────

    public function test_conditions_assignee_due_date_and_subtasks(): void
    {
        $this->rule('done', ['require_assignee' => true, 'require_due_date' => true, 'require_subtasks_done' => true]);
        $item = $this->item(['column_id' => $this->column('in-progress')]);
        $child = $this->item(['column_id' => $this->column('to-do'), 'parent_id' => $item->id]);

        $this->move($item, 'done')->assertSessionHasErrors(['column_id' => 'Cards need an assignee before they can move to Done.']);
        $item->update(['assigned_to' => $this->max->id]);
        $this->move($item, 'done')->assertSessionHasErrors(['column_id' => 'Cards need a due date before they can move to Done.']);
        $item->update(['due_date' => now()->addDay()->toDateString()]);
        $this->move($item, 'done')->assertSessionHasErrors(['column_id' => 'All subtasks must be done before a card can move to Done.']);
        $this->assertSame('in-progress', $this->columnOf($item));

        // completing the subtasks along with it ("Also complete the subtasks?") satisfies the condition; the
        // subtask itself has to meet the rule too
        $child->update(['assigned_to' => $this->max->id, 'due_date' => now()->toDateString()]);
        $this->move($item, 'done', null, true)->assertSessionHasNoErrors();
        $this->assertSame('done', $this->columnOf($item));
        $this->assertSame('done', $this->columnOf($child));
    }

    public function test_the_subtask_cascade_is_all_or_nothing_and_names_the_subtask(): void
    {
        $this->rule('done', ['allowed_from_column_ids' => [$this->column('in-progress')]]);
        $parent = $this->item(['column_id' => $this->column('in-progress')]);
        $ready = $this->item(['column_id' => $this->column('in-progress'), 'parent_id' => $parent->id]);
        $notReady = $this->item(['column_id' => $this->column('to-do'), 'parent_id' => $parent->id]);

        $this->move($parent, 'done', null, true)
            ->assertSessionHasErrors(['column_id' => "Subtask {$notReady->key}: Cards can only move to Done from In Progress."]);
        foreach ([$parent, $ready] as $unchanged) {
            $this->assertSame('in-progress', $this->columnOf($unchanged));
        }
        $this->assertSame('to-do', $this->columnOf($notReady));
    }

    // ── other paths ─────────────────────────────────────────────────────────────────────────

    public function test_any_column_change_as_a_signed_in_user_is_checked_by_the_model(): void
    {
        $this->rule('done', ['allowed_user_ids' => [$this->max->id]]);
        $item = $this->item(['column_id' => $this->column('to-do')]);

        // no signed-in person (webhooks, console): not checked
        $item->update(['column_id' => $this->column('in-progress')]);

        Auth::setUser($this->editor);
        try {
            $item->update(['column_id' => $this->column('done')]);
            $this->fail('The backstop let the move through.');
        } catch (WorkflowRuleViolation $e) {
            $this->assertSame("Only {$this->max->name} can move cards to Done.", $e->text());
            $this->assertSame('actor', $e->reason);
        }
        $this->assertSame('in-progress', $this->columnOf($item));

        // explicitly bypassed system writes (booking cards for the host)
        WorkflowGuard::bypass(fn () => $item->update(['column_id' => $this->column('done')]));
        $this->assertSame('done', $this->columnOf($item));
    }

    public function test_bulk_edit_checks_every_issue_and_changes_nothing_when_one_is_refused(): void
    {
        $this->rule('done', ['allowed_from_column_ids' => [$this->column('in-progress')]]);
        $ok = $this->item(['column_id' => $this->column('in-progress')]);
        $blocked = $this->item(['column_id' => $this->column('to-do')]);
        $url = route('boards.items.bulk.update', [$this->workspace->slug, $this->project->slug, $this->board->slug]);

        $this->actingAs($this->editor)->from($this->boardUrl())
            ->patch($url, ['item_ids' => [$ok->id, $blocked->id], 'changes' => ['column_id' => $this->column('done')]])
            ->assertSessionHasErrors(['column_id' => "{$blocked->key}: Cards can only move to Done from In Progress."]);
        $this->assertSame('in-progress', $this->columnOf($ok));
        $this->assertSame('to-do', $this->columnOf($blocked));

        $this->actingAs($this->editor)->from($this->boardUrl())
            ->patch($url, ['item_ids' => [$ok->id], 'changes' => ['column_id' => $this->column('done')]])
            ->assertSessionHasNoErrors();
        $this->assertSame('done', $this->columnOf($ok));
    }

    public function test_bulk_completing_a_parent_with_its_subtasks_counts_them_as_done(): void
    {
        $this->rule('done', ['require_subtasks_done' => true]);
        $parent = $this->item(['column_id' => $this->column('in-progress')]);
        $child = $this->item(['column_id' => $this->column('to-do'), 'parent_id' => $parent->id]);
        $url = route('boards.items.bulk.update', [$this->workspace->slug, $this->project->slug, $this->board->slug]);

        $this->actingAs($this->editor)->from($this->boardUrl())
            ->patch($url, ['item_ids' => [$parent->id, $child->id], 'changes' => ['column_id' => $this->column('done')]])
            ->assertSessionHasNoErrors();
        $this->assertSame('done', $this->columnOf($parent));
        $this->assertSame('done', $this->columnOf($child));
    }

    public function test_creating_counts_as_entering_from_nowhere(): void
    {
        $store = route('boards.items.store', [$this->workspace->slug, $this->project->slug, $this->board->slug]);
        $this->rule('done', ['allowed_from_column_ids' => [$this->column('in-progress')]]);
        $this->rule('in-progress', ['allowed_user_ids' => [$this->max->id], 'require_assignee' => true]);

        $this->actingAs($this->editor)->from($this->boardUrl())->post($store, ['title' => 'Straight to done', 'column_id' => $this->column('done')])
            ->assertSessionHasErrors(['column_id' => "Cards can't be created in Done — they can only move there from In Progress."]);
        $this->actingAs($this->editor)->from($this->boardUrl())->post($store, ['title' => 'Not mine', 'column_id' => $this->column('in-progress')])
            ->assertSessionHasErrors(['column_id' => "Only {$this->max->name} can move cards to In Progress."]);
        $this->actingAs($this->max)->from($this->boardUrl())->post($store, ['title' => 'No assignee', 'column_id' => $this->column('in-progress')])
            ->assertSessionHasErrors(['column_id' => 'Cards need an assignee before they can move to In Progress.']);
        $this->assertSame(0, Item::count());

        $this->actingAs($this->max)->from($this->boardUrl())
            ->post($store, ['title' => 'Mine', 'column_id' => $this->column('in-progress'), 'assigned_to' => $this->max->id])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->from($this->boardUrl())->post($store, ['title' => 'Plain', 'column_id' => $this->column('to-do')])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, Item::count());
    }

    public function test_duplicate_and_move_to_another_board_obey_the_rules(): void
    {
        $done = $this->item(['column_id' => $this->column('done')]);
        $other = Board::factory()->create(['project_id' => $this->project->id]);
        $otherDone = $other->columns()->where('slug', 'done')->value('id');
        $mover = $this->item(['column_id' => $this->column('in-progress')]);
        $sub = $this->item(['column_id' => $this->column('to-do'), 'parent_id' => $mover->id]);
        $this->rule('done', ['allowed_user_ids' => [$this->max->id]]);
        WorkflowRules::save(BoardColumn::findOrFail($otherDone), [
            'allowed_user_ids' => [], 'allowed_roles' => [], 'allowed_from_column_ids' => [$other->columns()->where('slug', 'in-progress')->value('id')],
            'require_assignee' => false, 'require_subtasks_done' => false, 'require_due_date' => false,
        ]);

        $this->actingAs($this->editor)->from($this->boardUrl())->post($this->itemRoute('boards.items.duplicate', $done))
            ->assertSessionHasErrors(['column_id' => "Only {$this->max->name} can move cards to Done."]);
        $this->actingAs($this->max)->from($this->boardUrl())->post($this->itemRoute('boards.items.duplicate', $done))
            ->assertSessionHasNoErrors();
        $this->assertSame(4, Item::count());

        $this->actingAs($this->editor)->from($this->boardUrl())
            ->patch($this->itemRoute('boards.items.move-to-board', $mover), ['target_board_id' => $other->id, 'target_column_id' => $otherDone])
            ->assertSessionHasErrors(['column_id' => "Cards can't be created in Done — they can only move there from In Progress."]);
        $this->assertSame($this->board->id, $mover->fresh()->board_id);
        $this->assertSame($this->board->id, $sub->fresh()->board_id);

        $this->actingAs($this->editor)->from($this->boardUrl())
            ->patch($this->itemRoute('boards.items.move-to-board', $mover), ['target_board_id' => $other->id])
            ->assertSessionHasNoErrors();
        $this->assertSame($other->id, $mover->fresh()->board_id);
    }

    public function test_automations_obey_the_rules_and_log_why(): void
    {
        $this->rule('done', ['allowed_user_ids' => [$this->max->id]]);
        AutomationRule::create([
            'project_id' => $this->project->id, 'name' => 'Ship it', 'enabled' => true, 'version' => 1,
            'actor_id' => $this->editor->id, 'trigger' => 'item.created', 'trigger_config' => [], 'conditions' => [],
            'actions' => [['type' => 'move', 'value' => $this->column('done'), 'text' => null]],
        ]);

        $item = $this->item(['column_id' => $this->column('to-do')]);

        $this->assertSame('to-do', $this->columnOf($item));
        $run = AutomationRun::sole();
        $this->assertSame(AutomationRun::ERROR, $run->status);
        $this->assertSame("Blocked by the board's workflow rules: Only {$this->max->name} can move cards to Done.", $run->results[0]['message']);
    }

    public function test_recurring_issues_pause_when_a_rule_refuses_their_creator(): void
    {
        $this->rule('in-progress', ['allowed_user_ids' => [$this->max->id]]);
        $template = new RecurringIssue([
            'board_id' => $this->board->id, 'column_id' => $this->column('in-progress'), 'created_by' => $this->editor->id,
            'title' => 'Weekly report', 'frequency' => 'weekly', 'interval' => 1, 'weekdays' => [1],
            'start_date' => '2026-10-26', 'timezone' => 'UTC', 'active_from' => '2026-10-24',
        ]);
        $template->refreshNextOccurrence()->save();

        $this->assertNull(RecurringIssueRunner::run($template, Carbon::parse('2026-10-26 08:00', 'UTC')));
        $template->refresh();
        $this->assertNotNull($template->paused_at);
        $this->assertSame("Paused: Only {$this->max->name} can move cards to In Progress.", $template->last_error);
        $this->assertSame(0, Item::count());
    }

    // ── MCP ───────────────────────────────────────────────────────────────────────

    public function test_mcp_moves_and_creates_return_the_reason_and_get_board_shows_the_rule(): void
    {
        $this->rule('done', ['allowed_from_column_ids' => [$this->column('in-progress')], 'allowed_roles' => ['project_admin']]);
        $item = $this->item(['column_id' => $this->column('to-do')]);
        $where = ['workspace' => $this->workspace->slug, 'project' => $this->project->slug, 'board' => $this->board->slug];

        BrainitiveServer::actingAs($this->editor)->tool(MoveBoardItemTool::class, $where + ['item_id' => $item->id, 'column_id' => $this->column('done')])
            ->assertHasErrors(['Only project admins can move cards to Done.']);
        $this->assertSame('to-do', $this->columnOf($item));

        BrainitiveServer::actingAs($this->editor)->tool(CreateBoardItemTool::class, $where + ['title' => 'Done already', 'column' => 'Done'])
            ->assertHasErrors(['Only project admins can move cards to Done.']);
        $this->assertSame(1, Item::count());

        BrainitiveServer::actingAs($this->editor)->tool(GetBoardTool::class, $where)
            ->assertStructuredContent(fn (AssertableJson $j) => $j
                ->where('columns.0.workflow_rule', '')
                ->where('columns.2.workflow_rule', 'Cards can enter Done only from In Progress. Only project admins can move cards here.')
                ->etc());
    }

    // ── board page hints ────────────────────────────────────────────────────────────────────

    public function test_the_board_page_tells_each_user_what_the_rules_allow(): void
    {
        $this->rule('done', ['allowed_user_ids' => [$this->max->id], 'allowed_from_column_ids' => [$this->column('in-progress')]]);

        $this->actingAs($this->editor)->get($this->boardUrl())->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Boards/Show')
                ->where('board.columns.0.workflow', null)
                ->where('board.columns.2.workflow.actor_allowed', false)
                ->where('board.columns.2.workflow.actor_reason', "Only {$this->max->name} can move cards to Done.")
                ->where('board.columns.2.workflow.allowed_from', [$this->column('in-progress')])
                ->where('board.columns.2.workflow.summary', ['Cards can enter Done only from In Progress.', "Only {$this->max->name} can move cards here."]));

        $this->actingAs($this->max)->get($this->boardUrl())->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('board.columns.2.workflow.actor_allowed', true)
                ->where('board.columns.2.workflow.actor_reason', null));
    }
}
