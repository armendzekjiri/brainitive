<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * One external calendar (or Google Tasks list) a user subscribed to. Personal, never
 * workspace-scoped. `provider` is a key of App\Support\Calendar\CalendarProviders.
 */
class CalendarConnection extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'provider',
        'kind',
        'account_email',
        'account_name',
        'external_calendar_id',
        'external_calendar_name',
        'external_calendar_color',
        'server_url',
        'access_token',
        'refresh_token',
        'credentials',
        'token_expires_at',
        'is_active',
        'last_synced_at',
        'sync_error',
        'sync_error_at',
    ];

    protected function casts(): array
    {
        return [
            // Auto-encrypt at rest. Laravel transparently encrypts on save and
            // decrypts on access — so $connection->access_token returns plain.
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            // Provider secrets that aren't OAuth tokens (CalDAV app password, ICS URL with its token).
            'credentials' => 'encrypted:array',
            'token_expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'sync_error_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    protected $hidden = [
        'access_token',
        'refresh_token',
        'credentials',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->token_expires_at && $this->token_expires_at->isPast();
    }

    /**
     * Find-or-create a connection, reviving a soft-deleted row with the same key. updateOrCreate()
     * only looks at non-trashed rows, so without this a disconnect-then-reconnect would INSERT
     * over the still-present soft-deleted row and hit the unique index. The key matches the
     * partial unique index (user_id, provider, kind, account_email, external_calendar_id).
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function upsertFor(int $userId, string $provider, string $kind, string $accountEmail, string $externalId, array $attributes): self
    {
        $connection = static::withTrashed()->firstOrNew([
            'user_id' => $userId,
            'provider' => $provider,
            'kind' => $kind,
            'account_email' => $accountEmail,
            'external_calendar_id' => $externalId,
        ]);

        if ($connection->trashed()) {
            $connection->restore();
        }

        $connection->fill($attributes)->save();

        return $connection;
    }

    /** Remember why loading this calendar failed (shown as a warning on the calendar page). */
    public function recordSyncError(string $message): void
    {
        $this->forceFill([
            'sync_error' => Str::limit($message, 500),
            'sync_error_at' => now(),
        ])->saveQuietly();
    }

    /** Forget a previous failure after a successful load. Writes only when there was one. */
    public function clearSyncError(): void
    {
        if ($this->sync_error === null && $this->sync_error_at === null) {
            return;
        }

        $this->forceFill(['sync_error' => null, 'sync_error_at' => null, 'last_synced_at' => now()])->saveQuietly();
    }
}
