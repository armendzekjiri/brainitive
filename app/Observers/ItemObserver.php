<?php

namespace App\Observers;

use App\Models\ActivityLog;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Item;
use App\Models\ItemPriority;
use App\Models\ItemType;
use App\Models\Sprint;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Writes the item history (Jira's "History" tab) for every change made through the model,
 * so the board UI, the full item page and the MCP tools are all covered.
 */
class ItemObserver
{
    /**
     * Fields that appear in the history => model used to turn an id into a readable value.
     * Values are resolved when the change happens, so the history survives renames/deletes.
     *
     * @var array<string, class-string|null>
     */
    private const TRACKED_FIELDS = [
        'title' => null,
        'description' => null,
        'board_id' => Board::class,
        'column_id' => BoardColumn::class,
        'sprint_id' => Sprint::class,
        'assigned_to' => User::class,
        'priority_id' => ItemPriority::class,
        'item_type_id' => ItemType::class,
        'parent_id' => Item::class,
        'story_points' => null,
        'original_estimate' => null,
        'start_date' => null,
        'start_time' => null,
        'due_date' => null,
        'due_time' => null,
    ];

    public function created(Item $item): void
    {
        ActivityLog::log($item, 'created', null, ['key' => $item->key]);
    }

    public function updated(Item $item): void
    {
        $changes = [];

        foreach (self::TRACKED_FIELDS as $field => $model) {
            if (! $item->wasChanged($field)) {
                continue;
            }

            // Long rich text: record that it changed, not the whole document twice
            $changes[$field] = $field === 'description'
                ? ['changed' => true]
                : [
                    'from' => $this->displayValue($field, $model, $item->getOriginal($field)),
                    'to' => $this->displayValue($field, $model, $item->getAttribute($field)),
                ];
        }

        if ($changes === []) {
            return;
        }

        $action = match (true) {
            isset($changes['column_id']) || isset($changes['board_id']) => 'moved',
            array_keys($changes) === ['assigned_to'] => $item->assigned_to ? 'assigned' : 'unassigned',
            default => 'updated',
        };

        ActivityLog::log($item, $action, $changes);
    }

    /**
     * Human-readable value for the history: names for relations, Y-m-d for dates, durations.
     */
    private function displayValue(string $field, ?string $model, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($field === 'original_estimate') {
            return Item::formatDuration((int) $value);
        }

        if ($value instanceof CarbonInterface) {
            return $value->toDateString();
        }

        if ($model === null) {
            return $value;
        }

        $related = $model::withoutGlobalScopes()->find($value);

        return match ($model) {
            Item::class => $related ? "{$related->key} {$related->title}" : "#{$value}",
            default => $related?->name ?? "#{$value}",
        };
    }
}
