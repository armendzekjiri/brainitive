<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Source links to target" between two resources of one project (BRAI-71). Endpoints are
 * stored as a stable type key + id, never a class name, so renaming a model never breaks
 * stored rows. Reads and writes go through App\Support\References\ContentReferences.
 */
class ContentReference extends Model
{
    public const ORIGIN_MANUAL = 'manual';

    /** Mirrors a plan row or whiteboard card linked to an issue; rewritten by model hooks, read-only in the UI. */
    public const ORIGIN_DERIVED = 'derived';

    /** Endpoint type keys → models. Whiteboards are pages. */
    public const TYPES = [
        'item' => Item::class,
        'page' => Page::class,
        'ticket' => Ticket::class,
        'plan' => Plan::class,
    ];

    protected $fillable = [
        'project_id',
        'source_type',
        'source_id',
        'target_type',
        'target_id',
        'origin',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'source_id' => 'integer',
            'target_id' => 'integer',
            'created_by' => 'integer',
        ];
    }

    /**
     * The type key of a model that can be an endpoint, or null.
     */
    public static function typeOf(Model $model): ?string
    {
        $key = array_search($model::class, self::TYPES, true);

        return $key === false ? null : $key;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isDerived(): bool
    {
        return $this->origin === self::ORIGIN_DERIVED;
    }
}
