<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One signed-in desktop / mobile app install (App API v1, BRAI-385 / BRAI-428) and its current
 * token pair. Revoking the device clears the pair, so its tokens stop working at once.
 * Tokens are issued and checked by App\Support\AppApi\AppTokens only.
 */
class AppDevice extends Model
{
    public const CLIENTS = ['desktop', 'mobile'];

    public const PLATFORMS = ['macos', 'windows', 'linux', 'ios', 'android'];

    protected $fillable = [
        'user_id', 'client', 'platform', 'name', 'app_version', 'os_version', 'push_token',
        'last_used_at', 'last_ip',
    ];

    protected $hidden = [
        'push_token', 'access_token_hash', 'refresh_token_hash', 'previous_refresh_hash',
    ];

    protected function casts(): array
    {
        return [
            'push_token' => 'encrypted',
            'access_expires_at' => 'datetime',
            'refresh_expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
