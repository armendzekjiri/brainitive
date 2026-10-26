<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Something the team shared with a portal's clients (v1.42.0, BRAI-73), frozen at publish time:
 * a published page version (`content.html`, cleaned by DeliverableHtml) or a plan snapshot
 * (`content.plan`). A newer publish of the same page / plan supersedes it; its decision stays as
 * history and never carries over. Written only through App\Support\ClientPortal\Deliverables.
 */
class ClientDeliverable extends Model
{
    public const STATUS_AWAITING = 'awaiting';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_CHANGES = 'changes_requested';

    protected $fillable = [
        'uuid', 'client_portal_id', 'kind', 'page_id', 'page_version_id', 'plan_id', 'version', 'title',
        'content', 'message', 'status', 'published_by', 'published_at', 'superseded_at', 'superseded_by_id', 'withdrawn_at',
    ];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'version' => 'integer',
            'published_at' => 'datetime',
            'superseded_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ClientDeliverable $deliverable) {
            $deliverable->uuid ??= (string) Str::uuid();
        });
    }

    public function portal(): BelongsTo
    {
        return $this->belongsTo(ClientPortal::class, 'client_portal_id');
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(ClientDeliverableDecision::class)->orderBy('id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(ClientDeliverableComment::class)->orderBy('id');
    }

    /** Comments a client may read: their own side and the team's explicit replies. */
    public function clientComments(): HasMany
    {
        return $this->comments()->where('visibility', ClientDeliverableComment::VISIBILITY_CLIENT);
    }

    /** @param Builder<ClientDeliverable> $query */
    public function scopeVisibleToClients(Builder $query): void
    {
        $query->whereNull('withdrawn_at');
    }

    /** @param Builder<ClientDeliverable> $query */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereNull('withdrawn_at')->whereNull('superseded_at');
    }

    public function isCurrent(): bool
    {
        return $this->withdrawn_at === null && $this->superseded_at === null;
    }

    /** What the client / team sees as the status: superseded wins over the kept decision. */
    public function displayStatus(): string
    {
        return match (true) {
            $this->withdrawn_at !== null => 'withdrawn',
            $this->superseded_at !== null => 'superseded',
            default => $this->status,
        };
    }
}
