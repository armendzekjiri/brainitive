<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What someone was doing when they logged time (v1.38.0), defined by the workspace admin — no
 * built-in templates. `hourly`: amount = hours × rate_cents. `fixed`: rate_cents is a flat price,
 * billed once per ticket (docs/ai/tasks/release-v1.38.0-billing.md); the hours are informational.
 * Used activities are archived, never deleted (time_entries restricts the delete).
 */
class BillingActivity extends Model
{
    public const HOURLY = 'hourly';

    public const FIXED = 'fixed';

    public const PRICING_TYPES = [self::HOURLY, self::FIXED];

    protected $fillable = ['workspace_id', 'name', 'pricing_type', 'rate_cents', 'billable', 'color', 'position', 'archived_at'];

    protected function casts(): array
    {
        return [
            'rate_cents' => 'integer',
            'billable' => 'boolean',
            'position' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    public function isFixed(): bool
    {
        return $this->pricing_type === self::FIXED;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }
}
