<?php

namespace App\Support\LeadForms;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The lead form format (BRAI-93): steps with fields and conditional logic. normalize() repairs
 * what the builder sends; visibleFields() and validateAnswers() evaluate the logic exactly like
 * resources/js/lib/leadForms.ts does in the browser — hidden steps and fields are skipped.
 *
 * { version: 1, steps: [{ id, title, description, visible_if, fields: [{ id, key, type, label,
 *   help, placeholder, required, maps_to, options: [{ id, label, icon }], layout, min, max,
 *   visible_if }] }] }      visible_if: { field, op, value }
 */
class LeadFormDefinition
{
    public const VERSION = 1;

    public const TYPES = ['short_text', 'long_text', 'email', 'phone', 'number', 'date', 'select', 'choice', 'multi_choice', 'rating', 'consent', 'hidden'];

    public const CHOICE_TYPES = ['select', 'choice', 'multi_choice'];

    public const OPS = ['equals', 'not_equals', 'includes', 'gt', 'lt', 'answered', 'not_answered'];

    public const MAPS = ['name', 'email', 'phone', 'company'];

    public const MAX_STEPS = 20;

    public const MAX_FIELDS_PER_STEP = 30;

    public const MAX_OPTIONS = 50;

    /**
     * @throws ValidationException when the form has no usable field
     */
    public static function normalize(mixed $input): array
    {
        $input = is_array($input) ? $input : [];
        $steps = [];
        $seenIds = [];
        $keys = [];
        $maps = [];
        $known = []; // field id => field (conditions may only use earlier fields)

        foreach (array_slice(self::list($input['steps'] ?? null), 0, self::MAX_STEPS) as $s => $step) {
            $stepId = self::id($step['id'] ?? null, 's', $seenIds);
            $earlier = $known;
            $fields = [];
            foreach (array_slice(self::list($step['fields'] ?? null), 0, self::MAX_FIELDS_PER_STEP) as $field) {
                $type = in_array($field['type'] ?? null, self::TYPES, true) ? $field['type'] : 'short_text';
                $label = self::text($field['label'] ?? null, 200) ?? 'Question';
                $id = self::id($field['id'] ?? null, 'f', $seenIds);

                $key = Str::slug(self::text($field['key'] ?? null, 60) ?? Str::limit($label, 60, ''), '_') ?: 'field';
                $base = $key;
                for ($n = 2; isset($keys[$key]); $n++) {
                    $key = "{$base}_{$n}";
                }
                $keys[$key] = true;

                $mapsTo = in_array($field['maps_to'] ?? null, self::MAPS, true) && ! isset($maps[$field['maps_to']]) ? $field['maps_to'] : null;
                if ($mapsTo === null && $type === 'email' && ! isset($maps['email'])) {
                    $mapsTo = 'email';
                }
                if ($mapsTo) {
                    $maps[$mapsTo] = true;
                }

                $options = [];
                if (in_array($type, self::CHOICE_TYPES, true)) {
                    $optionIds = [];
                    foreach (array_slice(self::list($field['options'] ?? null), 0, self::MAX_OPTIONS) as $option) {
                        $optionLabel = self::text($option['label'] ?? null, 120);
                        if ($optionLabel !== null) {
                            $options[] = [
                                'id' => self::id($option['id'] ?? null, 'o', $optionIds),
                                'label' => $optionLabel,
                                'icon' => self::text($option['icon'] ?? null, 40),
                            ];
                        }
                    }
                    if ($options === []) {
                        $options = [['id' => self::id(null, 'o', $optionIds), 'label' => 'Option 1', 'icon' => null]];
                    }
                }

                $normalized = [
                    'id' => $id,
                    'key' => $key,
                    'type' => $type,
                    'label' => $label,
                    'help' => self::text($field['help'] ?? null, 300),
                    'placeholder' => self::text($field['placeholder'] ?? null, 120),
                    'required' => $type !== 'hidden' && ! empty($field['required']),
                    'maps_to' => $mapsTo,
                    'options' => $options,
                    'layout' => in_array($field['layout'] ?? null, ['cards', 'list'], true) ? $field['layout'] : (in_array($type, ['choice', 'multi_choice'], true) ? 'cards' : 'list'),
                    'min' => is_numeric($field['min'] ?? null) ? (float) $field['min'] : null,
                    'max' => is_numeric($field['max'] ?? null) ? (float) $field['max'] : null,
                    'visible_if' => self::condition($field['visible_if'] ?? null, $known),
                ];
                $fields[] = $normalized;
                $known[$id] = $normalized;
            }

            $steps[] = [
                'id' => $stepId,
                'title' => self::text($step['title'] ?? null, 120) ?? 'Step '.($s + 1),
                'description' => self::text($step['description'] ?? null, 500),
                'visible_if' => self::condition($step['visible_if'] ?? null, $earlier), // earlier steps only
                'fields' => $fields,
            ];
        }

        if ($known === []) {
            throw ValidationException::withMessages(['definition' => 'Add at least one question to the form.']);
        }

        return ['version' => self::VERSION, 'steps' => $steps];
    }

    /**
     * Fields a visitor actually sees with these answers (hidden steps / fields left out).
     *
     * @return array<string, array> field id => field
     */
    public static function visibleFields(array $definition, array $answers): array
    {
        $visible = [];
        foreach ($definition['steps'] ?? [] as $step) {
            if (! self::passes($step['visible_if'] ?? null, $answers, $visible)) {
                continue;
            }
            foreach ($step['fields'] ?? [] as $field) {
                if (self::passes($field['visible_if'] ?? null, $answers, $visible)) {
                    $visible[$field['id']] = $field;
                }
            }
        }

        return $visible;
    }

    /**
     * Validate a submission: only visible fields count. Returns the clean answers.
     *
     * @param  array<string, mixed>  $answers  field id => raw value
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function validateAnswers(array $definition, array $answers): array
    {
        $clean = [];
        $errors = [];

        foreach (self::visibleFields($definition, $answers) as $id => $field) {
            $value = $answers[$id] ?? null;
            $empty = $value === null || $value === '' || $value === []
                || ($field['type'] === 'consent' && ! filter_var($value, FILTER_VALIDATE_BOOLEAN));
            if ($empty) {
                if ($field['required']) {
                    $errors["answers.{$id}"] = $field['type'] === 'consent' ? 'Please confirm this to continue.' : 'This question is required.';
                }

                continue;
            }

            [$ok, $result] = self::checkValue($field, $value);
            if ($ok) {
                $clean[$id] = $result;
            } else {
                $errors["answers.{$id}"] = $result;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }

    /**
     * Leads pushed by other tools (inbound webhook): `{answers: {...}}` or a flat object, keyed by
     * field key or id; choices by option id or label. Unknown keys are ignored and required
     * questions aren't enforced — but every value given must be valid. name / email / phone /
     * company without a mapped field are returned as contact.
     *
     * @return array{answers: array<string, mixed>, contact: array<string, string>}
     *
     * @throws ValidationException
     */
    public static function validateInbound(array $definition, array $input): array
    {
        $data = is_array($input['answers'] ?? null) ? $input['answers'] : $input;
        $byKey = [];
        $mapped = [];
        foreach (self::fields($definition) as $field) {
            $byKey[$field['id']] = $field;
            $byKey[$field['key']] ??= $field;
            if ($field['maps_to']) {
                $mapped[$field['maps_to']] = $field;
            }
        }

        $answers = [];
        $contact = [];
        $errors = [];
        foreach ($data as $key => $value) {
            $key = (string) $key;
            $field = $byKey[$key] ?? $mapped[$key] ?? null;
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            if ($field && $field['type'] === 'consent' && filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === false) {
                continue; // not given: no answer
            }
            if ($field === null) {
                if (in_array($key, self::MAPS, true) && is_scalar($value)) {
                    $contact[$key] = mb_substr(trim((string) $value), 0, $key === 'phone' ? 60 : 255);
                }

                continue;
            }

            if (in_array($field['type'], self::CHOICE_TYPES, true)) {
                $ids = [];
                foreach ($field['options'] as $option) {
                    $ids[mb_strtolower($option['label'])] = $option['id'];
                    $ids[mb_strtolower($option['id'])] = $option['id'];
                }
                $toId = fn ($v) => is_scalar($v) ? ($ids[mb_strtolower(trim((string) $v))] ?? (string) $v) : $v;
                $value = $field['type'] === 'multi_choice'
                    ? array_map($toId, is_array($value) ? $value : explode(',', (string) $value))
                    : $toId($value);
            }

            [$ok, $result] = self::checkValue($field, $value);
            if ($ok) {
                $answers[$field['id']] = $result;
            } else {
                $errors[$key] = $result;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        if ($answers === [] && $contact === []) {
            throw ValidationException::withMessages(['answers' => 'None of the keys match a question of this form.']);
        }
        if (isset($contact['email']) && ! filter_var($contact['email'], FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['email' => 'Enter a valid email address.']);
        }

        return ['answers' => $answers, 'contact' => $contact];
    }

    /**
     * name / email / phone / company taken from the fields mapped to them.
     *
     * @return array{name: ?string, email: ?string, phone: ?string, company: ?string}
     */
    public static function contact(array $definition, array $answers): array
    {
        $contact = array_fill_keys(self::MAPS, null);
        foreach (self::fields($definition) as $field) {
            $value = $answers[$field['id']] ?? null;
            if ($field['maps_to'] && is_scalar($value) && $value !== '') {
                $text = mb_substr(trim((string) $value), 0, $field['maps_to'] === 'phone' ? 60 : 255);
                if ($field['maps_to'] === 'email' && ! filter_var($text, FILTER_VALIDATE_EMAIL)) {
                    continue; // e.g. a short text mapped to email: never mail a non-address
                }
                $contact[$field['maps_to']] = $field['maps_to'] === 'email' ? strtolower($text) : $text;
            }
        }

        return $contact;
    }

    /**
     * @return list<array> every field of every step
     */
    public static function fields(array $definition): array
    {
        return array_merge([], ...array_map(fn ($step) => $step['fields'] ?? [], $definition['steps'] ?? []));
    }

    /**
     * Human-readable answer (option labels instead of ids) for emails, webhooks and the board.
     */
    public static function display(array $field, mixed $value): string
    {
        $labels = array_column($field['options'] ?? [], 'label', 'id');
        $label = fn ($v) => is_scalar($v) ? ($labels[(string) $v] ?? (string) $v) : '';

        // Old answers keep their shape when a question's type changes later: never assume it
        if (is_array($value)) {
            return implode(', ', array_filter(array_map($label, $value), fn ($v) => $v !== ''));
        }

        return match ($field['type']) {
            'select', 'choice', 'multi_choice' => $label($value),
            'consent' => $value ? 'Yes' : 'No',
            'rating' => is_numeric($value) ? $value.' / 5' : (string) $value,
            default => is_scalar($value) ? (string) $value : '',
        };
    }

    /**
     * Answers keyed by field key with display values (webhooks, emails, exports).
     *
     * @return array<string, array{label: string, value: mixed, display: string}>
     */
    public static function readable(array $definition, array $answers): array
    {
        $out = [];
        foreach (self::fields($definition) as $field) {
            if (array_key_exists($field['id'], $answers)) {
                $out[$field['key']] = [
                    'label' => $field['label'],
                    'value' => $answers[$field['id']],
                    'display' => self::display($field, $answers[$field['id']]),
                ];
            }
        }

        return $out;
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @return array{0: bool, 1: mixed} [valid, clean value or error message]
     */
    private static function checkValue(array $field, mixed $value): array
    {
        $optionIds = array_column($field['options'] ?? [], 'id');

        switch ($field['type']) {
            case 'email':
                $email = is_string($value) ? strtolower(trim($value)) : '';

                return filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($email) <= 255 ? [true, $email] : [false, 'Enter a valid email address.'];
            case 'phone':
                return is_string($value) && preg_match('/^[0-9+()\-.\s\/]{4,40}$/', trim($value)) ? [true, trim($value)] : [false, 'Enter a valid phone number.'];
            case 'number':
                if (! is_numeric($value) || is_bool($value)) {
                    return [false, 'Enter a number.'];
                }
                $number = (float) $value;
                if (($field['min'] !== null && $number < $field['min']) || ($field['max'] !== null && $number > $field['max'])) {
                    return [false, 'Enter a number between '.($field['min'] ?? '…').' and '.($field['max'] ?? '…').'.'];
                }

                return [true, floor($number) === $number && abs($number) < PHP_INT_MAX ? (int) $number : $number];
            case 'date':
                return is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])
                    ? [true, $value] : [false, 'Enter a date.'];
            case 'select':
            case 'choice':
                return is_string($value) && in_array($value, $optionIds, true) ? [true, $value] : [false, 'Choose one of the options.'];
            case 'multi_choice':
                $picked = is_array($value) ? array_values(array_unique(array_filter($value, 'is_string'))) : [];
                if ($picked === [] || array_diff($picked, $optionIds) !== []) {
                    return [false, 'Choose from the options.'];
                }
                if (($field['min'] !== null && count($picked) < $field['min']) || ($field['max'] !== null && count($picked) > $field['max'])) {
                    return [false, 'Choose between '.(int) ($field['min'] ?? 1).' and '.(int) ($field['max'] ?? count($optionIds)).' options.'];
                }

                return [true, $picked];
            case 'rating':
                return is_numeric($value) && (int) $value >= 1 && (int) $value <= 5 ? [true, (int) $value] : [false, 'Choose 1 to 5.'];
            case 'consent':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true ? [true, true] : [false, 'Consent must be given (true).'];
            case 'long_text':
                return is_string($value) && mb_strlen($value) <= 10000 ? [true, trim($value)] : [false, 'Use at most 10,000 characters.'];
            default: // short_text, hidden
                return is_scalar($value) && ! is_bool($value) && mb_strlen((string) $value) <= 500 ? [true, trim((string) $value)] : [false, 'Use at most 500 characters.'];
        }
    }

    /**
     * @param  array<string, array>  $visible  fields shown so far (a condition on a hidden field counts as unanswered)
     */
    private static function passes(?array $condition, array $answers, array $visible): bool
    {
        if ($condition === null) {
            return true;
        }
        $answer = isset($visible[$condition['field']]) ? ($answers[$condition['field']] ?? null) : null;
        $answered = ! ($answer === null || $answer === '' || $answer === [] || $answer === false);
        $value = $condition['value'];

        return match ($condition['op']) {
            'answered' => $answered,
            'not_answered' => ! $answered,
            'equals' => $answered && (is_array($answer) ? in_array($value, $answer, true) : (string) $answer === (string) $value),
            'not_equals' => ! $answered || (is_array($answer) ? ! in_array($value, $answer, true) : (string) $answer !== (string) $value),
            'includes' => $answered && (is_array($answer) ? in_array($value, $answer, true) : str_contains(mb_strtolower((string) $answer), mb_strtolower((string) $value))),
            'gt' => is_numeric($answer) && is_numeric($value) && (float) $answer > (float) $value,
            'lt' => is_numeric($answer) && is_numeric($value) && (float) $answer < (float) $value,
            default => true,
        };
    }

    /**
     * @param  array<string, array>  $known  fields a condition may refer to
     */
    private static function condition(mixed $condition, array $known): ?array
    {
        if (! is_array($condition) || ! is_string($condition['field'] ?? null) || ! isset($known[$condition['field']])
            || ! in_array($condition['op'] ?? null, self::OPS, true)) {
            return null;
        }
        $value = $condition['value'] ?? null;

        return [
            'field' => $condition['field'],
            'op' => $condition['op'],
            'value' => is_scalar($value) ? mb_substr((string) $value, 0, 200) : null,
        ];
    }

    /**
     * @return list<array>
     */
    private static function list(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    /**
     * @param  array<string, true>  $seen
     */
    private static function id(mixed $value, string $prefix, array &$seen): string
    {
        $id = is_string($value) && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $value) ? $value : null;
        while ($id === null || isset($seen[$id])) {
            $id = $prefix.'_'.Str::lower(Str::random(8));
        }
        $seen[$id] = true;

        return $id;
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, $max);
    }
}
