<?php

namespace App\Models;

use App\Support\Events\DomainEvents;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An outgoing webhook of a workspace (v1.39.0, BRAI-104): signed POSTs of DomainEvents to `url`.
 * The secret is encrypted at rest, hidden from serialization and only revealed on request
 * (WebhookController::secret). Auto-disabled (active = false, disabled_at) after
 * MAX_CONSECUTIVE_FAILURES failed deliveries in a row.
 */
class Webhook extends Model
{
    public const ALL_EVENTS = '*';

    public const MAX_CONSECUTIVE_FAILURES = 10;

    protected $fillable = [
        'workspace_id', 'name', 'url', 'secret', 'events', 'active', 'failure_count', 'disabled_at',
        'last_delivery_at', 'created_by',
    ];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'events' => 'array',
            'active' => 'boolean',
            'failure_count' => 'integer',
            'disabled_at' => 'datetime',
            'last_delivery_at' => 'datetime',
        ];
    }

    public static function generateSecret(): string
    {
        return 'whsec_'.bin2hex(random_bytes(24));
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    /** Active webhooks of a workspace subscribed to $event (directly or through "*"). */
    public function scopeListeningTo(Builder $query, int $workspaceId, string $event): Builder
    {
        return $query->where('workspace_id', $workspaceId)
            ->where('active', true)
            ->where(fn (Builder $q) => $q->whereJsonContains('events', $event)->orWhereJsonContains('events', self::ALL_EVENTS));
    }

    public function listensTo(string $event): bool
    {
        $events = $this->events ?? [];

        return in_array(self::ALL_EVENTS, $events, true) || in_array($event, $events, true);
    }

    /**
     * Keep only known event names; "*" wins over a list.
     *
     * @param  list<string>  $events
     * @return list<string>
     */
    public static function normalizeEvents(array $events): array
    {
        if (in_array(self::ALL_EVENTS, $events, true)) {
            return [self::ALL_EVENTS];
        }

        return array_values(array_intersect(array_keys(DomainEvents::EVENTS), $events));
    }
}
