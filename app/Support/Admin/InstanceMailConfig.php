<?php

namespace App\Support\Admin;

use App\Models\InstanceSettings;

/**
 * Applies the SMTP server saved in the admin console on top of the `.env` mail config.
 *
 * Runs when the mail manager is first resolved and again before every queued job, never at
 * boot — `config:cache` boots the app, and the SMTP password must not end up in the cached
 * config file. Turning the admin SMTP off restores the `.env` values.
 */
class InstanceMailConfig
{
    /** @var array<string, mixed>|null The `.env` values, captured before the first override. */
    private static ?array $original = null;

    public static function apply(): void
    {
        static::$original ??= [
            'mail.default' => config('mail.default'),
            'mail.mailers.smtp' => config('mail.mailers.smtp'),
            'mail.from' => config('mail.from'),
        ];

        $mail = InstanceSettings::mailSettings();

        if (! $mail) {
            config(static::$original);
        } else {
            config([
                'mail.default' => 'smtp',
                'mail.mailers.smtp' => array_merge(static::$original['mail.mailers.smtp'] ?? [], [
                    'transport' => 'smtp',
                    'scheme' => $mail['encryption'] === 'ssl' ? 'smtps' : 'smtp',
                    'host' => $mail['host'],
                    'port' => $mail['port'],
                    'username' => $mail['username'],
                    'password' => $mail['password'],
                ]),
                'mail.from' => ['address' => $mail['from_address'], 'name' => $mail['from_name']],
            ]);
        }

        if (app()->resolved('mail.manager')) {
            app('mail.manager')->forgetMailers();
        }
    }
}
