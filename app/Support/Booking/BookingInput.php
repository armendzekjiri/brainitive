<?php

namespace App\Support\Booking;

use App\Models\BookingType;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Cleans what the editor sends for a meeting type (BRAI-158): hours, overrides, rules, questions and
 * location in the one stored shape BookingRules and the public page read. Unknown keys are dropped.
 */
final class BookingInput
{
    public const MAX_QUESTIONS = 10;

    public const QUESTION_TYPES = ['text', 'textarea', 'phone', 'choice'];

    public const MAX_OVERRIDES = 200;

    /** @return array{weekly: array<string, list<array{0: string, 1: string}>>, overrides: array<string, list<array{0: string, 1: string}>>} */
    public static function availability(mixed $input): array
    {
        $input = is_array($input) ? $input : [];
        $weekly = [];
        foreach (array_keys(BookingRules::DAYS) as $day) {
            $weekly[$day] = self::ranges($input['weekly'][$day] ?? [], "availability.weekly.{$day}");
        }
        $overrides = [];
        foreach (array_slice((array) ($input['overrides'] ?? []), 0, self::MAX_OVERRIDES, true) as $date => $ranges) {
            if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
                throw ValidationException::withMessages(['availability.overrides' => 'A date override has an invalid date.']);
            }
            $overrides[$date] = self::ranges($ranges, "availability.overrides.{$date}");
        }
        ksort($overrides);

        return ['weekly' => $weekly, 'overrides' => $overrides];
    }

    /** @return array<string, int|null> */
    public static function rules(mixed $input): array
    {
        $input = is_array($input) ? $input : [];
        $int = function (string $key, int $min, int $max) use ($input): int {
            $value = $input[$key] ?? BookingRules::DEFAULTS[$key];
            if (! is_numeric($value) || (int) $value < $min || (int) $value > $max) {
                throw ValidationException::withMessages(["rules.{$key}" => "Choose a value between {$min} and {$max}."]);
            }

            return (int) $value;
        };
        $dailyMax = $input['daily_max'] ?? null;
        if ($dailyMax !== null && $dailyMax !== '' && (! is_numeric($dailyMax) || (int) $dailyMax < 1 || (int) $dailyMax > 100)) {
            throw ValidationException::withMessages(['rules.daily_max' => 'Choose a value between 1 and 100, or leave it empty.']);
        }

        return [
            'step_minutes' => $int('step_minutes', 5, 240),
            'buffer_before' => $int('buffer_before', 0, 240),
            'buffer_after' => $int('buffer_after', 0, 240),
            'min_notice_minutes' => $int('min_notice_minutes', 0, 60 * 24 * 30),
            'horizon_days' => $int('horizon_days', 1, 365),
            'daily_max' => $dailyMax === null || $dailyMax === '' ? null : (int) $dailyMax,
        ];
    }

    /** @return list<array{id: string, label: string, type: string, required: bool, options: list<string>}> */
    public static function questions(mixed $input): array
    {
        $out = [];
        $seen = [];
        foreach (array_slice(is_array($input) ? array_values($input) : [], 0, self::MAX_QUESTIONS) as $i => $q) {
            $label = trim(Str::limit((string) ($q['label'] ?? ''), 200, ''));
            if ($label === '') {
                throw ValidationException::withMessages(["questions.{$i}.label" => 'Every question needs a label.']);
            }
            $type = in_array($q['type'] ?? null, self::QUESTION_TYPES, true) ? $q['type'] : 'text';
            $id = preg_match('/^[a-z0-9_-]{1,40}$/', (string) ($q['id'] ?? '')) ? $q['id'] : Str::lower(Str::random(8));
            while (isset($seen[$id])) {
                $id = Str::lower(Str::random(8));
            }
            $seen[$id] = true;
            $options = $type === 'choice'
                ? array_values(array_unique(array_filter(array_map(fn ($o) => trim(Str::limit((string) $o, 100, '')), array_slice((array) ($q['options'] ?? []), 0, 20)))))
                : [];
            if ($type === 'choice' && $options === []) {
                throw ValidationException::withMessages(["questions.{$i}.options" => 'A choice question needs at least one option.']);
            }
            $out[] = ['id' => $id, 'label' => $label, 'type' => $type, 'required' => (bool) ($q['required'] ?? false), 'options' => $options];
        }

        return $out;
    }

    /** @return array{kind: string, value: string|null} */
    public static function location(mixed $input): array
    {
        $kind = in_array($input['kind'] ?? null, BookingType::LOCATIONS, true) ? $input['kind'] : 'none';
        $value = in_array($kind, ['phone', 'in_person', 'link'], true) ? trim(Str::limit((string) ($input['value'] ?? ''), 500, '')) : '';
        if ($kind === 'link' && $value !== '' && ! preg_match('#^https://#i', $value)) {
            throw ValidationException::withMessages(['location.value' => 'The meeting link must start with https://.']);
        }

        return ['kind' => $kind, 'value' => $value !== '' ? $value : null];
    }

    /**
     * The guest's answers, validated against the type's questions (unknown ids dropped).
     *
     * @return array<string, string>
     */
    public static function answers(BookingType $type, mixed $input): array
    {
        $input = is_array($input) ? $input : [];
        $out = [];
        $errors = [];
        foreach ((array) $type->questions as $q) {
            $value = trim(Str::limit((string) ($input[$q['id']] ?? ''), 2000, ''));
            if ($value === '') {
                if ($q['required'] ?? false) {
                    $errors["answers.{$q['id']}"] = 'Please answer this question.';
                }

                continue;
            }
            if (($q['type'] ?? 'text') === 'choice' && ! in_array($value, (array) ($q['options'] ?? []), true)) {
                $errors["answers.{$q['id']}"] = 'Please pick one of the options.';

                continue;
            }
            $out[$q['id']] = $value;
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }

    /** @return list<array{0: string, 1: string}> */
    private static function ranges(mixed $ranges, string $field): array
    {
        $out = [];
        foreach (array_slice(is_array($ranges) ? array_values($ranges) : [], 0, 12) as $range) {
            $start = BookingRules::minutes((string) ($range[0] ?? ''));
            $end = BookingRules::minutes((string) ($range[1] ?? ''));
            if ($start === null || $end === null || $start >= $end) {
                throw ValidationException::withMessages([$field => 'Each time range needs a start before its end (HH:MM).']);
            }
            $out[] = [(string) $range[0], (string) $range[1]];
        }
        usort($out, fn ($a, $b) => strcmp($a[0], $b[0]));

        return $out;
    }
}
