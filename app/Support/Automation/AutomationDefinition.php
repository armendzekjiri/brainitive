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
 *     trigger_config: {column_id?: int}
 *     conditions: list<{type: string, value: int|string|null}>
 *     actions:    list<{type: string, value: int|string|null, text: string|null}>
 */
final class AutomationDefinition
{
    /**
     * @return array{name: string, trigger: string, trigger_config: array, conditions: list<array>, actions: list<array>}
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
            'conditions' => ['nullable', 'array', 'max:'.AutomationCatalog::MAX_CONDITIONS],
            'conditions.*.type' => ['required', 'string', Rule::in(array_keys(AutomationCatalog::CONDITIONS))],
            'conditions.*.value' => ['nullable'],
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
        foreach ($validated['conditions'] ?? [] as $i => $condition) {
            $spec = AutomationCatalog::CONDITIONS[$condition['type']];
            if (! in_array($subject, $spec['subjects'], true)) {
                $errors["conditions.{$i}.type"] = "\"{$spec['label']}\" doesn't apply to this trigger.";

                continue;
            }
            $value = self::value($spec['value'], $condition['value'] ?? null);
            if ($spec['value'] !== null && ! $options->valid($spec['value'], $value, $subject)) {
                $errors["conditions.{$i}.value"] = self::invalidMessage($spec['value']);
            }
            $conditions[] = ['type' => $condition['type'], 'value' => $value];
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
            'conditions' => $conditions,
            'actions' => $actions,
        ];
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
            'member', 'assignee', 'recipient' => 'Pick a member of this project.',
            'days' => 'Enter a number of days between 0 and 365.',
            'text' => 'Enter some text (at most 200 characters).',
            default => 'Pick a value.',
        };
    }
}
