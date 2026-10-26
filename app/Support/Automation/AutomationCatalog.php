<?php

namespace App\Support\Automation;

/**
 * What a rule can be made of (v1.40.0, BRAI-63). Every trigger has a subject (an issue or a ticket);
 * conditions and actions list the subjects they work on, and AutomationDefinition refuses the rest.
 *
 * Action value kinds (what `value` holds): board, helpdesk, column (board column for issues, helpdesk
 * column for tickets), item_type, priority, label, member (a user with project access), assignee
 * (member or "reporter"), recipient (member or "assignee"), days (0..365), text. `text` on an action
 * is free text with {{item.key}} / {{item.title}} placeholders.
 *
 * Conditions (BRAI-144) are {field, operator, value}: CONDITION_FIELDS (+ custom fields of issues)
 * × OPERATORS; the rule's `condition_match` says whether all or any must hold.
 */
final class AutomationCatalog
{
    public const MAX_CONDITIONS = 10;

    /** Bounded work per run: a rule has at most this many actions. */
    public const MAX_ACTIONS = 10;

    public const SUBJECT_ITEM = 'item';

    public const SUBJECT_TICKET = 'ticket';

    /** trigger => label, subject, help */
    public const TRIGGERS = [
        'item.created' => ['label' => 'Issue created', 'subject' => self::SUBJECT_ITEM, 'help' => 'A new issue or subtask is added to a board.'],
        'item.moved' => ['label' => 'Issue moved', 'subject' => self::SUBJECT_ITEM, 'help' => 'An issue moves to another column (optionally: to one column).'],
        'item.assigned' => ['label' => 'Issue assigned', 'subject' => self::SUBJECT_ITEM, 'help' => 'Someone is assigned to an issue.'],
        'item.commented' => ['label' => 'Comment added', 'subject' => self::SUBJECT_ITEM, 'help' => 'Someone comments on an issue.'],
        'item.due' => ['label' => 'Due date passed', 'subject' => self::SUBJECT_ITEM, 'help' => 'An open issue passes its due date (checked every few minutes, once per issue and due date).'],
        'ticket.created' => ['label' => 'Ticket created', 'subject' => self::SUBJECT_TICKET, 'help' => 'A helpdesk ticket arrives (agent, portal, widget or email).'],
    ];

    /** DomainEvents name => trigger (item.due comes from the scheduler, not from an event). */
    public const EVENT_TRIGGERS = [
        'item.created' => 'item.created',
        'item.moved' => 'item.moved',
        'item.assigned' => 'item.assigned',
        'item.commented' => 'item.commented',
        'ticket.created' => 'ticket.created',
    ];

    private const BOTH = [self::SUBJECT_ITEM, self::SUBJECT_TICKET];

    /** A rule's conditions: all must hold, or any one of them (BRAI-144). */
    public const MATCH_ALL = 'all';

    public const MATCH_ANY = 'any';

    /** Most ids in one any-of / all-of / none-of list. */
    public const MAX_LIST = 50;

    /** Most characters of a text value. */
    public const MAX_TEXT = 200;

    /** Custom fields of issues are fields "custom:<item_field id>". */
    public const CUSTOM_PREFIX = 'custom:';

    /**
     * Operators (BRAI-144): label (the sentence word) and what `value` holds:
     * null = nothing, one = one id, many = a list of ids, text, number, date (Y-m-d), days ({days: n}).
     * "Not" operators (is_not, not_in, has_not, has_none, not_contains) hold for an empty value.
     */
    public const OPERATORS = [
        'is' => ['label' => 'is', 'value' => 'one'],
        'is_not' => ['label' => 'is not', 'value' => 'one'],
        'in' => ['label' => 'is any of', 'value' => 'many'],
        'not_in' => ['label' => 'is none of', 'value' => 'many'],
        'has' => ['label' => 'has', 'value' => 'one'],
        'has_not' => ['label' => 'doesn\'t have', 'value' => 'one'],
        'has_any' => ['label' => 'has any of', 'value' => 'many'],
        'has_all' => ['label' => 'has all of', 'value' => 'many'],
        'has_none' => ['label' => 'has none of', 'value' => 'many'],
        'contains' => ['label' => 'contains', 'value' => 'text'],
        'not_contains' => ['label' => 'doesn\'t contain', 'value' => 'text'],
        'equals' => ['label' => 'equals', 'value' => 'text'],
        'starts_with' => ['label' => 'starts with', 'value' => 'text'],
        'eq' => ['label' => '=', 'value' => 'number'],
        'neq' => ['label' => '≠', 'value' => 'number'],
        'gt' => ['label' => '>', 'value' => 'number'],
        'gte' => ['label' => '≥', 'value' => 'number'],
        'lt' => ['label' => '<', 'value' => 'number'],
        'lte' => ['label' => '≤', 'value' => 'number'],
        'is_overdue' => ['label' => 'is overdue', 'value' => null],
        'within_next_days' => ['label' => 'is within the next', 'value' => 'days'],
        'before_date' => ['label' => 'is before', 'value' => 'date'],
        'after_date' => ['label' => 'is after', 'value' => 'date'],
        'is_checked' => ['label' => 'is checked', 'value' => null],
        'is_unchecked' => ['label' => 'is not checked', 'value' => null],
        'is_empty' => ['label' => 'is empty', 'value' => null],
        'is_not_empty' => ['label' => 'is not empty', 'value' => null],
    ];

    private const SELECT_OPS = ['is', 'is_not', 'in', 'not_in'];

    private const MULTI_OPS = ['has', 'has_not', 'has_any', 'has_all', 'has_none', 'is_empty', 'is_not_empty'];

    private const MEMBER_OPS = ['is', 'is_not', 'in', 'is_empty', 'is_not_empty'];

    private const TEXT_OPS = ['contains', 'not_contains', 'equals', 'starts_with', 'is_empty', 'is_not_empty'];

    private const DATE_OPS = ['is_empty', 'is_not_empty', 'within_next_days', 'before_date', 'after_date'];

    /**
     * Condition fields (BRAI-144): label, `source` = the option list ids come from (AutomationOptions
     * kind; null = free value), operators, subjects. Custom fields come per project, see
     * CUSTOM_FIELD_OPERATORS.
     */
    public const CONDITION_FIELDS = [
        'board' => ['label' => 'Board', 'source' => 'board', 'operators' => self::SELECT_OPS, 'subjects' => [self::SUBJECT_ITEM]],
        'helpdesk' => ['label' => 'Helpdesk', 'source' => 'helpdesk', 'operators' => self::SELECT_OPS, 'subjects' => [self::SUBJECT_TICKET]],
        'column' => ['label' => 'Column', 'source' => 'column', 'operators' => self::SELECT_OPS, 'subjects' => self::BOTH],
        'item_type' => ['label' => 'Type', 'source' => 'item_type', 'operators' => self::SELECT_OPS, 'subjects' => [self::SUBJECT_ITEM]],
        'priority' => ['label' => 'Priority', 'source' => 'priority', 'operators' => [...self::SELECT_OPS, 'is_empty', 'is_not_empty'], 'subjects' => self::BOTH],
        'labels' => ['label' => 'Labels', 'source' => 'label', 'operators' => self::MULTI_OPS, 'subjects' => [self::SUBJECT_ITEM]],
        'assignee' => ['label' => 'Assignee', 'source' => 'member', 'operators' => self::MEMBER_OPS, 'subjects' => self::BOTH],
        'reporter' => ['label' => 'Reporter', 'source' => 'member', 'operators' => self::MEMBER_OPS, 'subjects' => [self::SUBJECT_ITEM]],
        'title' => ['label' => 'Title', 'source' => null, 'operators' => self::TEXT_OPS, 'subjects' => self::BOTH],
        'description' => ['label' => 'Description', 'source' => null, 'operators' => self::TEXT_OPS, 'subjects' => self::BOTH],
        'due_date' => ['label' => 'Due date', 'source' => null, 'operators' => ['is_empty', 'is_not_empty', 'is_overdue', 'within_next_days', 'before_date', 'after_date'], 'subjects' => [self::SUBJECT_ITEM]],
        'start_date' => ['label' => 'Start date', 'source' => null, 'operators' => self::DATE_OPS, 'subjects' => [self::SUBJECT_ITEM]],
    ];

    /**
     * Custom field type (ItemField::TYPES) => operators. Issues only. (Custom fields have no
     * multi-select type; one would take MULTI_OPS like labels.)
     */
    public const CUSTOM_FIELD_OPERATORS = [
        'text' => ['contains', 'not_contains', 'equals', 'is_empty', 'is_not_empty'],
        'number' => ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'is_empty', 'is_not_empty'],
        'select' => ['is', 'is_not', 'in', 'is_empty', 'is_not_empty'],
        'checkbox' => ['is_checked', 'is_unchecked'],
        'date' => self::DATE_OPS,
    ];

    /**
     * Before BRAI-144 a condition was {type, value} with one fixed operator. They are read as
     * {field, operator} (AutomationDefinition::normalizeCondition) and rewritten by a data migration.
     */
    public const LEGACY_CONDITIONS = [
        'board' => ['board', 'is'],
        'helpdesk' => ['helpdesk', 'is'],
        'item_type' => ['item_type', 'is'],
        'priority' => ['priority', 'is'],
        'label' => ['labels', 'has'],
        'assignee_empty' => ['assignee', 'is_empty'],
        'assignee_is' => ['assignee', 'is'],
        'column' => ['column', 'is'],
        'title_contains' => ['title', 'contains'],
    ];

    /** action => label, value kind, text (null | optional | required), subjects */
    public const ACTIONS = [
        'assign' => ['label' => 'Assign to', 'value' => 'assignee', 'text' => null, 'subjects' => self::BOTH],
        'move' => ['label' => 'Move to column', 'value' => 'column', 'text' => null, 'subjects' => [self::SUBJECT_ITEM]],
        'set_priority' => ['label' => 'Set priority', 'value' => 'priority', 'text' => null, 'subjects' => self::BOTH],
        'set_type' => ['label' => 'Set type', 'value' => 'item_type', 'text' => null, 'subjects' => [self::SUBJECT_ITEM]],
        'set_due_date' => ['label' => 'Set due date', 'value' => 'days', 'text' => null, 'subjects' => [self::SUBJECT_ITEM]],
        'add_label' => ['label' => 'Add label', 'value' => 'label', 'text' => null, 'subjects' => [self::SUBJECT_ITEM]],
        'comment' => ['label' => 'Add a comment', 'value' => null, 'text' => 'required', 'subjects' => [self::SUBJECT_ITEM]],
        'notify' => ['label' => 'Notify', 'value' => 'recipient', 'text' => 'optional', 'subjects' => self::BOTH],
        'create_issue' => ['label' => 'Create a linked issue on', 'value' => 'board', 'text' => null, 'subjects' => [self::SUBJECT_TICKET]],
    ];

    public static function subjectOf(string $trigger): string
    {
        return self::TRIGGERS[$trigger]['subject'] ?? self::SUBJECT_ITEM;
    }

    /** The catalog for the builder (plain arrays). */
    public static function forFrontend(): array
    {
        $list = fn (array $entries) => array_map(
            fn (string $key, array $entry) => ['key' => $key] + $entry,
            array_keys($entries),
            array_values($entries),
        );

        return [
            'triggers' => $list(self::TRIGGERS),
            'conditionFields' => $list(self::CONDITION_FIELDS),
            'operators' => $list(self::OPERATORS),
            'customFieldOperators' => self::CUSTOM_FIELD_OPERATORS,
            'actions' => $list(self::ACTIONS),
            'maxConditions' => self::MAX_CONDITIONS,
            'maxList' => self::MAX_LIST,
            'maxActions' => self::MAX_ACTIONS,
        ];
    }
}
