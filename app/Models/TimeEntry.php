<?php

namespace App\Models;

use App\Support\Events\DomainEvents;
use App\Support\Events\EventPayloads;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One piece of logged work (v1.38.0): the ledger behind items.time_spent and every billing report.
 * Keeps the item's running total in sync (create +, update ±, delete −); totals logged before the
 * ledger existed stay. Billed entries (billing_report_id set) are locked — see isLocked().
 * Contract: docs/ai/tasks/release-v1.38.0-billing.md.
 */
class TimeEntry extends Model
{
    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_TIMER = 'timer';

    public const SOURCE_LEGACY = 'legacy';

    protected $fillable = [
        'workspace_id', 'project_id', 'item_id', 'user_id', 'billing_activity_id', 'spent_on', 'minutes',
        'note', 'billable', 'source', 'item_key', 'item_title',
    ];

    protected function casts(): array
    {
        return [
            'spent_on' => 'date:Y-m-d',
            'minutes' => 'integer',
            'billable' => 'boolean',
            'billing_report_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // legacy rows are backfilled from minutes that are already in time_spent; withTrashed: an
        // issue in the trash keeps a right total for when it is restored
        static::created(function (TimeEntry $entry) {
            if ($entry->item_id && $entry->source !== self::SOURCE_LEGACY) {
                Item::withTrashed()->whereKey($entry->item_id)->increment('time_spent', $entry->minutes);
            }
            if ($entry->source !== self::SOURCE_LEGACY) {
                DomainEvents::emit($entry->workspace_id, 'time_entry.created', fn () => EventPayloads::timeEntry($entry)); // webhooks (BRAI-104)
            }
        });

        static::updated(function (TimeEntry $entry) {
            if ($entry->source === self::SOURCE_LEGACY || ! $entry->wasChanged(['minutes', 'item_id'])) {
                return;
            }
            $oldItem = $entry->getOriginal('item_id');
            $oldMinutes = (int) $entry->getOriginal('minutes');
            if ($oldItem) {
                Item::withTrashed()->whereKey($oldItem)->where('time_spent', '>=', $oldMinutes)->decrement('time_spent', $oldMinutes);
            }
            if ($entry->item_id) {
                Item::withTrashed()->whereKey($entry->item_id)->increment('time_spent', $entry->minutes);
            }
        });

        static::deleted(function (TimeEntry $entry) {
            if ($entry->item_id && $entry->source !== self::SOURCE_LEGACY) {
                Item::withTrashed()->whereKey($entry->item_id)->where('time_spent', '>=', $entry->minutes)->decrement('time_spent', $entry->minutes);
            }
        });
    }

    /** Billed by a finalized report: no edits, no deletes, no moves. */
    public function isLocked(): bool
    {
        return $this->billing_report_id !== null;
    }

    public function scopeUnbilled(Builder $query): Builder
    {
        return $query->whereNull('billing_report_id');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(BillingActivity::class, 'billing_activity_id');
    }
}
