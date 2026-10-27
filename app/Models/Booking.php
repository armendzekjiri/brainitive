<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One booked time (BRAI-158). The guest manages it through a one-record token link (only its sha256
 * is stored). `unchecked_connection_ids`: calendars that couldn't be read when it was booked, so the
 * host is told to look for an overlap.
 */
class Booking extends Model
{
    public const CONFIRMED = 'confirmed';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'booking_type_id', 'user_id', 'starts_at', 'ends_at', 'guest_name', 'guest_email', 'guest_timezone',
        'answers', 'consent_text', 'consent_at', 'status', 'manage_token_hash', 'calendar_connection_id',
        'external_event_id', 'conference_url', 'unchecked_connection_ids', 'item_id', 'cancelled_by',
        'cancel_reason', 'cancelled_at', 'reminded_at',
    ];

    protected $hidden = ['manage_token_hash'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'answers' => 'array',
            'consent_at' => 'datetime',
            'unchecked_connection_ids' => 'array',
            'cancelled_at' => 'datetime',
            'reminded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Booking $booking) {
            $booking->uuid ??= (string) Str::uuid();
        });
    }

    /** Always stored in UTC: Eloquent writes a timestamp's wall clock without converting it. */
    protected function startsAt(): Attribute
    {
        return Attribute::set(fn ($value) => self::utc($value));
    }

    protected function endsAt(): Attribute
    {
        return Attribute::set(fn ($value) => self::utc($value));
    }

    private static function utc(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value)->utc()->format('Y-m-d H:i:s');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(BookingType::class, 'booking_type_id');
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('status', self::CONFIRMED);
    }

    /** A new manage token: the plain value goes into the guest's email, only the hash is stored. */
    public static function newManageToken(): array
    {
        $token = Str::random(48);

        return [$token, hash('sha256', $token)];
    }

    public static function findByManageToken(string $token): ?self
    {
        return strlen($token) === 48 ? self::where('manage_token_hash', hash('sha256', $token))->first() : null;
    }
}
