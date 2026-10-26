<?php

namespace Tests\Feature\Automation;

use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Board;
use App\Models\Comment;
use App\Models\Helpdesk;
use App\Models\Item;
use App\Models\ItemField;
use App\Models\ItemFieldOption;
use App\Models\ItemFieldValue;
use App\Models\ItemPriority;
use App\Models\ItemType;
use App\Models\Label;
use App\Models\Ticket;
use App\Models\TicketContact;
use App\Models\User;
use App\Support\Automation\AutomationCatalog;
use App\Support\Automation\AutomationConditions;
use App\Support\Automation\AutomationDefinition;
use App\Support\Automation\AutomationEvents;
use App\Support\Automation\AutomationOptions;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * BRAI-144: conditions as {field, operator, value} with "all" / "any": every field × operator
 * family (custom fields and time zone sensitive dates included), legacy {type, value} rules and
 * their data migration, validation and the sentences rules are shown with.
 */
class AutomationConditionsTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    private User $admin;

    private User $max;

    private AutomationOptions $options;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        $this->admin = $this->projectMember('admin');
        $this->max = $this->projectMember('editor');
        $this->options = new AutomationOptions($this->project);
    }

    private function priority(string $name): ItemPriority
    {
        return ItemPriority::create(['name' => $name, 'slug' => Str::slug($name).'-'.Str::random(4), 'color' => '#f00', 'order' => 2]);
    }

    private function label(string $name): Label
    {
        return Label::create(['project_id' => $this->project->id, 'name' => $name, 'color' => '#f00']);
    }

    /** @param  array{0: string, 1: string, 2?: mixed}  $condition  [field, operator, value] */
    private function holds(array $condition, Item|Ticket $subject, ?string $timezone = null): bool
    {
        return AutomationConditions::passes(['field' => $condition[0], 'operator' => $condition[1], 'value' => $condition[2] ?? null], $subject, new AutomationOptions($this->project), $timezone);
    }

    /** Each case: [condition, expected]. */
    private function assertCases(Item|Ticket $subject, array $cases, ?string $timezone = null): void
    {
        foreach ($cases as [$condition, $expected]) {
            $this->assertSame($expected, $this->holds($condition, $subject, $timezone), json_encode($condition).' should be '.($expected ? 'true' : 'false'));
        }
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
            'actions' => [['type' => 'comment', 'value' => null, 'text' => 'ran']],
        ]);
    }

    private function field(string $type, string $name, array $options = []): ItemField
    {
        $field = ItemField::create(['project_id' => $this->project->id, 'name' => $name, 'type' => $type, 'position' => 1]);
        foreach ($options as $i => $label) {
            ItemFieldOption::create(['item_field_id' => $field->id, 'label' => $label, 'position' => $i]);
        }

        return $field->load('options');
    }

    private function definition(array $conditions, array $extra = []): array
    {
        return $extra + [
            'name' => 'Rule',
            'trigger' => 'item.created',
            'trigger_config' => [],
            'conditions' => $conditions,
            'actions' => [['type' => 'assign', 'value' => $this->editor->id]],
        ];
    }

    private function validationErrors(array $definition): array
    {
        try {
            AutomationDefinition::validate($definition, new AutomationOptions($this->project));
        } catch (ValidationException $e) {
            return array_keys($e->errors());
        }

        return [];
    }

    // ── field × operator families ───────────────────────────────────────────

    public function test_select_fields_is_is_not_any_of_none_of_and_empty(): void
    {
        $high = $this->priority('High');
        $urgent = $this->priority('Urgent');
        $type = ItemType::create(['name' => 'Bug', 'slug' => 'bug-conditions', 'is_active' => true]);
        $otherBoard = Board::factory()->create(['project_id' => $this->project->id]);
        $todo = $this->column('to-do');
        $done = $this->column('done');
        $item = $this->item(['priority_id' => $high->id, 'item_type_id' => $type->id]);
        $bare = $this->item();

        $this->assertCases($item, [
            [['board', 'is', $this->board->id], true],
            [['board', 'is', $otherBoard->id], false],
            [['board', 'is_not', $otherBoard->id], true],
            [['board', 'in', [$otherBoard->id, $this->board->id]], true],
            [['board', 'not_in', [$this->board->id]], false],
            [['column', 'is', $todo], true],
            [['column', 'in', [$done]], false],
            [['column', 'not_in', [$done]], true],
            [['item_type', 'is', $type->id], true],
            [['item_type', 'is_not', $type->id], false],
            [['priority', 'in', [$high->id, $urgent->id]], true],
            [['priority', 'not_in', [$urgent->id]], true],
            [['priority', 'is_empty'], false],
            [['priority', 'is_not_empty'], true],
        ]);
        $this->assertCases($bare, [
            [['priority', 'is_empty'], true],
            [['priority', 'is', $high->id], false],
            [['priority', 'is_not', $high->id], true],   // "not" holds for an empty value
            [['priority', 'not_in', [$high->id]], true],
            [['item_type', 'in', [$type->id]], false],
        ]);
    }

    public function test_labels_has_has_not_any_all_none_and_empty(): void
    {
        $bug = $this->label('Bug');
        $duplicate = $this->label('Duplicate');
        $ux = $this->label('UX');
        $item = $this->item();
        $item->labels()->attach([$bug->id, $ux->id]);
        $bare = $this->item();

        $this->assertCases($item, [
            [['labels', 'has', $bug->id], true],
            [['labels', 'has', $duplicate->id], false],
            [['labels', 'has_not', $duplicate->id], true],
            [['labels', 'has_any', [$duplicate->id, $ux->id]], true],
            [['labels', 'has_all', [$bug->id, $ux->id]], true],
            [['labels', 'has_all', [$bug->id, $duplicate->id]], false],
            [['labels', 'has_none', [$duplicate->id]], true],
            [['labels', 'has_none', [$duplicate->id, $bug->id]], false],
            [['labels', 'is_empty'], false],
            [['labels', 'is_not_empty'], true],
        ]);
        $this->assertCases($bare, [
            [['labels', 'is_empty'], true],
            [['labels', 'has_none', [$bug->id]], true],
            [['labels', 'has_any', [$bug->id]], false],
        ]);
    }

    public function test_members_assignee_and_reporter(): void
    {
        $item = $this->item(['assigned_to' => $this->max->id, 'reporter_id' => null, 'created_by' => $this->admin->id]);
        $bare = $this->item(['assigned_to' => null]);

        $this->assertCases($item, [
            [['assignee', 'is', $this->max->id], true],
            [['assignee', 'is_not', $this->max->id], false],
            [['assignee', 'in', [$this->editor->id, $this->max->id]], true],
            [['assignee', 'is_not_empty'], true],
            [['reporter', 'is', $this->admin->id], true], // no reporter: the creator
            [['reporter', 'is_empty'], false],
        ]);
        $this->assertCases($bare, [
            [['assignee', 'is_empty'], true],
            [['assignee', 'is_not', $this->max->id], true],
            [['assignee', 'in', [$this->max->id]], false],
            [['reporter', 'is', $this->editor->id], true],
        ]);
    }

    public function test_title_and_description_text_is_case_insensitive_and_plain(): void
    {
        $item = $this->item(['title' => 'Payment fails on Checkout', 'description' => '<p>The <strong>Stripe</strong>&nbsp;webhook</p><p>times out &amp; retries</p>']);
        $bare = $this->item(['title' => 'x', 'description' => '<p></p>']);

        $this->assertCases($item, [
            [['title', 'contains', 'CHECKOUT'], true],
            [['title', 'not_contains', 'refund'], true],
            [['title', 'not_contains', 'payment'], false],
            [['title', 'equals', 'payment fails on checkout'], true],
            [['title', 'equals', 'payment fails'], false],
            [['title', 'starts_with', 'payment'], true],
            [['title', 'starts_with', 'fails'], false],
            [['description', 'contains', 'stripe webhook'], true], // tags out, entities decoded
            [['description', 'contains', 'out & retries'], true],
            [['description', 'contains', '<strong>'], false],
            [['description', 'is_not_empty'], true],
        ]);
        $this->assertCases($bare, [
            [['description', 'is_empty'], true],
            [['description', 'not_contains', 'stripe'], true],
            [['title', 'is_empty'], false],
        ]);
    }

    public function test_dates_empty_overdue_within_before_after(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00:00', 'UTC'));
        $overdue = $this->item(['due_date' => '2026-11-09', 'start_date' => '2026-11-01']);
        $soon = $this->item(['due_date' => '2026-11-13']);
        $dueLaterToday = $this->item(['due_date' => '2026-11-10', 'due_time' => '15:00']);
        $dueEarlierToday = $this->item(['due_date' => '2026-11-10', 'due_time' => '09:00']);
        $done = $this->item(['due_date' => '2026-11-01', 'completed_at' => now()]);
        $bare = $this->item();

        $this->assertCases($overdue, [
            [['due_date', 'is_overdue'], true],
            [['due_date', 'is_not_empty'], true],
            [['due_date', 'within_next_days', ['days' => 7]], false], // already past
            [['due_date', 'before_date', '2026-11-10'], true],
            [['due_date', 'after_date', '2026-11-09'], false],
            [['start_date', 'after_date', '2026-10-31'], true],
            [['start_date', 'is_empty'], false],
        ]);
        $this->assertCases($soon, [
            [['due_date', 'is_overdue'], false],
            [['due_date', 'within_next_days', ['days' => 3]], true],
            [['due_date', 'within_next_days', ['days' => 2]], false],
            [['start_date', 'is_empty'], true],
        ]);
        $this->assertTrue($this->holds(['due_date', 'is_overdue'], $dueEarlierToday));
        $this->assertFalse($this->holds(['due_date', 'is_overdue'], $dueLaterToday));
        $this->assertTrue($this->holds(['due_date', 'within_next_days', ['days' => 0]], $dueLaterToday));
        $this->assertFalse($this->holds(['due_date', 'is_overdue'], $done), 'a finished issue is not overdue');
        $this->assertCases($bare, [
            [['due_date', 'is_empty'], true],
            [['due_date', 'is_overdue'], false],
            [['due_date', 'before_date', '2030-01-01'], false],
            [['due_date', 'within_next_days', ['days' => 365]], false],
        ]);
    }

    public function test_dates_are_read_in_the_rule_actors_time_zone(): void
    {
        // 12:00 UTC on the 10th is already 01:00 on the 11th in Auckland
        $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00:00', 'UTC'));
        $dueTenth = $this->item(['due_date' => '2026-11-10']);
        $dueEleventh = $this->item(['due_date' => '2026-11-11']);

        $this->assertFalse($this->holds(['due_date', 'is_overdue'], $dueTenth, 'UTC'));
        $this->assertTrue($this->holds(['due_date', 'is_overdue'], $dueTenth, 'Pacific/Auckland'));
        $this->assertFalse($this->holds(['due_date', 'within_next_days', ['days' => 0]], $dueEleventh, 'UTC'));
        $this->assertTrue($this->holds(['due_date', 'within_next_days', ['days' => 0]], $dueEleventh, 'Pacific/Auckland'));

        // the engine uses the actor's zone
        $this->editor->forceFill(['timezone' => 'Pacific/Auckland'])->save();
        $this->rule(['name' => 'nz', 'conditions' => [['field' => 'due_date', 'operator' => 'is_overdue', 'value' => null]], 'actions' => [['type' => 'comment', 'value' => null, 'text' => 'late in nz']]]);
        $item = $this->item(['due_date' => '2026-11-10']);
        $this->assertSame(1, Comment::where('item_id', $item->id)->where('body', '<p>late in nz</p>')->count());
    }

    public function test_custom_fields_by_type(): void
    {
        $text = $this->field('text', 'Customer');
        $number = $this->field('number', 'Size');
        $select = $this->field('select', 'Tier', ['Gold', 'Silver', 'Bronze']);
        $checkbox = $this->field('checkbox', 'Approved');
        $date = $this->field('date', 'Launch');
        [$gold, $silver] = [$select->options[0]->id, $select->options[1]->id];

        $item = $this->item();
        ItemFieldValue::create(['item_id' => $item->id, 'item_field_id' => $text->id, 'value_text' => 'ACME Corp']);
        ItemFieldValue::create(['item_id' => $item->id, 'item_field_id' => $number->id, 'value_number' => 5]);
        ItemFieldValue::create(['item_id' => $item->id, 'item_field_id' => $select->id, 'option_id' => $gold]);
        ItemFieldValue::create(['item_id' => $item->id, 'item_field_id' => $checkbox->id, 'value_bool' => true]);
        ItemFieldValue::create(['item_id' => $item->id, 'item_field_id' => $date->id, 'value_date' => '2026-12-01']);
        $bare = $this->item();
        $c = fn (ItemField $f) => "custom:{$f->id}";

        $this->assertCases($item, [
            [[$c($text), 'contains', 'acme'], true],
            [[$c($text), 'not_contains', 'acme'], false],
            [[$c($text), 'equals', 'acme corp'], true],
            [[$c($text), 'is_not_empty'], true],
            [[$c($number), 'eq', 5], true],
            [[$c($number), 'neq', 5], false],
            [[$c($number), 'gt', 4.5], true],
            [[$c($number), 'gte', 5], true],
            [[$c($number), 'lt', 5], false],
            [[$c($number), 'lte', 5], true],
            [[$c($select), 'is', $gold], true],
            [[$c($select), 'is_not', $gold], false],
            [[$c($select), 'in', [$silver, $gold]], true],
            [[$c($select), 'is_empty'], false],
            [[$c($checkbox), 'is_checked'], true],
            [[$c($checkbox), 'is_unchecked'], false],
            [[$c($date), 'after_date', '2026-11-30'], true],
            [[$c($date), 'before_date', '2026-12-01'], false],
            [[$c($date), 'is_not_empty'], true],
        ]);
        $this->assertCases($bare, [
            [[$c($text), 'is_empty'], true],
            [[$c($number), 'is_empty'], true],
            [[$c($number), 'gt', -1], false],
            [[$c($number), 'neq', 5], true],
            [[$c($select), 'is_empty'], true],
            [[$c($checkbox), 'is_unchecked'], true],
            [[$c($date), 'is_empty'], true],
        ]);

        // an operator another type has, and a field of another project: fail closed
        $this->assertFalse($this->holds([$c($checkbox), 'contains', 'x'], $item));
        $foreign = ItemField::create(['project_id' => Board::factory()->create()->project_id, 'name' => 'Foreign', 'type' => 'text', 'position' => 1]);
        ItemFieldValue::create(['item_id' => $item->id, 'item_field_id' => $foreign->id, 'value_text' => 'x']);
        $this->assertFalse($this->holds(["custom:{$foreign->id}", 'is_not_empty'], $item));
    }

    public function test_a_removed_custom_field_fails_closed_and_reads_field_removed(): void
    {
        $field = $this->field('text', 'Customer');
        $item = $this->item();
        ItemFieldValue::create(['item_id' => $item->id, 'item_field_id' => $field->id, 'value_text' => 'ACME']);
        $condition = ['field' => "custom:{$field->id}", 'operator' => 'is_not_empty', 'value' => null];
        $this->assertTrue($this->holds(["custom:{$field->id}", 'is_not_empty'], $item));

        $field->update(['archived_at' => now()]);
        $options = new AutomationOptions($this->project);
        $this->assertFalse(AutomationConditions::passes($condition, $item, $options));
        $this->assertSame('Customer (field removed)', AutomationConditions::describe($condition, $options, 'item'));
        $this->assertContains('conditions.0.field', $this->validationErrors($this->definition([$condition])));

        $id = $field->id;
        $field->delete();
        $options = new AutomationOptions($this->project);
        $this->assertSame('Custom field (field removed)', AutomationConditions::describe(['field' => "custom:{$id}", 'operator' => 'is_empty', 'value' => null], $options, 'item'));
        $this->assertFalse(AutomationConditions::passes(['field' => "custom:{$id}", 'operator' => 'is_empty', 'value' => null], $item, $options));
    }

    public function test_ticket_conditions_fit_tickets_only(): void
    {
        $helpdesk = Helpdesk::create(['project_id' => $this->project->id, 'name' => 'Support', 'slug' => 'support', 'created_by' => $this->editor->id]);
        $contact = TicketContact::firstOrCreate(['email' => 'customer@example.com'], ['uuid' => (string) Str::uuid()]);
        $high = $this->priority('High');
        $ticket = $helpdesk->tickets()->create([
            'subject' => 'Printer on fire', 'description' => '<p>Smoke everywhere</p>', 'column_id' => $helpdesk->columns()->first()->id,
            'ticket_contact_id' => $contact->id, 'key' => $this->project->getNextTicketKey(), 'position' => 0, 'priority_id' => $high->id,
        ]);

        $this->assertCases($ticket, [
            [['helpdesk', 'is', $helpdesk->id], true],
            [['helpdesk', 'not_in', [$helpdesk->id]], false],
            [['priority', 'in', [$high->id]], true],
            [['title', 'contains', 'fire'], true],
            [['description', 'contains', 'smoke'], true],
            [['assignee', 'is_empty'], true],
            [['labels', 'is_empty'], false], // issue-only field on a ticket: fails closed
            [['board', 'is_not', 1], false],
        ]);

        $errors = $this->validationErrors($this->definition([['field' => 'labels', 'operator' => 'is_empty']], ['trigger' => 'ticket.created', 'actions' => [['type' => 'assign', 'value' => $this->editor->id]]]));
        $this->assertSame(['conditions.0.field'], $errors);
    }

    // ── all / any ───────────────────────────────────────────────────────────

    public function test_match_all_needs_every_condition_and_any_needs_one(): void
    {
        $bug = $this->label('Bug');
        $high = $this->priority('High');
        $conditions = [
            ['field' => 'labels', 'operator' => 'has_none', 'value' => [$bug->id]],
            ['field' => 'priority', 'operator' => 'in', 'value' => [$high->id]],
        ];
        $this->rule(['name' => 'all', 'condition_match' => 'all', 'conditions' => $conditions, 'actions' => [['type' => 'comment', 'value' => null, 'text' => 'all']]]);
        $this->rule(['name' => 'any', 'condition_match' => 'any', 'conditions' => $conditions, 'actions' => [['type' => 'comment', 'value' => null, 'text' => 'any']]]);

        $comments = fn (Item $item) => Comment::where('item_id', $item->id)->orderBy('body')->pluck('body')->all();

        $both = $this->item(['priority_id' => $high->id]);
        $this->assertSame(['<p>all</p>', '<p>any</p>'], $comments($both));

        $one = AutomationEvents::defer(function () use ($bug, $high) {
            $item = $this->item(['priority_id' => $high->id]);
            $item->labels()->attach($bug->id);

            return $item;
        });
        $this->assertSame(['<p>any</p>'], $comments($one));

        $none = AutomationEvents::defer(function () use ($bug) {
            $item = $this->item();
            $item->labels()->attach($bug->id);

            return $item;
        });
        $this->assertSame([], $comments($none));

        // the run keeps which conditions passed
        $run = AutomationRun::where('subject_id', $one->id)->sole();
        $this->assertSame([
            'match' => 'any',
            'items' => [
                ['label' => 'Labels has none of Bug', 'passed' => false],
                ['label' => 'Priority is any of High', 'passed' => true],
            ],
        ], $run->conditions);
    }

    public function test_no_conditions_always_match_with_all_or_any(): void
    {
        $this->assertTrue(AutomationConditions::matches([], 'all'));
        $this->assertTrue(AutomationConditions::matches([], 'any'));
        $this->assertFalse(AutomationConditions::matches([['passed' => false]], 'any'));
        $this->assertTrue(AutomationConditions::matches([['passed' => false], ['passed' => true]], 'any'));
        $this->assertFalse(AutomationConditions::matches([['passed' => false], ['passed' => true]], 'all'));
    }

    // ── legacy rules ────────────────────────────────────────────────────────

    public function test_legacy_conditions_are_read_in_the_new_shape(): void
    {
        $label = $this->label('Bug');
        $legacy = [
            ['type' => 'board', 'value' => 7],
            ['type' => 'helpdesk', 'value' => 3],
            ['type' => 'item_type', 'value' => 2],
            ['type' => 'priority', 'value' => 4],
            ['type' => 'label', 'value' => $label->id],
            ['type' => 'assignee_empty', 'value' => null],
            ['type' => 'assignee_is', 'value' => 9],
            ['type' => 'column', 'value' => 5],
            ['type' => 'title_contains', 'value' => 'fire'],
        ];

        $this->assertSame([
            ['field' => 'board', 'operator' => 'is', 'value' => 7],
            ['field' => 'helpdesk', 'operator' => 'is', 'value' => 3],
            ['field' => 'item_type', 'operator' => 'is', 'value' => 2],
            ['field' => 'priority', 'operator' => 'is', 'value' => 4],
            ['field' => 'labels', 'operator' => 'has', 'value' => $label->id],
            ['field' => 'assignee', 'operator' => 'is_empty', 'value' => null],
            ['field' => 'assignee', 'operator' => 'is', 'value' => 9],
            ['field' => 'column', 'operator' => 'is', 'value' => 5],
            ['field' => 'title', 'operator' => 'contains', 'value' => 'fire'],
        ], AutomationDefinition::normalizeConditions($legacy));

        $rule = $this->rule(['conditions' => [['type' => 'label', 'value' => $label->id], ['type' => 'assignee_empty']]]);
        $this->assertSame('all', $rule->fresh()->conditionMatch());
        $this->assertSame(['Labels has Bug', 'Assignee is empty'], array_map(
            fn (array $c) => AutomationConditions::describe($c, $this->options, 'item'),
            $rule->conditions,
        ));

        // the builder gets the new shape
        $this->actingAs($this->admin)->get(route('automations.edit', [$this->workspace->slug, $this->project->slug, $rule->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('rule.conditions.0', ['field' => 'labels', 'operator' => 'has', 'value' => $label->id])
                ->where('rule.condition_match', 'all')
                ->where('rule.summary.if', ['Labels has Bug', 'Assignee is empty'])
                ->has('catalog.conditionFields')
                ->has('catalog.operators')
                ->has('options.customFields'));
    }

    public function test_the_data_migration_rewrites_legacy_rules_once(): void
    {
        $legacy = $this->rule(['conditions' => [['type' => 'board', 'value' => $this->board->id], ['type' => 'assignee_empty', 'value' => null], ['type' => 'title_contains', 'value' => 'fire']], 'actions' => [['type' => 'comment', 'value' => null, 'text' => 'legacy']]]);
        $current = $this->rule(['conditions' => [['field' => 'labels', 'operator' => 'has_none', 'value' => [1, 2]]]]);
        $empty = $this->rule(['conditions' => []]);
        $updatedAt = DB::table('automation_rules')->where('id', $current->id)->value('updated_at');

        $migration = require database_path('migrations/2026_10_31_180100_convert_automation_conditions.php');
        $migration->up();
        $migration->up(); // idempotent

        $this->assertSame([
            ['field' => 'board', 'operator' => 'is', 'value' => $this->board->id],
            ['field' => 'assignee', 'operator' => 'is_empty', 'value' => null],
            ['field' => 'title', 'operator' => 'contains', 'value' => 'fire'],
        ], $legacy->fresh()->conditions);
        $this->assertSame([['field' => 'labels', 'operator' => 'has_none', 'value' => [1, 2]]], $current->fresh()->conditions);
        $this->assertSame([], $empty->fresh()->conditions);
        $this->assertSame($updatedAt, DB::table('automation_rules')->where('id', $current->id)->value('updated_at'));
        $this->assertSame(1, $legacy->fresh()->version, 'the meaning did not change: same version');

        // the converted rule still runs
        $item = $this->item(['title' => 'Printer on fire']);
        $this->assertSame(1, Comment::where('item_id', $item->id)->where('body', '<p>legacy</p>')->count());
        $other = $this->item(['title' => 'Printer is fine']);
        $this->assertSame(0, Comment::where('item_id', $other->id)->where('body', '<p>legacy</p>')->count());
    }

    public function test_old_runs_without_conditions_still_render(): void
    {
        $rule = $this->rule(['conditions' => [['type' => 'title_contains', 'value' => 'zzz']]]);
        AutomationRun::create([
            'automation_rule_id' => $rule->id, 'project_id' => $this->project->id, 'rule_version' => 1, 'event_id' => 'old',
            'trigger' => 'item.created', 'subject_type' => 'item', 'subject_id' => 1, 'subject_label' => 'BRAI-1 Old',
            'status' => AutomationRun::SUCCESS, 'results' => [],
        ]);

        $this->actingAs($this->admin)->get(route('automations.edit', [$this->workspace->slug, $this->project->slug, $rule->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('runs.0.conditions', null)->where('rule.summary.if', ['Title contains "zzz"']));
    }

    // ── validation ──────────────────────────────────────────────────────────

    public function test_validation_checks_field_operator_and_value(): void
    {
        $label = $this->label('Bug');
        $foreignLabel = Label::create(['project_id' => Board::factory()->create()->project_id, 'name' => 'x', 'color' => '#000']);
        $number = $this->field('number', 'Size');
        $select = $this->field('select', 'Tier', ['Gold']);
        $otherSelect = $this->field('select', 'Other', ['Nope']);

        $cases = [
            'unknown field' => [['field' => 'mood', 'operator' => 'is', 'value' => 1], 'conditions.0.field'],
            'operator of another field' => [['field' => 'labels', 'operator' => 'contains', 'value' => 'x'], 'conditions.0.operator'],
            'overdue is for due dates' => [['field' => 'start_date', 'operator' => 'is_overdue'], 'conditions.0.operator'],
            'foreign label' => [['field' => 'labels', 'operator' => 'has', 'value' => $foreignLabel->id], 'conditions.0.value'],
            'list with a foreign label' => [['field' => 'labels', 'operator' => 'has_any', 'value' => [$label->id, $foreignLabel->id]], 'conditions.0.value'],
            'empty list' => [['field' => 'labels', 'operator' => 'has_none', 'value' => []], 'conditions.0.value'],
            'list instead of id' => [['field' => 'board', 'operator' => 'is', 'value' => [$this->board->id]], 'conditions.0.value'],
            'too long list' => [['field' => 'labels', 'operator' => 'has_any', 'value' => range(1, AutomationCatalog::MAX_LIST + 1)], 'conditions.0.value'],
            'empty text' => [['field' => 'title', 'operator' => 'contains', 'value' => '  '], 'conditions.0.value'],
            'long text' => [['field' => 'title', 'operator' => 'contains', 'value' => str_repeat('a', 201)], 'conditions.0.value'],
            'not a number' => [['field' => "custom:{$number->id}", 'operator' => 'gt', 'value' => 'five'], 'conditions.0.value'],
            'not a date' => [['field' => 'due_date', 'operator' => 'before_date', 'value' => '2026-02-30'], 'conditions.0.value'],
            'days out of range' => [['field' => 'due_date', 'operator' => 'within_next_days', 'value' => ['days' => 400]], 'conditions.0.value'],
            'option of another field' => [['field' => "custom:{$select->id}", 'operator' => 'is', 'value' => $otherSelect->options[0]->id], 'conditions.0.value'],
            'unknown custom field' => [['field' => 'custom:999999', 'operator' => 'is_empty'], 'conditions.0.field'],
        ];
        foreach ($cases as $name => [$condition, $error]) {
            $this->assertSame([$error], $this->validationErrors($this->definition([$condition])), $name);
        }
        $this->assertSame(['conditions'], $this->validationErrors($this->definition(array_fill(0, AutomationCatalog::MAX_CONDITIONS + 1, ['field' => 'title', 'operator' => 'is_empty']))));
        $this->assertSame(['condition_match'], $this->validationErrors($this->definition([], ['condition_match' => 'some'])));

        // valid values are normalised: ids to ints, duplicates out, empty-type operators lose their value
        $definition = AutomationDefinition::validate($this->definition([
            ['field' => 'labels', 'operator' => 'has_none', 'value' => [(string) $label->id, $label->id]],
            ['field' => 'assignee', 'operator' => 'is_empty', 'value' => 5],
            ['field' => 'due_date', 'operator' => 'within_next_days', 'value' => 3],
            ['field' => "custom:{$number->id}", 'operator' => 'gte', 'value' => '2.5'],
            ['field' => "custom:{$select->id}", 'operator' => 'in', 'value' => [$select->options[0]->id]],
            ['field' => 'title', 'operator' => 'starts_with', 'value' => '  Bug: '],
        ], ['condition_match' => 'any']), new AutomationOptions($this->project));

        $this->assertSame('any', $definition['condition_match']);
        $this->assertSame([
            ['field' => 'labels', 'operator' => 'has_none', 'value' => [$label->id]],
            ['field' => 'assignee', 'operator' => 'is_empty', 'value' => null],
            ['field' => 'due_date', 'operator' => 'within_next_days', 'value' => ['days' => 3]],
            ['field' => "custom:{$number->id}", 'operator' => 'gte', 'value' => 2.5],
            ['field' => "custom:{$select->id}", 'operator' => 'in', 'value' => [$select->options[0]->id]],
            ['field' => 'title', 'operator' => 'starts_with', 'value' => 'Bug:'],
        ], $definition['conditions']);
    }

    public function test_saving_stores_match_and_a_match_change_is_a_new_version(): void
    {
        $label = $this->label('Bug');
        $url = fn (string $name, array $extra = []) => route($name, [$this->workspace->slug, $this->project->slug, ...$extra]);
        $definition = $this->definition([['field' => 'labels', 'operator' => 'has_none', 'value' => [$label->id]]], ['condition_match' => 'all']);

        $this->actingAs($this->admin)->post($url('automations.store'), $definition)->assertSessionHasNoErrors();
        $rule = AutomationRule::sole();
        $this->assertSame('all', $rule->condition_match);

        $this->actingAs($this->admin)->put($url('automations.update', [$rule->id]), ['condition_match' => 'any'] + $definition)->assertSessionHasNoErrors();
        $this->assertSame(['any', 2], [$rule->fresh()->condition_match, $rule->fresh()->version]);

        // the dry run uses the match too
        $item = $this->item(['title' => 'x']);
        $item->labels()->attach($label->id);
        $this->actingAs($this->admin)->postJson($url('automations.preview'), ['subject_key' => $item->key, 'condition_match' => 'any', 'conditions' => [
            ['field' => 'labels', 'operator' => 'has_none', 'value' => [$label->id]],
            ['field' => 'title', 'operator' => 'equals', 'value' => 'x'],
        ]] + $definition)->assertOk()->assertJsonPath('matches', true)->assertJsonPath('match', 'any')
            ->assertJsonPath('conditions.0.passed', false)->assertJsonPath('conditions.1.passed', true);
    }

    // ── sentences ───────────────────────────────────────────────────────────

    public function test_describe_reads_as_a_sentence(): void
    {
        $bug = $this->label('Bug');
        $duplicate = $this->label('Duplicate');
        $high = $this->priority('High');
        $urgent = $this->priority('Urgent');
        $size = $this->field('number', 'Size');
        $tier = $this->field('select', 'Tier', ['Gold', 'Silver']);
        $approved = $this->field('checkbox', 'Approved');
        $options = new AutomationOptions($this->project);
        $say = fn (string $field, string $operator, mixed $value = null, string $subject = 'item') => AutomationConditions::describe(['field' => $field, 'operator' => $operator, 'value' => $value], $options, $subject);

        $this->assertSame('Labels has none of Bug, Duplicate', $say('labels', 'has_none', [$bug->id, $duplicate->id]));
        $this->assertSame('Labels has Bug', $say('labels', 'has', $bug->id));
        $this->assertSame("Labels doesn't have Bug", $say('labels', 'has_not', $bug->id));
        $this->assertSame('Labels is empty', $say('labels', 'is_empty'));
        $this->assertSame('Priority is any of High, Urgent', $say('priority', 'in', [$high->id, $urgent->id]));
        $this->assertSame('Priority is none of (deleted)', $say('priority', 'not_in', [999999]));
        $this->assertSame('Board is not '.$this->board->name, $say('board', 'is_not', $this->board->id));
        $this->assertSame('Assignee is '.$this->max->name, $say('assignee', 'is', $this->max->id));
        $this->assertSame('Reporter is not empty', $say('reporter', 'is_not_empty'));
        $this->assertSame('Title starts with "Bug:"', $say('title', 'starts_with', 'Bug:'));
        $this->assertSame("Description doesn't contain \"secret\"", $say('description', 'not_contains', 'secret'));
        $this->assertSame('Due date is overdue', $say('due_date', 'is_overdue'));
        $this->assertSame('Due date is within the next 3 days', $say('due_date', 'within_next_days', ['days' => 3]));
        $this->assertSame('Due date is within the next 1 day', $say('due_date', 'within_next_days', ['days' => 1]));
        $this->assertSame('Due date is today', $say('due_date', 'within_next_days', ['days' => 0]));
        $this->assertSame('Start date is before 2026-12-01', $say('start_date', 'before_date', '2026-12-01'));
        $this->assertSame('Size ≥ 2.5', $say("custom:{$size->id}", 'gte', 2.5));
        $this->assertSame('Size = 5', $say("custom:{$size->id}", 'eq', 5));
        $this->assertSame('Tier is any of Gold, Silver', $say("custom:{$tier->id}", 'in', $tier->options->pluck('id')->all()));
        $this->assertSame('Approved is checked', $say("custom:{$approved->id}", 'is_checked'));
        $this->assertSame('Approved (field changed)', $say("custom:{$approved->id}", 'gt', 1));
        $this->assertSame('Title contains "fire"', AutomationConditions::describe(['type' => 'title_contains', 'value' => 'fire'], $options, 'ticket'));
    }
}
