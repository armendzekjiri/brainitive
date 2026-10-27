<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Support\Carbon;
use NotificationChannels\WebPush\PushSubscription as BasePushSubscription;

/**
 * A browser's push subscription (laravel-notification-channels/webpush, `config('webpush.model')`)
 * plus what Profile → Notifications needs to explain it (BRAI-154): the browser it came from and
 * what the push service last answered.
 *
 * `expired_at` = the push service answered 404 / 410. The row is kept (flagged) so the device list
 * can say which browser stopped receiving; flagged rows get no more pushes
 * (`User::routeNotificationForWebPush()`) and are pruned after 30 days.
 *
 * @property string|null $user_agent
 * @property string|null $device_label
 * @property Carbon|null $last_used_at
 * @property Carbon|null $last_success_at
 * @property string|null $last_error
 * @property Carbon|null $last_error_at
 * @property Carbon|null $expired_at
 */
class PushSubscription extends BasePushSubscription
{
    use Prunable;

    /** Days a flagged (expired) subscription stays listed before it is pruned. */
    public const KEEP_EXPIRED_DAYS = 30;

    protected $fillable = [
        'endpoint',
        'public_key',
        'auth_token',
        'content_encoding',
        'user_agent',
        'device_label',
    ];

    /** The endpoint and keys address and encrypt pushes to this browser: never serialised. */
    protected $hidden = ['endpoint', 'public_key', 'auth_token'];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_error_at' => 'datetime',
            'expired_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expired_at !== null;
    }

    /** @param  Builder<static>  $query */
    public function scopeLive(Builder $query): void
    {
        $query->whereNull('expired_at');
    }

    public function prunable(): Builder
    {
        return static::where('expired_at', '<', now()->subDays(self::KEEP_EXPIRED_DAYS));
    }
}
