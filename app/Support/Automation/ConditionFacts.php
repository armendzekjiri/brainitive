<?php

namespace App\Support\Automation;

use App\Models\Item;
use App\Models\ItemFieldValue;
use App\Models\Ticket;
use Carbon\CarbonImmutable;

/**
 * The values of one issue / ticket that a rule's conditions look at (BRAI-144), read once per
 * check: labels and custom field values cost one query each, and only when a condition needs them.
 * Dates are Y-m-d strings; "today" is in the rule's time zone.
 */
final class ConditionFacts
{
    /** @var list<int>|null */
    private ?array $labels = null;

    /** @var array<int, ItemFieldValue>|null */
    private ?array $fieldValues = null;

    public function __construct(private readonly Item|Ticket $subject, private readonly string $timezone) {}

    /** Today (optionally shifted) as Y-m-d in the rule's time zone. */
    public function today(?callable $shift = null): string
    {
        $today = CarbonImmutable::now($this->timezone)->startOfDay();

        return ($shift ? $shift($today) : $today)->toDateString();
    }

    /** The subject's value of a field (null = none; labels: a list of ids). */
    public function get(string $field, ?array $custom): mixed
    {
        $subject = $this->subject;
        $item = $subject instanceof Item ? $subject : null;

        if ($custom) {
            return $item ? $this->customValue($custom) : null;
        }

        return match ($field) {
            'board' => $item?->board_id,
            'helpdesk' => $subject instanceof Ticket ? $subject->helpdesk_id : null,
            'column' => $subject->column_id,
            'item_type' => $item?->item_type_id,
            'priority' => $subject->priority_id,
            'labels' => $item ? $this->labels() : [],
            'assignee' => $subject->assigned_to,
            'reporter' => $item ? ($item->reporter_id ?? $item->created_by) : null,
            'title' => AutomationSubject::title($subject),
            'description' => self::plainText($subject->description),
            'due_date' => $item?->due_date?->toDateString(),
            'start_date' => $item?->start_date?->toDateString(),
            default => null,
        };
    }

    /** An open issue whose due day is over, or whose due time today has passed (rule time zone). */
    public function overdue(): bool
    {
        $item = $this->subject;
        if (! $item instanceof Item || $item->completed_at !== null || ! $item->due_date) {
            return false;
        }
        $due = $item->due_date->toDateString();
        $now = CarbonImmutable::now($this->timezone);

        return $due < $now->toDateString()
            || ($due === $now->toDateString() && $item->due_time && substr((string) $item->due_time, 0, 5) <= $now->format('H:i'));
    }

    /** Rich text as the words a person reads: tags out, entities decoded, whitespace collapsed. */
    public static function plainText(?string $html): string
    {
        $text = preg_replace('/<(br|\/p|\/div|\/li|\/h[1-6])\b[^>]*>/i', ' ', (string) $html);
        $text = html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** @return list<int> */
    private function labels(): array
    {
        return $this->labels ??= $this->subject->labels()->pluck('labels.id')->map(fn ($id) => (int) $id)->all();
    }

    private function customValue(array $custom): mixed
    {
        $this->fieldValues ??= ItemFieldValue::where('item_id', $this->subject->id)->get()->keyBy('item_field_id')->all();
        $row = $this->fieldValues[$custom['id']] ?? null;
        if (! $row) {
            return $custom['type'] === 'checkbox' ? false : null;
        }

        return match ($custom['type']) {
            'text' => $row->value_text,
            'number' => $row->value_number,
            'date' => $row->value_date?->toDateString(),
            'checkbox' => (bool) $row->value_bool,
            'select' => $row->option_id,
            default => null,
        };
    }
}
