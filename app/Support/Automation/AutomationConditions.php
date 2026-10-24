<?php

namespace App\Support\Automation;

use App\Models\Item;
use App\Models\Ticket;

/**
 * IF: every condition must hold (AND). Checked against the subject's current state, once before a
 * run is created and again when it starts (v1.40.0, BRAI-63).
 */
final class AutomationConditions
{
    /**
     * @param  list<array{type: string, value: mixed}>  $conditions
     * @return list<array{type: string, label: string, passed: bool}>
     */
    public static function check(array $conditions, Item|Ticket $subject, AutomationOptions $options): array
    {
        $subjectType = AutomationSubject::type($subject);

        return array_map(fn (array $condition) => [
            'type' => $condition['type'],
            'label' => self::describe($condition, $options, $subjectType),
            'passed' => self::passes($condition, $subject),
        ], $conditions);
    }

    /** @param  list<array{passed: bool}>  $checked */
    public static function allPassed(array $checked): bool
    {
        return collect($checked)->every(fn (array $c) => $c['passed']);
    }

    public static function passes(array $condition, Item|Ticket $subject): bool
    {
        $value = $condition['value'] ?? null;

        return match ($condition['type']) {
            'board' => $subject instanceof Item && (int) $subject->board_id === (int) $value,
            'helpdesk' => $subject instanceof Ticket && (int) $subject->helpdesk_id === (int) $value,
            'item_type' => $subject instanceof Item && (int) $subject->item_type_id === (int) $value,
            'priority' => (int) $subject->priority_id === (int) $value,
            'label' => $subject instanceof Item && $subject->labels()->where('labels.id', (int) $value)->exists(),
            'assignee_empty' => $subject->assigned_to === null,
            'assignee_is' => (int) $subject->assigned_to === (int) $value,
            'column' => (int) $subject->column_id === (int) $value,
            'title_contains' => is_string($value) && $value !== '' && mb_stripos(AutomationSubject::title($subject), $value) !== false,
            default => false,
        };
    }

    public static function describe(array $condition, AutomationOptions $options, string $subjectType): string
    {
        $spec = AutomationCatalog::CONDITIONS[$condition['type']] ?? null;
        if (! $spec) {
            return $condition['type'];
        }
        if ($spec['value'] === null) {
            return $spec['label'];
        }

        return $spec['label'].' '.($options->name($spec['value'], $condition['value'] ?? null, $subjectType) ?? '(deleted)');
    }
}
