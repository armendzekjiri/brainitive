<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;

class InstanceSettings extends Model
{
    protected $fillable = [
        'name',
        'logo',
        'settings',
    ];

    /** Memoised row for the current request / job — reset with flushCache(). */
    private static ?self $current = null;

    protected static function booted(): void
    {
        static::saved(fn () => static::flushCache());
    }

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    /**
     * Get the singleton instance settings.
     */
    public static function instance(): self
    {
        return static::firstOrCreate([], [
            'name' => 'Brainitive',
        ]);
    }

    /**
     * The settings row, read once per request. Null before the table exists (fresh install,
     * artisan commands that run ahead of `migrate`).
     */
    public static function current(): ?self
    {
        if (static::$current) {
            return static::$current;
        }

        try {
            return static::$current = static::instance();
        } catch (QueryException) {
            return null;
        }
    }

    public static function flushCache(): void
    {
        static::$current = null;
    }

    public static function allowsRegistration(): bool
    {
        return (bool) (static::current()?->settings['allow_registration'] ?? true);
    }

    public static function requiresEmailVerification(): bool
    {
        return (bool) (static::current()?->settings['require_email_verification'] ?? true);
    }

    /**
     * SMTP settings saved in the admin console, or null when the instance uses `.env`.
     * The password is decrypted here and must never be sent to the browser.
     *
     * @return array{host: string, port: int, encryption: ?string, username: ?string, password: ?string, from_address: string, from_name: string}|null
     */
    public static function mailSettings(): ?array
    {
        $mail = static::current()?->settings['mail'] ?? null;

        if (! is_array($mail) || empty($mail['enabled']) || empty($mail['host'])) {
            return null;
        }

        return [
            'host' => (string) $mail['host'],
            'port' => (int) ($mail['port'] ?? 587),
            'encryption' => $mail['encryption'] ?? null,
            'username' => $mail['username'] ?? null,
            'password' => static::decryptSecret($mail['password'] ?? null),
            'from_address' => (string) ($mail['from_address'] ?? config('mail.from.address')),
            'from_name' => (string) ($mail['from_name'] ?? config('mail.from.name')),
        ];
    }

    public static function encryptSecret(?string $value): ?string
    {
        return $value === null || $value === '' ? null : Crypt::encryptString($value);
    }

    private static function decryptSecret(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return null;
        }
    }
}
