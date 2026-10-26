<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A user's private calendar subscription (BRAI-142): `/calendar/feed/{token}.ics`. At most one active
 * feed per user. The token is found by its sha256 `token_hash`; `token` is an encrypted copy so the
 * owner can copy the URL again. Reset = revoke + a new row (the old URL 404s).
 */
class CalendarFeed extends Model
{
    /** What goes in when nothing was chosen. Titles, keys and links only — descriptions are opt-in. */
    public const DEFAULT_OPTIONS = [
        'assigned' => true,      // board issues assigned to me (start / due dates)
        'created' => false,      // board issues I created
        'tasks' => true,         // task-list tasks assigned to me (or unassigned and created by me)
        'my_day' => true,        // what I planned on My Day, on that day
        'focus' => true,         // focus sessions logged with the timer
        'completed' => false,    // also show finished work
        'descriptions' => false, // add issue / task descriptions (plain text, shortened)
        'project_ids' => [],     // only these projects (empty = all I can open)
    ];

    protected $fillable = [
        'user_id', 'token_hash', 'token', 'options', 'content_hash', 'content_changed_at',
        'last_fetched_at', 'fetch_count', 'revoked_at',
    ];

    protected $hidden = ['token', 'token_hash', 'content_hash'];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'options' => 'array',
            'content_changed_at' => 'datetime',
            'last_fetched_at' => 'datetime',
            'revoked_at' => 'datetime',
            'fetch_count' => 'integer',
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

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** The active feed of a URL token, or null (unknown or revoked). */
    public static function findActive(string $token): ?self
    {
        return self::active()->where('token_hash', self::hashToken($token))->first();
    }

    public static function activeFor(User $user): ?self
    {
        return self::active()->where('user_id', $user->id)->first();
    }

    /**
     * A new feed with a new token; the user's previous active feed (if any) is revoked in the same
     * transaction. Options default to the previous feed's, so "reset link" keeps the choices.
     */
    public static function issue(User $user, ?array $options = null): self
    {
        return DB::transaction(function () use ($user, $options) {
            User::whereKey($user->id)->lockForUpdate()->first(); // serializes parallel "create" clicks
            $previous = self::activeFor($user);
            $previous?->update(['revoked_at' => now()]);
            $token = Str::random(48);

            return self::create([
                'user_id' => $user->id,
                'token_hash' => self::hashToken($token),
                'token' => $token,
                'options' => self::normalizeOptions($options ?? $previous?->options ?? []),
            ]);
        });
    }

    /** Known keys only, booleans as booleans, project ids as unique ints. */
    public static function normalizeOptions(array $options): array
    {
        $normalized = [];
        foreach (self::DEFAULT_OPTIONS as $key => $default) {
            if ($key === 'project_ids') {
                $ids = array_map('intval', (array) ($options['project_ids'] ?? []));
                $normalized['project_ids'] = array_values(array_unique(array_filter($ids, fn (int $id) => $id > 0)));

                continue;
            }
            $normalized[$key] = array_key_exists($key, $options) ? filter_var($options[$key], FILTER_VALIDATE_BOOLEAN) : $default;
        }

        return $normalized;
    }

    public function resolvedOptions(): array
    {
        return self::normalizeOptions($this->options ?? []);
    }

    public function url(): string
    {
        return route('calendar-feed.ics', ['token' => $this->token]);
    }

    public function webcalUrl(): string
    {
        return (string) preg_replace('#^https?://#i', 'webcal://', $this->url());
    }

    /** Links that open "subscribe to this calendar" in each app. */
    public function subscribeLinks(): array
    {
        return [
            'apple' => $this->webcalUrl(),
            'google' => 'https://calendar.google.com/calendar/r?cid='.rawurlencode($this->webcalUrl()),
            'outlook' => 'https://outlook.live.com/calendar/0/addfromweb?url='.rawurlencode($this->url()).'&name='.rawurlencode('Brainitive'),
        ];
    }
}
