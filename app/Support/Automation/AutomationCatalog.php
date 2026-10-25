<?php

namespace App\Support\Automation;

/**
 * What a rule can be made of (v1.40.0, BRAI-63). Every trigger has a subject (an issue or a ticket);
 * conditions and actions list the subjects they work on, and AutomationDefinition refuses the rest.
 *
 * Value kinds (what `value` holds): board, helpdesk, column (board column for issues, helpdesk column
 * for tickets), item_type, priority, label, member (a user with project access), assignee (member or
 * "reporter"), recipient (member or "assignee"), days (0..365), text. `text` on an action is free text
 * with {{item.key}} / {{item.title}} placeholders.
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

    /** condition => label, value kind (null = none), subjects */
    public const CONDITIONS = [
        'board' => ['label' => 'Board is', 'value' => 'board', 'subjects' => [self::SUBJECT_ITEM]],
        'helpdesk' => ['label' => 'Helpdesk is', 'value' => 'helpdesk', 'subjects' => [self::SUBJECT_TICKET]],
        'item_type' => ['label' => 'Type is', 'value' => 'item_type', 'subjects' => [self::SUBJECT_ITEM]],
        'priority' => ['label' => 'Priority is', 'value' => 'priority', 'subjects' => self::BOTH],
        'label' => ['label' => 'Has label', 'value' => 'label', 'subjects' => [self::SUBJECT_ITEM]],
        'assignee_empty' => ['label' => 'Assignee is empty', 'value' => null, 'subjects' => self::BOTH],
        'assignee_is' => ['label' => 'Assignee is', 'value' => 'member', 'subjects' => self::BOTH],
        'column' => ['label' => 'Column is', 'value' => 'column', 'subjects' => self::BOTH],
        'title_contains' => ['label' => 'Title contains', 'value' => 'text', 'subjects' => self::BOTH],
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
            'conditions' => $list(self::CONDITIONS),
            'actions' => $list(self::ACTIONS),
            'maxConditions' => self::MAX_CONDITIONS,
            'maxActions' => self::MAX_ACTIONS,
        ];
    }
}
