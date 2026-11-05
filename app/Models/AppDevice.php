<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One signed-in desktop / mobile / TV app install (App API v1, BRAI-385 / BRAI-428) and its current
 * token pair. Revoking the device clears the pair, so its tokens stop working at once.
 * Tokens are issued and checked by App\Support\AppApi\AppTokens only. `push_token_hash` (SHA-256 of
 * the push token, to find one token across rows) follows `push_token` on every save — never set it.
 */
class AppDevice extends Model
{
    public const CLIENTS = ['desktop', 'mobile', 'tv'];

    public const PLATFORMS = ['macos', 'windows', 'linux', 'ios', 'android', 'android_tv'];

    protected $fillable = [
        'user_id', 'client', 'platform', 'name', 'app_version', 'os_version', 'push_token',
        'last_used_at', 'last_ip',
    ];

    protected $hidden = [
        'push_token', 'push_token_hash', 'access_token_hash', 'refresh_token_hash', 'previous_refresh_hash',
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

    protected static function booted(): void
    {
        static::saving(function (AppDevice $device) {
            if ($device->isDirty('push_token') || ! $device->exists) {
                $device->push_token_hash = self::pushTokenHash($device->push_token);
            }
        });
    }

    /** SHA-256 (hex) of a push token — the lookup column and the receipt check's token id. */
    public static function pushTokenHash(?string $token): ?string
    {
        return is_string($token) && $token !== '' ? hash('sha256', $token) : null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    /**
     * Installs a push may go to: signed in (not revoked, refresh token not expired — an app that
     * hasn't been opened for refresh_ttl is signed out in practice), the mobile app, with a push token.
     */
    public function scopeEligibleForPush(Builder $query): void
    {
        $query->active()
            ->where('client', 'mobile')
            ->whereNotNull('push_token')
            ->where('refresh_expires_at', '>', now());
    }

    /** Most recently used first (never used: by sign-in time) — the order of the device lists. */
    public function scopeRecentFirst(Builder $query): void
    {
        $query->orderByRaw('COALESCE(last_used_at, created_at) DESC')->orderByDesc('id');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
