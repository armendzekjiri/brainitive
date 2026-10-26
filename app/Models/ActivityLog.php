<?php

namespace App\Models;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ActivityLog extends Model
{
    protected $fillable = [
        'user_id',
        'loggable_type',
        'loggable_id',
        'action',
        'changes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'metadata' => 'array',
        ];
    }

    /**
     * Get the user who performed the action.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the loggable model.
     */
    public function loggable(): MorphTo
    {
        return $this->morphTo();
    }

    /** Origin merged into every entry logged inside via() — e.g. ['via' => 'github']. */
    private static ?array $origin = null;

    /**
     * Run $callback with every entry it logs marked as made by an integration (metadata.via),
     * e.g. ActivityLog::via('github', fn () => …) → the history says "GitHub" (BRAI-61).
     */
    public static function via(string $origin, Closure $callback): mixed
    {
        $previous = self::$origin;
        self::$origin = ['via' => $origin];
        try {
            return $callback();
        } finally {
            self::$origin = $previous;
        }
    }

    /**
     * Log an activity.
     */
    public static function log(
        Model $model,
        string $action,
        ?array $changes = null,
        ?array $metadata = null
    ): self {
        return static::create([
            'user_id' => self::$origin ? null : auth()->id(), // an integration acted, not a person
            'loggable_type' => get_class($model),
            'loggable_id' => $model->id,
            'action' => $action,
            'changes' => $changes,
            'metadata' => self::$origin ? array_merge($metadata ?? [], self::$origin) : $metadata,
        ]);
    }

    /**
     * Get a human-readable description of the action.
     */
    public function getDescriptionAttribute(): string
    {
        $userName = $this->user?->name ?? 'System';

        return match ($this->action) {
            'created' => "{$userName} created this item",
            'updated' => "{$userName} updated this item",
            'deleted' => "{$userName} deleted this item",
            'moved' => "{$userName} moved this item",
            'assigned' => "{$userName} assigned this item",
            'unassigned' => "{$userName} unassigned this item",
            'commented' => "{$userName} commented",
            'status_changed' => "{$userName} changed the status",
            default => "{$userName} performed action: {$this->action}",
        };
    }
}
