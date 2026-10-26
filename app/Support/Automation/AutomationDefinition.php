<?php

namespace App\Support\Automation;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Validates and normalises a rule's definition from the builder (v1.40.0, BRAI-63). Every id must be
 * one of the project's (AutomationOptions); conditions and actions must fit the trigger's subject.
 * The result is the only shape stored in automation_rules:
 *
 *     trigger_config:  {column_id?: int}
 *     condition_match: "all" | "any"
 *     conditions:      list<{field: string, operator: string, value: int|float|string|list<int>|{days: int}|null}>
 *     actions:         list<{type: string, value: int|string|null, text: string|null}>
 *
 * Conditions saved before BRAI-144 were {type, value}; normalizeCondition() reads them (and input in
 * that shape) as {field, operator, value}.
 */
final class AutomationDefinition
{
    /**
     * @return array{name: string, trigger: string, trigger_config: array, condition_match: string, conditions: list<array>, actions: list<array>}
     *
     * @throws ValidationException
     */
    public static function validate(array $input, AutomationOptions $options): array
    {
        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:120'],
            'trigger' => ['required', 'string', Rule::in(array_keys(AutomationCatalog::TRIGGERS))],
            'trigger_config' => ['nullable', 'array'],
            'trigger_config.column_id' => ['nullable', 'integer'],
            'condition_match' => ['nullable', 'string', Rule::in([AutomationCatalog::MATCH_ALL, AutomationCatalog::MATCH_ANY])],
            'conditions' => ['nullable', 'array', 'max:'.AutomationCatalog::MAX_CONDITIONS],
            'conditions.*' => ['array'],
            'actions' => ['required', 'array', 'min:1', 'max:'.AutomationCatalog::MAX_ACTIONS],
            'actions.*.type' => ['required', 'string', Rule::in(array_keys(AutomationCatalog::ACTIONS))],
            'actions.*.value' => ['nullable'],
            'actions.*.text' => ['nullable', 'string', 'max:2000'],
        ], [
            'actions.required' => 'Add at least one action.',
            'actions.min' => 'Add at least one action.',
        ])->validate();

        $trigger = $validated['trigger'];
        $subject = AutomationCatalog::subjectOf($trigger);
        $errors = [];

        $config = [];
        $columnId = $validated['trigger_config']['column_id'] ?? null;
        if ($trigger === 'item.moved' && $columnId !== null) {
            if ($options->boardOfColumn((int) $columnId) === null) {
                $errors['trigger_config.column_id'] = 'Pick a column of this project.';
            }
            $config['column_id'] = (int) $columnId;
        }

        $conditions = [];
        foreach (array_values($validated['conditions'] ?? []) as $i => $condition) {
            [$condition, $error] = self::condition(self::normalizeCondition($condition), $options, $subject);
            if ($error) {
                $errors["conditions.{$i}.".$error[0]] = $error[1];
            }
            $conditions[] = $condition;
        }

        $actions = [];
        foreach ($validated['actions'] as $i => $action) {
            $spec = AutomationCatalog::ACTIONS[$action['type']];
            if (! in_array($subject, $spec['subjects'], true)) {
                $errors["actions.{$i}.type"] = "\"{$spec['label']}\" doesn't apply to this trigger.";

                continue;
            }
            $value = self::value($spec['value'], $action['value'] ?? null);
            if ($spec['value'] !== null && ! $options->valid($spec['value'], $value, $subject)) {
                $errors["actions.{$i}.value"] = self::invalidMessage($spec['value']);
            }
            $text = trim((string) ($action['text'] ?? ''));
            if ($spec['text'] === 'required' && $text === '') {
                $errors["actions.{$i}.text"] = 'Write the text.';
            }
            $actions[] = ['type' => $action['type'], 'value' => $value, 'text' => $spec['text'] === null || $text === '' ? null : $text];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'name' => trim($validated['name']),
            'trigger' => $trigger,
            'trigger_config' => $config,
            'condition_match' => self::normalizeMatch($validated['condition_match'] ?? null),
            'conditions' => $conditions,
            'actions' => $actions,
        ];
    }

    /**
     * Any stored or submitted condition as {field, operator, value}: the BRAI-144 shape as it is,
     * a legacy {type, value} through AutomationCatalog::LEGACY_CONDITIONS (an unknown legacy type
     * keeps its name as the field and fails closed).
     *
     * @return array{field: string, operator: string, value: mixed}
     */
    public static function normalizeCondition(array $condition): array
    {
        if (array_key_exists('field', $condition) || ! array_key_exists('type', $condition)) {
            return [
                'field' => is_string($condition['field'] ?? null) ? $condition['field'] : '',
                'operator' => is_string($condition['operator'] ?? null) ? $condition['operator'] : '',
                'value' => $condition['value'] ?? null,
            ];
        }
        $type = (string) $condition['type'];
        [$field, $operator] = AutomationCatalog::LEGACY_CONDITIONS[$type] ?? [$type, 'is'];

        return [
            'field' => $field,
            'operator' => $operator,
            'value' => AutomationCatalog::OPERATORS[$operator]['value'] === null ? null : ($condition['value'] ?? null),
        ];
    }

    /** @return list<array{field: string, operator: string, value: mixed}> */
    public static function normalizeConditions(?array $conditions): array
    {
        return array_map(fn ($c) => self::normalizeCondition((array) $c), array_values($conditions ?? []));
    }

    public static function normalizeMatch(?string $match): string
    {
        return $match === AutomationCatalog::MATCH_ANY ? AutomationCatalog::MATCH_ANY : AutomationCatalog::MATCH_ALL;
    }

    /**
     * One condition checked against the catalog and the project: the field fits the subject, the
     * operator fits the field, the value fits the operator (ids of this project, list sizes, text
     * length, dates).
     *
     * @return array{0: array{field: string, operator: string, value: mixed}, 1: array{0: string, 1: string}|null}
     */
    private static function condition(array $condition, AutomationOptions $options, string $subject): array
    {
        ['field' => $field, 'operator' => $operator, 'value' => $raw] = $condition;
        $stored = ['field' => $field, 'operator' => $operator, 'value' => null];

        $spec = AutomationConditions::spec($field, $options);
        if (! $spec) {
            return [$stored, ['field', str_starts_with($field, AutomationCatalog::CUSTOM_PREFIX)
                ? 'This custom field was removed. Pick another field or remove the condition.'
                : 'Pick a field.']];
        }
        if (! in_array($subject, $spec['subjects'], true)) {
            return [$stored, ['field', "\"{$spec['label']}\" doesn't apply to this trigger."]];
        }
        if (! in_array($operator, $spec['operators'], true)) {
            return [$stored, ['operator', "Pick how to compare {$spec['label']}."]];
        }

        $shape = AutomationCatalog::OPERATORS[$operator]['value'];
        $source = $spec['source'];
        $validId = fn (mixed $id) => is_int($id) && ($source === 'custom_option'
            ? collect($spec['custom']['options'])->contains('id', $id)
            : $options->valid($source, $id, $subject));
        $invalid = fn (string $message) => [$stored, ['value', $message]];

        switch ($shape) {
            case null:
                return [$stored, null];
            case 'one':
                $value = self::id($raw);
                $stored['value'] = $value;

                return $validId($value) ? [$stored, null] : $invalid(self::invalidMessage($source === 'custom_option' ? 'option' : (string) $source));
            case 'many':
                $ids = is_array($raw) ? array_map(self::id(...), array_values($raw)) : [];
                $stored['value'] = array_values(array_unique(array_filter($ids, 'is_int')));
                if ($ids === []) {
                    return $invalid('Pick at least one.');
                }
                if (count($stored['value']) > AutomationCatalog::MAX_LIST) {
                    return $invalid('Pick at most '.AutomationCatalog::MAX_LIST.'.');
                }
                $kind = $source === 'custom_option' ? 'option' : $source;

                return ! in_array(null, $ids, true) && collect($stored['value'])->every($validId)
                    ? [$stored, null]
                    : $invalid(self::invalidMessage($kind));
            case 'text':
                $text = is_scalar($raw) ? trim((string) $raw) : '';
                $stored['value'] = $text;

                return $text !== '' && mb_strlen($text) <= AutomationCatalog::MAX_TEXT ? [$stored, null] : $invalid(self::invalidMessage('text'));
            case 'number':
                if (! is_numeric($raw) || ! is_finite((float) $raw) || abs((float) $raw) > 1e12) {
                    return $invalid('Enter a number.');
                }
                $stored['value'] = (float) $raw == (int) $raw ? (int) $raw : (float) $raw;

                return [$stored, null];
            case 'date':
                $date = is_string($raw) ? trim($raw) : '';
                $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                if (! $parsed || $parsed->format('Y-m-d') !== $date) {
                    return $invalid('Pick a date.');
                }
                $stored['value'] = $date;

                return [$stored, null];
            case 'days':
                $days = is_array($raw) ? ($raw['days'] ?? null) : $raw;
                if (! is_numeric($days) || (int) $days != $days || (int) $days < 0 || (int) $days > 365) {
                    return $invalid(self::invalidMessage('days'));
                }
                $stored['value'] = ['days' => (int) $days];

                return [$stored, null];
        }

        return $invalid('Pick a value.');
    }

    private static function id(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }

    /** Ids become ints; keywords (reporter / assignee) and text stay strings; no value = null. */
    private static function value(?string $kind, mixed $value): int|string|null
    {
        if ($kind === null || $value === null || $value === '') {
            return null;
        }
        if ($kind === 'text') {
            return is_scalar($value) ? trim((string) $value) : null;
        }
        if (is_numeric($value) && (string) (int) $value === (string) $value) {
            return (int) $value;
        }

        return is_string($value) ? $value : null;
    }

    private static function invalidMessage(string $kind): string
    {
        return match ($kind) {
            'board' => 'Pick a board of this project.',
            'helpdesk' => 'Pick a helpdesk of this project.',
            'column' => 'Pick a column of this project.',
            'item_type' => 'Pick an issue type of this project.',
            'priority' => 'Pick a priority.',
            'label' => 'Pick a label of this project.',
            'option' => 'Pick an option of this field.',
            'member', 'assignee', 'recipient' => 'Pick a member of this project.',
            'days' => 'Enter a number of days between 0 and 365.',
            'text' => 'Enter some text (at most '.AutomationCatalog::MAX_TEXT.' characters).',
            default => 'Pick a value.',
        };
    }
}
