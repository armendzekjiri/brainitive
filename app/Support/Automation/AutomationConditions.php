<?php

namespace App\Support\Automation;

use App\Models\Item;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * IF: the rule's conditions, each {field, operator, value} (BRAI-144; before: {type, value}, read
 * through AutomationDefinition::normalizeCondition). The rule's `condition_match` says whether all
 * of them or any one must hold. Checked against the subject's current state, once before a run is
 * created and again when it starts (v1.40.0, BRAI-63).
 *
 * Dates ("today", overdue) are wall-clock values compared in the rule actor's time zone, like the
 * "Due date passed" trigger. A custom field that was archived or deleted, or whose type no longer
 * fits the operator, fails closed. "Not" operators hold for an empty value.
 */
final class AutomationConditions
{
    /**
     * @param  list<array>  $conditions  stored conditions (new or legacy shape)
     * @return list<array{field: string, operator: string, label: string, passed: bool}>
     */
    public static function check(array $conditions, Item|Ticket $subject, AutomationOptions $options, ?string $timezone = null): array
    {
        $subjectType = (string) AutomationSubject::type($subject);
        $facts = new ConditionFacts($subject, $timezone ?? config('app.timezone'));

        return array_map(function (array $condition) use ($subject, $options, $subjectType, $facts) {
            $condition = AutomationDefinition::normalizeCondition($condition);

            return [
                'field' => $condition['field'],
                'operator' => $condition['operator'],
                'label' => self::describe($condition, $options, $subjectType),
                'passed' => self::evaluate($condition, $subject, $options, $facts),
            ];
        }, array_values($conditions));
    }

    /** Do the checked conditions let the rule run? No conditions: always. */
    public static function matches(array $checked, ?string $match = AutomationCatalog::MATCH_ALL): bool
    {
        if ($checked === []) {
            return true;
        }

        return $match === AutomationCatalog::MATCH_ANY
            ? collect($checked)->contains(fn (array $c) => $c['passed'])
            : collect($checked)->every(fn (array $c) => $c['passed']);
    }

    /**
     * What a run keeps of its check (shown in the run history).
     *
     * @return array{match: string, items: list<array{label: string, passed: bool}>}
     */
    public static function summary(array $checked, ?string $match): array
    {
        return [
            'match' => AutomationDefinition::normalizeMatch($match),
            'items' => array_map(fn (array $c) => ['label' => $c['label'], 'passed' => $c['passed']], $checked),
        ];
    }

    /** @param  list<array{passed: bool}>  $checked */
    public static function allPassed(array $checked): bool
    {
        return self::matches($checked, AutomationCatalog::MATCH_ALL);
    }

    /** The time zone a rule's dates are read in: its actor's (as the "Due date passed" trigger). */
    public static function timezoneOf(?User $actor): string
    {
        return $actor?->localTimezone() ?? config('app.timezone');
    }

    public static function passes(array $condition, Item|Ticket $subject, AutomationOptions $options, ?string $timezone = null): bool
    {
        return self::evaluate(
            AutomationDefinition::normalizeCondition($condition),
            $subject,
            $options,
            new ConditionFacts($subject, $timezone ?? config('app.timezone')),
        );
    }

    /**
     * The field's spec for this project: label, source, operators, subjects (+ `custom` = the custom
     * field). Null when the field is unknown, or a custom field that is archived / gone.
     */
    public static function spec(string $field, AutomationOptions $options): ?array
    {
        if (isset(AutomationCatalog::CONDITION_FIELDS[$field])) {
            return AutomationCatalog::CONDITION_FIELDS[$field] + ['custom' => null];
        }
        $custom = self::customField($field, $options);
        if (! $custom || $custom['archived'] || ! isset(AutomationCatalog::CUSTOM_FIELD_OPERATORS[$custom['type']])) {
            return null;
        }

        return [
            'label' => $custom['name'],
            'source' => $custom['type'] === 'select' ? 'custom_option' : null,
            'operators' => AutomationCatalog::CUSTOM_FIELD_OPERATORS[$custom['type']],
            'subjects' => [AutomationCatalog::SUBJECT_ITEM],
            'custom' => $custom,
        ];
    }

    /** The custom field a "custom:<id>" key points at (archived ones too), or null. */
    public static function customField(string $field, AutomationOptions $options): ?array
    {
        if (! str_starts_with($field, AutomationCatalog::CUSTOM_PREFIX)) {
            return null;
        }
        $id = substr($field, strlen(AutomationCatalog::CUSTOM_PREFIX));

        return ctype_digit($id) ? $options->customField((int) $id) : null;
    }

    private static function evaluate(array $condition, Item|Ticket $subject, AutomationOptions $options, ConditionFacts $facts): bool
    {
        $spec = self::spec($condition['field'], $options);
        $operator = $condition['operator'];
        if (! $spec || ! in_array($operator, $spec['operators'], true)
            || ! in_array(AutomationSubject::type($subject), $spec['subjects'], true)) {
            return false; // unknown / removed field, or an operator its type no longer has: fail closed
        }
        $value = $condition['value'];
        $actual = $facts->get($condition['field'], $spec['custom']);
        $isOne = fn () => $actual !== null && (int) $actual === (int) $value;
        $isIn = fn () => $actual !== null && in_array((int) $actual, self::ids($value), true);
        $contains = fn () => is_string($value) && $value !== '' && mb_stripos((string) $actual, $value) !== false;
        $equalNumber = fn () => $actual !== null && is_numeric($value) && abs((float) $actual - (float) $value) < 1e-9;

        return match ($operator) {
            // one value of a list (ids)
            'is' => $isOne(),
            'is_not' => ! $isOne(),
            'in' => $isIn(),
            'not_in' => ! $isIn(),
            // a set of values (labels)
            'has' => in_array((int) $value, (array) $actual, true),
            'has_not' => ! in_array((int) $value, (array) $actual, true),
            'has_any' => array_intersect(self::ids($value), (array) $actual) !== [],
            'has_all' => self::ids($value) !== [] && array_diff(self::ids($value), (array) $actual) === [],
            'has_none' => array_intersect(self::ids($value), (array) $actual) === [],
            // text, case-insensitive
            'contains' => $contains(),
            'not_contains' => ! $contains(),
            'equals' => is_string($value) && mb_strtolower(trim((string) $actual)) === mb_strtolower(trim($value)),
            'starts_with' => is_string($value) && $value !== '' && str_starts_with(mb_strtolower(ltrim((string) $actual)), mb_strtolower($value)),
            // numbers
            'eq' => $equalNumber(),
            'neq' => ! $equalNumber(),
            'gt' => $actual !== null && is_numeric($value) && (float) $actual > (float) $value,
            'gte' => $actual !== null && is_numeric($value) && (float) $actual >= (float) $value,
            'lt' => $actual !== null && is_numeric($value) && (float) $actual < (float) $value,
            'lte' => $actual !== null && is_numeric($value) && (float) $actual <= (float) $value,
            // dates: Y-m-d strings compare as dates
            'is_overdue' => $facts->overdue(),
            'within_next_days' => $actual !== null && $actual >= $facts->today()
                && $actual <= $facts->today(fn (CarbonImmutable $d) => $d->addDays(self::days($value))),
            'before_date' => $actual !== null && is_string($value) && $actual < $value,
            'after_date' => $actual !== null && is_string($value) && $actual > $value,
            // checkbox
            'is_checked' => $actual === true,
            'is_unchecked' => $actual !== true,
            // emptiness
            'is_empty' => self::isEmpty($actual),
            'is_not_empty' => ! self::isEmpty($actual),
            default => false,
        };
    }

    /**
     * "Labels has none of Bug, Duplicate", "Priority is any of High, Urgent", "Due date is within
     * the next 3 days", "Size ≥ 5". Ids that are gone read "(deleted)"; a removed custom field
     * "(field removed)".
     */
    public static function describe(array $condition, AutomationOptions $options, string $subjectType): string
    {
        $condition = AutomationDefinition::normalizeCondition($condition);
        $field = $condition['field'];
        $spec = self::spec($field, $options);
        if (! $spec) {
            if (! str_starts_with($field, AutomationCatalog::CUSTOM_PREFIX)) {
                return $field;
            }
            $custom = self::customField($field, $options);

            return ($custom ? $custom['name'] : 'Custom field').' (field removed)';
        }

        $operator = AutomationCatalog::OPERATORS[$condition['operator']] ?? null;
        if (! $operator || ! in_array($condition['operator'], $spec['operators'], true)) {
            return $spec['label'].' ('.($spec['custom'] ? 'field changed' : 'unknown check').')';
        }

        $value = $condition['value'];
        if ($condition['operator'] === 'within_next_days' && self::days($value) === 0) {
            return $spec['label'].' is today';
        }
        $name = fn (mixed $id) => self::valueName($spec, $id, $options, $subjectType) ?? '(deleted)';
        $words = match ($operator['value']) {
            null => null,
            'one' => $name($value),
            'many' => implode(', ', array_map($name, self::ids($value))) ?: '(nothing)',
            'text' => '"'.$value.'"',
            'number' => self::number($value),
            'days' => self::days($value) === 1 ? '1 day' : self::days($value).' days',
            default => (string) $value,
        };

        return $spec['label'].' '.$operator['label'].($words === null ? '' : ' '.$words);
    }

    private static function valueName(array $spec, mixed $id, AutomationOptions $options, string $subjectType): ?string
    {
        if ($spec['source'] === 'custom_option') {
            $option = collect($spec['custom']['options'])->firstWhere('id', is_numeric($id) ? (int) $id : null);

            return $option['name'] ?? null;
        }

        return $spec['source'] ? $options->name($spec['source'], $id, $subjectType) : null;
    }

    /** @return list<int> */
    public static function ids(mixed $value): array
    {
        return is_array($value) ? array_values(array_map('intval', array_filter($value, 'is_numeric'))) : [];
    }

    /** {days: n} (stored) or n. */
    public static function days(mixed $value): int
    {
        return (int) (is_array($value) ? ($value['days'] ?? 0) : $value);
    }

    private static function number(mixed $value): string
    {
        $float = (float) $value;

        return floor($float) === $float && abs($float) < 1e15
            ? (string) (int) $float
            : rtrim(rtrim(number_format($float, 6, '.', ''), '0'), '.');
    }

    private static function isEmpty(mixed $actual): bool
    {
        return match (true) {
            $actual === null, $actual === [] => true,
            is_string($actual) => trim($actual) === '',
            default => false,
        };
    }
}
