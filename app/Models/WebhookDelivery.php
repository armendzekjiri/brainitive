<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One POST of an event to a webhook (v1.39.0, BRAI-104): the exact envelope (`payload`), the
 * outcome of the last attempt and the response (body truncated to RESPONSE_LIMIT bytes).
 * Pruned after 30 days (`model:prune` in routes/console.php).
 */
class WebhookDelivery extends Model
{
    use Prunable;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const RESPONSE_LIMIT = 2048;

    public const KEEP_DAYS = 30;

    protected $fillable = [
        'uuid', 'webhook_id', 'event', 'event_id', 'payload', 'status', 'attempts', 'response_code',
        'response_body', 'duration_ms', 'error', 'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'response_code' => 'integer',
            'duration_ms' => 'integer',
            'delivered_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (WebhookDelivery $delivery) {
            $delivery->uuid ??= (string) Str::uuid();
        });
    }

    public function webhook(): BelongsTo
    {
        return $this->belongsTo(Webhook::class);
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(self::KEEP_DAYS));
    }
}
