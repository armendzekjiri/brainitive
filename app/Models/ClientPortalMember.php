<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An invited client of one portal (v1.42.0, BRAI-73). Active = not revoked and not expired.
 * Re-inviting clears `revoked_at` and sets a new expiry; the row (and its history) is kept.
 */
class ClientPortalMember extends Model
{
    protected $fillable = ['client_portal_id', 'portal_identity_id', 'invited_by', 'invited_at', 'expires_at', 'revoked_at', 'revoked_by', 'last_seen_at'];

    protected function casts(): array
    {
        return [
            'invited_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function portal(): BelongsTo
    {
        return $this->belongsTo(ClientPortal::class, 'client_portal_id');
    }

    public function identity(): BelongsTo
    {
        return $this->belongsTo(PortalIdentity::class, 'portal_identity_id');
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /** @param Builder<ClientPortalMember> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at')->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /** active | expired | revoked */
    public function state(): string
    {
        return match (true) {
            $this->revoked_at !== null => 'revoked',
            $this->expires_at !== null && ! $this->expires_at->isFuture() => 'expired',
            default => 'active',
        };
    }
}
