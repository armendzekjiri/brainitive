<?php

namespace Tests\Feature\Automation;

use App\Jobs\RunAutomation;
use App\Models\ActivityLog;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Board;
use App\Models\Comment;
use App\Models\ContentReference;
use App\Models\Helpdesk;
use App\Models\Item;
use App\Models\ItemPriority;
use App\Models\ItemType;
use App\Models\Label;
use App\Models\Ticket;
use App\Models\TicketContact;
use App\Models\User;
use App\Notifications\AutomationNoticeNotification;
use App\Notifications\ItemAssignedNotification;
use App\Notifications\TicketAssignedNotification;
use App\Support\Automation\AutomationEvents;
use App\Support\Automation\AutomationRunner;
use App\Support\Automation\DueDateTrigger;
use App\Support\Boards\ItemComments;
use App\Support\Events\DomainEvents;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * BRAI-63: triggers, conditions and actions of automation rules, how events reach the engine
 * (after commit, deferred to the end of a request), idempotency, loop safety and permissions.
 */
class AutomationEngineTest extends TestCase
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

    private function rule(array $attributes = []): AutomationRule
    {
        return AutomationRule::create($attributes + [
            'project_id' => $this->project->id,
            'name' => 'Rule '.Str::random(4),
            'enabled' => true,
            'version' => 1,
            'actor_id' => $this->editor->id,
            'trigger' => 'item.created',
            'trigger_config' => [],
            'conditions' => [],
            'actions' => [],
        ]);
    }

    private function priority(string $name = 'High'): ItemPriority
    {
        return ItemPriority::create(['name' => $name, 'slug' => Str::slug($name).'-'.Str::random(4), 'color' => '#f00', 'order' => 2]);
    }

    private function helpdeskTicket(array $attributes = []): Ticket
    {
        $helpdesk = Helpdesk::where('project_id', $this->project->id)->first()
            ?? Helpdesk::create(['project_id' => $this->project->id, 'name' => 'Support', 'slug' => 'support', 'created_by' => $this->editor->id]);
        $contact = TicketContact::firstOrCreate(['email' => 'customer@example.com'], ['uuid' => (string) Str::uuid()]);

        return $helpdesk->tickets()->create($attributes + [
            'subject' => 'Printer on fire',
            'description' => '<p>Please help</p>',
            'column_id' => $helpdesk->columns()->first()->id,
            'ticket_contact_id' => $contact->id,
            'key' => $this->project->getNextTicketKey(),
            'position' => 0,
        ]);
    }

    // ── triggers ─────────────────────────────────────────────────────────────

    public function test_a_rule_assigning_its_own_actor_still_notifies_them_as_the_automation(): void
    {
        // v1.41.2: "when moved to Testing and unassigned, assign Armend" — Armend's own rule must tell him
        Notification::fake();
        $rule = $this->rule([
            'name' => 'Testing owner',
            'actions' => [['type' => 'assign', 'value' => $this->editor->id, 'text' => null]],
        ]);

        $item = $this->item(['title' => 'Needs a tester']);

        $this->assertSame($this->editor->id, $item->fresh()->assigned_to);
        Notification::assertSentTo($this->editor, ItemAssignedNotification::class, function (ItemAssignedNotification $n) {
            $data = $n->toDatabase($this->editor);

            return $n->automation === 'Testing owner'
                && $data['actor_name'] === 'Automation “Testing owner”'
                && str_starts_with($n->toMail($this->editor)->subject, 'Automation “Testing owner” assigned you to');
        });

        // assigning yourself by hand still stays quiet
        $rule->update(['enabled' => false]);
        Notification::fake();
        $this->actingAs($this->editor);
        $other = $this->item(['title' => 'Mine']);
        $other->update(['assigned_to' => $this->editor->id]);
        Notification::assertNotSentTo($this->editor, ItemAssignedNotification::class);
    }

    public function test_a_rule_assigning_a_ticket_to_its_own_actor_still_notifies_them(): void
    {
        Notification::fake();
        $this->rule([
            'name' => 'Support owner',
            'trigger' => 'ticket.created',
            'actions' => [['type' => 'assign', 'value' => $this->editor->id, 'text' => null]],
        ]);
        $ticket = $this->helpdeskTicket();

        DomainEvents::emit($this->workspace, 'ticket.created', fn () => [], subject: $ticket);

        $this->assertSame($this->editor->id, $ticket->fresh()->assigned_to);
        Notification::assertSentTo($this->editor, TicketAssignedNotification::class, fn (TicketAssignedNotification $n) => $n->automation === 'Support owner');
    }

    public function test_issue_created_rule_assigns_and_sets_priority_once_as_its_actor(): void
    {
        Notification::fake();
        $high = $this->priority();
        $rule = $this->rule([
            'conditions' => [['type' => 'board', 'value' => $this->board->id]],
            'actions' => [['type' => 'assign', 'value' => $this->max->id, 'text' => null], ['type' => 'set_priority', 'value' => $high->id, 'text' => null]],
        ]);

        $item = $this->item(['title' => 'New issue']);

        $item->refresh();
        $this->assertSame($this->max->id, $item->assigned_to);
        $this->assertSame($high->id, $item->priority_id);

        $run = AutomationRun::sole();
        $this->assertSame([AutomationRun::SUCCESS, $rule->id, 1, "item.created:{$item->id}"], [$run->status, $run->automation_rule_id, $run->rule_version, $run->event_id]);
        $this->assertSame(['ok', 'ok'], array_column($run->results, 'status'));
        $this->assertSame("{$item->key} New issue", $run->subject_label);
        $this->assertSame(AutomationRun::SUCCESS, $rule->fresh()->last_run_status);

        // the existing paths: history by the actor, "assigned you" from the actor
        $this->assertSame($this->editor->id, ActivityLog::where('loggable_id', $item->id)->where('action', 'assigned')->value('user_id'));
        Notification::assertSentTo($this->max, ItemAssignedNotification::class);
        $this->assertNull(auth()->user(), 'the actor is only signed in while the run executes');

        // a duplicate delivery of the same event: no second run
        AutomationEvents::start(['trigger' => 'item.created', 'project_id' => $this->project->id, 'subject_type' => 'item', 'subject_id' => $item->id, 'event_id' => "item.created:{$item->id}", 'context' => []]);
        $this->assertSame(1, AutomationRun::count());
    }

    public function test_issue_moved_trigger_can_require_a_target_column(): void
    {
        $this->rule([
            'trigger' => 'item.moved',
            'trigger_config' => ['column_id' => $this->column('done')],
            'actions' => [['type' => 'comment', 'value' => null, 'text' => '{{item.key}} "{{item.title}}" <b>shipped</b>']],
        ]);
        $item = $this->item(['title' => 'Login']);

        $item->update(['column_id' => $this->column('in-progress')]);
        $this->assertSame(0, AutomationRun::count());

        $item->update(['column_id' => $this->column('done')]);
        $comment = Comment::where('item_id', $item->id)->sole();
        $this->assertSame("<p>{$item->key} &quot;Login&quot; &lt;b&gt;shipped&lt;/b&gt;</p>", $comment->body);
        $this->assertSame($this->editor->id, $comment->user_id);
        $this->assertSame(['from_column_id' => $this->column('in-progress'), 'to_column_id' => $this->column('done')], AutomationRun::sole()->context);
    }

    public function test_issue_assigned_trigger_notifies_the_assignee(): void
    {
        Notification::fake();
        $this->rule([
            'name' => 'Heads-up',
            'trigger' => 'item.assigned',
            'actions' => [['type' => 'notify', 'value' => 'assignee', 'text' => 'You own {{item.key}} now']],
        ]);
        $item = $this->item();

        $item->update(['assigned_to' => $this->max->id]);

        Notification::assertSentTo($this->max, AutomationNoticeNotification::class, function (AutomationNoticeNotification $n) use ($item) {
            $data = $n->toDatabase($this->max);

            return $data['type'] === 'automation_notice'
                && $data['title'] === "Automation \"Heads-up\": {$item->key}"
                && $data['body'] === "You own {$item->key} now"
                && $data['actor_name'] === $this->editor->name;
        });
    }

    public function test_comment_added_trigger_adds_a_label(): void
    {
        $label = Label::create(['project_id' => $this->project->id, 'name' => 'discussed', 'color' => '#0f0']);
        $this->rule(['trigger' => 'item.commented', 'actions' => [['type' => 'add_label', 'value' => $label->id, 'text' => null]]]);
        $item = $this->item();

        $comment = ItemComments::add($item, $this->viewer, '<p>Any news?</p>');

        $this->assertTrue($item->labels()->whereKey($label->id)->exists());
        $this->assertSame("item.commented:{$comment->id}", AutomationRun::sole()->event_id);
        $this->assertSame(['labels' => ['added' => ['discussed'], 'removed' => []]], ActivityLog::where('loggable_id', $item->id)->where('action', 'labels_changed')->sole()->changes);
    }

    public function test_ticket_created_rule_creates_one_linked_issue_on_duplicate_delivery_and_assigns_the_ticket(): void
    {
        Notification::fake();
        $this->rule([
            'trigger' => 'ticket.created',
            'conditions' => [['type' => 'title_contains', 'value' => 'fire']],
            'actions' => [
                ['type' => 'create_issue', 'value' => $this->board->id, 'text' => null],
                ['type' => 'assign', 'value' => $this->max->id, 'text' => null],
            ],
        ]);
        $ticket = $this->helpdeskTicket();

        // the hook point (TicketController) emits once per ticket; a second delivery is a no-op
        DomainEvents::emit($this->workspace, 'ticket.created', fn () => [], subject: $ticket);
        DomainEvents::emit($this->workspace, 'ticket.created', fn () => [], subject: $ticket);

        $issue = Item::where('board_id', $this->board->id)->sole();
        $this->assertSame('Printer on fire', $issue->title);
        $this->assertSame($this->editor->id, $issue->created_by);
        $this->assertTrue(ContentReference::where(['source_type' => 'item', 'source_id' => $issue->id, 'target_type' => 'ticket', 'target_id' => $ticket->id])->exists());
        $this->assertSame($this->max->id, $ticket->fresh()->assigned_to);
        Notification::assertSentTo($this->max, TicketAssignedNotification::class);

        $run = AutomationRun::sole();
        $this->assertSame("Created {$issue->key} on {$this->board->name}.", $run->results[0]['message']);
        // the issue the rule created is automation-made: no item.created rules for it (none exist anyway)
        $this->assertSame(0, AutomationRun::where('subject_type', 'item')->count());
    }

    public function test_agent_ticket_form_reaches_the_engine(): void
    {
        $this->rule(['trigger' => 'ticket.created', 'actions' => [['type' => 'create_issue', 'value' => $this->board->id, 'text' => null]]]);
        $helpdesk = Helpdesk::create(['project_id' => $this->project->id, 'name' => 'Support', 'slug' => 'support', 'created_by' => $this->editor->id]);

        $this->actingAs($this->editor)->post(route('tickets.store', [$this->workspace->slug, $this->project->slug, $helpdesk->slug]), [
            'subject' => 'Cannot log in',
            'contact_email' => 'jane@example.com',
            'column_id' => $helpdesk->columns()->first()->id,
            'notify_contact' => false,
        ])->assertSessionHasNoErrors();

        $this->assertSame('Cannot log in', Item::where('board_id', $this->board->id)->sole()->title);
    }

    public function test_due_date_passed_runs_once_per_issue_and_due_date(): void
    {
        $now = CarbonImmutable::parse('2026-11-10 12:00:00', 'UTC');
        $this->rule(['trigger' => 'item.due', 'actions' => [['type' => 'comment', 'value' => null, 'text' => 'Overdue']]]);

        $overdue = $this->item(['due_date' => '2026-11-09']);
        $dueTimePassed = $this->item(['due_date' => '2026-11-10', 'due_time' => '09:00']);
        $dueLaterToday = $this->item(['due_date' => '2026-11-10', 'due_time' => '18:00']);
        $dueTodayNoTime = $this->item(['due_date' => '2026-11-10']);
        $done = $this->item(['due_date' => '2026-11-09', 'completed_at' => now()]);
        $old = $this->item(['due_date' => '2026-10-01']);

        $this->assertSame(2, DueDateTrigger::run($now));
        $this->assertSame(0, DueDateTrigger::run($now), 'once per issue and due date');
        $this->assertEqualsCanonicalizing([$overdue->id, $dueTimePassed->id], AutomationRun::pluck('subject_id')->all());
        foreach ([$dueLaterToday, $dueTodayNoTime, $done, $old] as $item) {
            $this->assertSame(0, Comment::where('item_id', $item->id)->count());
        }

        // a new due date that passes again starts the rule again
        $overdue->update(['due_date' => '2026-11-08']);
        $this->assertSame(1, DueDateTrigger::run($now));
        $this->assertSame(2, Comment::where('item_id', $overdue->id)->count());

        $this->artisan('app:run-due-date-automations')->assertSuccessful();
    }

    // ── conditions ───────────────────────────────────────────────────────────

    public function test_every_condition_type_is_checked(): void
    {
        $label = Label::create(['project_id' => $this->project->id, 'name' => 'bug', 'color' => '#f00']);
        $type = ItemType::create(['name' => 'Bug', 'slug' => 'bug-automation', 'is_active' => true]);
        $high = $this->priority();
        $otherBoard = Board::factory()->create(['project_id' => $this->project->id]);

        $cases = [
            [['type' => 'board', 'value' => $this->board->id], ['type' => 'board', 'value' => $otherBoard->id]],
            [['type' => 'item_type', 'value' => $type->id], ['type' => 'item_type', 'value' => $type->id + 1000]],
            [['type' => 'priority', 'value' => $high->id], ['type' => 'priority', 'value' => $high->id + 1000]],
            [['type' => 'label', 'value' => $label->id], ['type' => 'label', 'value' => $label->id + 1000]],
            [['type' => 'assignee_empty', 'value' => null], ['type' => 'assignee_is', 'value' => $this->max->id]],
            [['type' => 'column', 'value' => $this->column('to-do')], ['type' => 'column', 'value' => $this->column('done')]],
            [['type' => 'title_contains', 'value' => 'PAYMENT'], ['type' => 'title_contains', 'value' => 'refund']],
        ];

        foreach ($cases as $i => [$matching, $failing]) {
            $this->rule(['name' => "match {$i}", 'conditions' => [$matching], 'actions' => [['type' => 'comment', 'value' => null, 'text' => "match {$i}"]]]);
            $this->rule(['name' => "fail {$i}", 'conditions' => [$matching, $failing], 'actions' => [['type' => 'comment', 'value' => null, 'text' => "fail {$i}"]]]);
        }

        // labels are added after the insert, like the board's create form; the rules run after the request
        $item = AutomationEvents::defer(function () use ($type, $high, $label) {
            $item = $this->item(['title' => 'Payment fails', 'item_type_id' => $type->id, 'priority_id' => $high->id]);
            $item->labels()->attach($label->id);

            return $item;
        });

        $comments = Comment::where('item_id', $item->id)->pluck('body')->all();
        $this->assertEqualsCanonicalizing(array_map(fn ($i) => "<p>match {$i}</p>", array_keys($cases)), $comments);
        $this->assertSame(count($cases), AutomationRun::count(), 'conditions that do not match leave no run');

        // assignee is X
        $this->rule(['trigger' => 'item.assigned', 'conditions' => [['type' => 'assignee_is', 'value' => $this->max->id]], 'actions' => [['type' => 'comment', 'value' => null, 'text' => 'max']]]);
        $item->update(['assigned_to' => $this->editor->id]);
        $item->update(['assigned_to' => $this->max->id]);
        $this->assertSame(1, Comment::where('item_id', $item->id)->where('body', '<p>max</p>')->count());
    }

    // ── actions ──────────────────────────────────────────────────────────────

    public function test_issue_actions_change_the_issue_through_the_model(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00:00', 'UTC'));
        $type = ItemType::create(['name' => 'Bug', 'slug' => 'bug-automation', 'is_active' => true]);
        $label = Label::create(['project_id' => $this->project->id, 'name' => 'triage', 'color' => '#f00']);
        $otherBoard = Board::factory()->create(['project_id' => $this->project->id]);
        $this->rule(['actions' => [
            ['type' => 'assign', 'value' => 'reporter', 'text' => null],
            ['type' => 'move', 'value' => $this->column('done'), 'text' => null],
            ['type' => 'move', 'value' => $otherBoard->columns()->first()->id, 'text' => null],
            ['type' => 'set_type', 'value' => $type->id, 'text' => null],
            ['type' => 'set_due_date', 'value' => 3, 'text' => null],
            ['type' => 'add_label', 'value' => $label->id, 'text' => null],
            ['type' => 'notify', 'value' => $this->max->id, 'text' => null],
        ]]);
        Notification::fake();

        $item = $this->item(['created_by' => $this->viewer->id, 'reporter_id' => $this->viewer->id]);

        $item->refresh();
        $this->assertSame($this->viewer->id, $item->assigned_to);
        $this->assertSame($this->column('done'), $item->column_id);
        $this->assertNotNull($item->completed_at);
        $this->assertSame($type->id, $item->item_type_id);
        $this->assertSame('2026-11-13', $item->due_date->toDateString());
        $this->assertTrue($item->labels()->whereKey($label->id)->exists());
        Notification::assertSentTo($this->max, AutomationNoticeNotification::class);

        $results = AutomationRun::sole()->results;
        $this->assertSame(['ok', 'ok', 'skipped', 'ok', 'ok', 'ok', 'ok'], array_column($results, 'status'));
        $this->assertStringContainsString('is on another board', $results[2]['message']);
        $this->assertSame(AutomationRun::SUCCESS, AutomationRun::sole()->status);
    }

    // ── loops, permissions, transactions, retries ────────────────────────────

    public function test_changes_made_by_a_rule_never_trigger_rules(): void
    {
        $this->rule(['actions' => [['type' => 'assign', 'value' => $this->max->id, 'text' => null], ['type' => 'comment', 'value' => null, 'text' => 'auto']]]);
        $this->rule(['trigger' => 'item.assigned', 'actions' => [['type' => 'comment', 'value' => null, 'text' => 'assigned']]]);
        $this->rule(['trigger' => 'item.commented', 'actions' => [['type' => 'comment', 'value' => null, 'text' => 'again']]]);
        // a rule that would re-trigger itself forever
        $this->rule(['trigger' => 'item.moved', 'actions' => [['type' => 'move', 'value' => $this->column('to-do'), 'text' => null]]]);
        $this->rule(['trigger' => 'item.moved', 'actions' => [['type' => 'move', 'value' => $this->column('in-progress'), 'text' => null]]]);

        $item = $this->item();
        $this->assertSame(1, AutomationRun::count());
        $this->assertSame(['<p>auto</p>'], Comment::where('item_id', $item->id)->pluck('body')->all());

        $item->update(['column_id' => $this->column('done')]);
        $this->assertSame(3, AutomationRun::count(), 'one run per moved rule, no ping-pong');
    }

    public function test_a_rolled_back_change_starts_nothing(): void
    {
        $this->rule(['actions' => [['type' => 'assign', 'value' => $this->max->id, 'text' => null]]]);

        try {
            DB::transaction(function () {
                $this->item();
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, AutomationRun::count());
        $this->assertSame(0, Item::count());
    }

    public function test_runs_are_dispatched_after_commit(): void
    {
        Queue::fake();
        $this->rule(['actions' => [['type' => 'assign', 'value' => $this->max->id, 'text' => null]]]);

        DB::transaction(function () {
            $this->item();
            Queue::assertNothingPushed();
            $this->assertSame(0, AutomationRun::count());
        });

        Queue::assertPushed(RunAutomation::class, 1);
        $this->assertSame(AutomationRun::QUEUED, AutomationRun::sole()->status);
    }

    public function test_a_request_runs_rules_after_its_controller_finished(): void
    {
        $label = Label::create(['project_id' => $this->project->id, 'name' => 'urgent', 'color' => '#f00']);
        $this->rule([
            'conditions' => [['type' => 'label', 'value' => $label->id]],
            'actions' => [['type' => 'assign', 'value' => $this->max->id, 'text' => null]],
        ]);

        // the board's create form syncs labels after the insert; the rule still sees them
        $this->actingAs($this->editor)->post(route('boards.items.store', [$this->workspace->slug, $this->project->slug, $this->board->slug]), [
            'title' => 'Urgent thing',
            'column_id' => $this->column('to-do'),
            'label_ids' => [$label->id],
        ])->assertSessionHasNoErrors();

        $this->assertSame($this->max->id, Item::where('title', 'Urgent thing')->value('assigned_to'));
        $this->assertSame($this->editor->id, auth()->id(), 'the request user is restored after the run');
    }

    public function test_lost_permissions_skip_or_fail_the_run_clearly(): void
    {
        $rule = $this->rule(['actor_id' => $this->max->id, 'actions' => [['type' => 'assign', 'value' => $this->editor->id, 'text' => null]]]);

        // removed from the project → skipped
        $this->project->users()->detach($this->max->id);
        $item = $this->item();
        $run = AutomationRun::sole();
        $this->assertSame(AutomationRun::SKIPPED, $run->status);
        $this->assertStringContainsString("{$this->max->name} can no longer open this project", $run->message);
        $this->assertNull($item->fresh()->assigned_to);

        // back as a viewer → the action fails, the run is an error
        $this->project->users()->attach($this->max->id, ['role' => 'viewer']);
        $item = $this->item();
        $run = AutomationRun::latest('id')->first();
        $this->assertSame(AutomationRun::ERROR, $run->status);
        $this->assertSame('error', $run->results[0]['status']);
        $this->assertSame("{$this->max->name} may no longer edit this issue.", $run->results[0]['message']);
        $this->assertNull($item->fresh()->assigned_to);

        // the actor's account is deleted → skipped
        $this->max->delete();
        $this->assertNull($rule->fresh()->actor_id);
        $this->item();
        $this->assertStringContainsString('no longer exists', AutomationRun::latest('id')->first()->message);
    }

    public function test_a_run_for_a_switched_off_or_changed_rule_is_skipped(): void
    {
        Queue::fake();
        $rule = $this->rule(['actions' => [['type' => 'assign', 'value' => $this->max->id, 'text' => null]]]);
        $first = $this->item();
        $second = $this->item();
        [$runA, $runB] = AutomationRun::orderBy('id')->get()->all();

        $rule->update(['enabled' => false]);
        AutomationRunner::run($runA->id);
        $this->assertSame('The rule was switched off before it ran.', $runA->fresh()->message);

        $rule->update(['enabled' => true, 'version' => 2]);
        AutomationRunner::run($runB->id);
        $this->assertSame('The rule was changed before it ran.', $runB->fresh()->message);
        $this->assertNull($first->fresh()->assigned_to);
        $this->assertNull($second->fresh()->assigned_to);

        // the new version may run for the same event again (idempotency is per rule version)
        $created = AutomationEvents::start(['trigger' => 'item.created', 'project_id' => $this->project->id, 'subject_type' => 'item', 'subject_id' => $first->id, 'event_id' => "item.created:{$first->id}", 'context' => []]);
        $this->assertCount(1, $created);
        $this->assertSame(2, $created[0]->rule_version);
    }

    public function test_a_retried_run_continues_after_the_actions_that_already_finished(): void
    {
        Queue::fake();
        $this->rule(['actions' => [
            ['type' => 'comment', 'value' => null, 'text' => 'first'],
            ['type' => 'assign', 'value' => $this->max->id, 'text' => null],
        ]]);
        $item = $this->item();
        $run = AutomationRun::sole();

        // the worker died after the first action committed
        $run->update(['status' => AutomationRun::RUNNING, 'attempts' => 1, 'results' => [['action' => 'comment', 'label' => 'Add a comment', 'status' => 'ok', 'message' => 'Comment added']]]);

        (new RunAutomation($run->id))->handle();

        $run->refresh();
        $this->assertSame(AutomationRun::SUCCESS, $run->status);
        $this->assertSame(2, $run->attempts);
        $this->assertSame(0, Comment::where('item_id', $item->id)->count(), 'the finished action is not repeated');
        $this->assertSame($this->max->id, $item->fresh()->assigned_to);

        // a finished run is never executed again
        $item->update(['assigned_to' => null]);
        AutomationRunner::run($run->id);
        $this->assertNull($item->fresh()->assigned_to);
    }

    public function test_a_failed_job_marks_the_run_as_error(): void
    {
        Queue::fake();
        $this->rule(['actions' => [['type' => 'assign', 'value' => $this->max->id, 'text' => null]]]);
        $this->item();
        $run = AutomationRun::sole();

        (new RunAutomation($run->id))->failed(new RuntimeException('worker died'));

        $this->assertSame(AutomationRun::ERROR, $run->fresh()->status);
        $this->assertStringNotContainsString('worker died', (string) $run->fresh()->message);
    }

    public function test_without_an_enabled_rule_the_hook_costs_one_query(): void
    {
        $this->rule(['enabled' => false, 'actions' => [['type' => 'assign', 'value' => $this->max->id, 'text' => null]]]);
        $this->rule(['trigger' => 'item.moved', 'actions' => [['type' => 'assign', 'value' => $this->max->id, 'text' => null]]]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->item();
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $this->assertSame(1, $queries->filter(fn (string $q) => str_contains($q, '"automation_rules"'))->count());
        $this->assertSame(0, $queries->filter(fn (string $q) => str_contains($q, '"automation_runs"'))->count());
    }
}
