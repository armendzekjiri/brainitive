<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

/**
 * A person outside the workspace who proved their email with a sign-in link (v1.42.0):
 * feedback-portal voters (BRAI-60) and invited clients (BRAI-73). Notifiable by mail only.
 * Never a workspace member: every portal checks its own access (client invitations,
 * portal enabled, …) on top of "is signed in". See App\Support\Portal\PortalAuth.
 */
class PortalIdentity extends Model
{
    use Notifiable;

    protected $fillable = ['email', 'name', 'user_id', 'email_verified_at', 'last_seen_at'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PortalIdentity $identity) {
            $identity->uuid ??= (string) Str::uuid();
            $identity->email = self::normalizeEmail($identity->email);
        });
    }

    public static function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    public static function findByEmail(string $email): ?self
    {
        return self::where('email', self::normalizeEmail($email))->first();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function loginTokens(): HasMany
    {
        return $this->hasMany(PortalLoginToken::class);
    }

    public function isVerified(): bool
    {
        return $this->email_verified_at !== null;
    }

    /** What a portal shows about the viewer (never the id). */
    public function displayName(): string
    {
        return $this->name ?: Str::before($this->email, '@');
    }

    /** @return array{uuid: string, name: string, email: string, is_member: bool} */
    public function toPortalArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->displayName(),
            'email' => $this->email,
            'is_member' => $this->user_id !== null,
        ];
    }
}
