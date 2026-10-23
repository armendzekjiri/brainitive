<?php

namespace App\Support\Boards;

use App\Models\ActivityLog;
use App\Models\Item;
use App\Models\ItemField;
use App\Models\ItemFieldValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reads and writes an issue's custom field values (BRAI-77).
 *
 * Every value is validated against its field's type on the server; `null` clears it (the row
 * is deleted), so 0 and false stay distinct from "no value". Each change writes a
 * `field_changed` history entry with display values.
 */
class ItemFieldValues
{
    public const MAX_TEXT = 1000;

    /** Largest absolute number (decimal(20,6) leaves 14 integer digits). */
    public const MAX_NUMBER = 1e14;

    /**
     * Set several values on one item: `[fieldId => value|null]`. All-or-nothing: one invalid
     * value rejects the whole request.
     *
     * @param  array<int|string, mixed>  $values
     *
     * @throws ValidationException
     */
    public static function apply(Item $item, array $values): void
    {
        $item->loadMissing('board');
        $ids = collect(array_keys($values))->filter(fn ($id) => ctype_digit((string) $id))->map(fn ($id) => (int) $id);

        $fields = ItemField::where('project_id', $item->board->project_id)
            ->whereIn('id', $ids->all())
            ->with('options')
            ->get()
            ->keyBy('id');
        $current = $item->fieldValues()->whereIn('item_field_id', $fields->keys()->all())->get()->keyBy('item_field_id');

        $errors = [];
        $normalized = [];
        foreach ($values as $fieldId => $raw) {
            $field = ctype_digit((string) $fieldId) ? $fields->get((int) $fieldId) : null;
            if (! $field || $field->isArchived() || ! $field->appliesToBoard($item->board_id)) {
                $errors["values.{$fieldId}"] = 'This field is not available on this board.';

                continue;
            }

            $result = self::normalize($field, $raw, $current->get($field->id));
            if (is_string($result) && str_starts_with($result, 'error:')) {
                $errors["values.{$fieldId}"] = substr($result, 6);

                continue;
            }
            $normalized[$field->id] = $result;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($item, $fields, $current, $normalized) {
            $changed = false;
            foreach ($normalized as $fieldId => $value) {
                $changed = self::write($item, $fields[$fieldId], $current->get($fieldId), $value) || $changed;
            }
            if ($changed) {
                // Lets open popups notice the change (history refetch keys off updated_at).
                $item->touch();
            }
        });
    }

    /**
     * Copy every value from one item to another (duplicating an issue).
     */
    public static function copy(Item $from, Item $to): void
    {
        $rows = $from->fieldValues()->get()->map(fn (ItemFieldValue $value) => [
            ...$value->only(['item_field_id', 'value_text', 'value_number', 'value_bool', 'option_id']),
            'value_date' => $value->value_date?->toDateString(),
            'item_id' => $to->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($rows->isNotEmpty()) {
            ItemFieldValue::insert($rows->all());
        }
    }

    /**
     * Remove values whose field belongs to another project (an issue moved between projects),
     * with a history entry per removed value. Values of the issue's own project stay.
     *
     * @param  list<int>  $itemIds
     */
    public static function dropForeign(array $itemIds, int $projectId): void
    {
        $rows = ItemFieldValue::whereIn('item_id', $itemIds)
            ->whereHas('field', fn ($q) => $q->where('project_id', '!=', $projectId))
            ->with(['field.options', 'item'])
            ->get();

        foreach ($rows as $row) {
            if ($row->item) {
                ActivityLog::log($row->item, 'field_changed', [
                    'value' => ['from' => self::display($row->field, self::valueOf($row->field, $row)), 'to' => null],
                ], ['field' => $row->field->name, 'field_id' => $row->field->id, 'reason' => 'moved_project']);
            }
        }

        ItemFieldValue::whereKey($rows->modelKeys())->delete();
    }

    /**
     * The typed value of a stored row (what the API and the board use).
     */
    public static function valueOf(ItemField $field, ?ItemFieldValue $row): mixed
    {
        if ($row === null) {
            return null;
        }

        return match ($field->type) {
            'date' => $row->value_date?->toDateString(),
            default => $row->getAttribute(ItemFieldValue::COLUMNS[$field->type]),
        };
    }

    /**
     * Human-readable value for the history and other plain-text places.
     */
    public static function display(ItemField $field, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($field->type) {
            'checkbox' => $value ? 'Yes' : 'No',
            'number' => self::formatNumber((float) $value),
            'select' => $field->options->firstWhere('id', (int) $value)?->label ?? "#{$value}",
            default => (string) $value,
        };
    }

    public static function formatNumber(float $number): string
    {
        $text = rtrim(rtrim(number_format($number, 6, '.', ''), '0'), '.');

        return $text === '-0' ? '0' : $text;
    }

    /**
     * Validate and convert a submitted value. Returns the typed value, null to clear, or an
     * "error:<message>" string.
     */
    private static function normalize(ItemField $field, mixed $raw, ?ItemFieldValue $current): mixed
    {
        if ($raw === null) {
            return null;
        }

        switch ($field->type) {
            case 'text':
                if (! is_string($raw) && ! is_int($raw) && ! is_float($raw)) {
                    return 'error:Enter text.';
                }
                $text = trim((string) $raw);
                if ($text === '') {
                    return null;
                }

                return mb_strlen($text) > self::MAX_TEXT ? 'error:Use at most '.self::MAX_TEXT.' characters.' : $text;

            case 'number':
                if (is_bool($raw) || ! is_numeric($raw)) {
                    return 'error:Enter a number.';
                }
                $number = (float) $raw;
                if (! is_finite($number) || abs($number) >= self::MAX_NUMBER) {
                    return 'error:The number is too large.';
                }

                return round($number, 6);

            case 'date':
                if (! is_string($raw) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                    return 'error:Enter a date as YYYY-MM-DD.';
                }

                return $raw;

            case 'checkbox':
                $bool = is_bool($raw) ? $raw : filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($bool === null || (is_string($raw) && trim($raw) === '')) {
                    return 'error:Choose yes or no.';
                }

                return $bool;

            case 'select':
                if (! is_int($raw) && ! (is_string($raw) && ctype_digit($raw))) {
                    return 'error:Choose an option.';
                }
                $option = $field->options->firstWhere('id', (int) $raw);
                // An archived option can stay on issues that already have it, but can't be picked anew.
                if (! $option || ($option->archived_at !== null && $current?->option_id !== $option->id)) {
                    return 'error:Choose one of the field\'s options.';
                }

                return $option->id;
        }

        return 'error:Unknown field type.';
    }

    /**
     * Store one normalized value. Returns whether anything changed.
     */
    private static function write(Item $item, ItemField $field, ?ItemFieldValue $row, mixed $value): bool
    {
        $before = self::valueOf($field, $row);
        if ($before === $value || ($field->type === 'number' && $before !== null && $value !== null && (float) $before === (float) $value)) {
            return false;
        }

        if ($value === null) {
            // By query: a concurrent clear may already have removed the row we read.
            ItemFieldValue::where('item_id', $item->id)->where('item_field_id', $field->id)->delete();
        } else {
            $column = ItemFieldValue::COLUMNS[$field->type];
            ItemFieldValue::updateOrCreate(
                ['item_id' => $item->id, 'item_field_id' => $field->id],
                [...array_fill_keys(array_values(ItemFieldValue::COLUMNS), null), $column => $value],
            );
        }

        ActivityLog::log($item, 'field_changed', [
            'value' => ['from' => self::display($field, $before), 'to' => self::display($field, $value)],
        ], ['field' => $field->name, 'field_id' => $field->id]);

        return true;
    }
}
