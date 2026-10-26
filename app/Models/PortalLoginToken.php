<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One emailed sign-in link for a PortalIdentity (v1.42.0). Only the sha256 of the token is
 * stored; the link works once and for PortalAuth::LINK_MINUTES. Pruned a day after expiry.
 */
class PortalLoginToken extends Model
{
    use Prunable;

    protected $fillable = ['portal_identity_id', 'token_hash', 'return_to', 'expires_at', 'used_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function identity(): BelongsTo
    {
        return $this->belongsTo(PortalIdentity::class, 'portal_identity_id');
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }

    public function prunable(): Builder
    {
        return static::where('expires_at', '<', now()->subDay());
    }
}
