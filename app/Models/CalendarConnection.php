<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

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
        'access_token',
        'refresh_token',
        'token_expires_at',
        'is_active',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            // Auto-encrypt at rest. Laravel transparently encrypts on save and
            // decrypts on access — so $connection->access_token returns plain.
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->token_expires_at && $this->token_expires_at->isPast();
    }
}
