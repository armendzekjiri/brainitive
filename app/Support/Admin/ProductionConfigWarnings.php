<?php

namespace App\Support\Admin;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Production settings that leak secrets or credentials when a development `.env` is copied to a
 * server (Codex config review 9): APP_DEBUG=true (stack traces with environment values on error
 * pages), the `log` mailer (reset / invitation links written to the log) and a plain-http APP_URL
 * (passwords and session cookies in the clear). Nothing is changed; each problem is logged as a
 * warning, at most once an hour per problem, so it shows up in the logs an operator reads.
 */
class ProductionConfigWarnings
{
    /**
     * The problems of the current configuration (empty outside production).
     *
     * @return array<string, string> key => message
     */
    public static function atBoot(): array
    {
        if (! app()->environment('production')) {
            return [];
        }

        $problems = [];

        if (config('app.debug')) {
            $problems['app-debug'] = 'APP_DEBUG=true in production: error pages show stack traces and configuration values. Set APP_DEBUG=false.';
        }

        $url = (string) config('app.url');
        if (str_starts_with($url, 'http://') && ! self::isLocalUrl($url)) {
            $problems['app-url-http'] = "APP_URL is plain http ({$url}) in production: passwords and session cookies travel unencrypted. Serve HTTPS and set APP_URL=https://….";
        }

        return $problems;
    }

    /** Logs the boot-time problems (called from AppServiceProvider::boot). */
    public static function reportAtBoot(): void
    {
        foreach (self::atBoot() as $key => $message) {
            self::warnOnce($key, $message);
        }
    }

    /**
     * A mail is about to be written to the log instead of being sent. Checked when mail is sent,
     * not at boot: an SMTP server saved in the admin console replaces MAIL_MAILER=log at runtime
     * (InstanceMailConfig), so only the mailer actually used counts.
     */
    public static function onMessageSending(MessageSending $event): void
    {
        if (! app()->environment('production')) {
            return;
        }

        $mailer = $event->data['mailer'] ?? config('mail.default');
        if (config("mail.mailers.{$mailer}.transport") === 'log') {
            self::warnOnce('mail-log', "Mail is written to the log (mailer \"{$mailer}\") in production: password-reset and invitation links end up in the log files. Set MAIL_MAILER=smtp (or SMTP under Instance admin → Settings).");
        }
    }

    private static function warnOnce(string $key, string $message): void
    {
        try {
            if (! Cache::add("production-config-warning:{$key}", true, now()->addHour())) {
                return;
            }
        } catch (Throwable) {
            // no cache (yet): warn anyway
        }

        Log::warning("[config] {$message}");
    }

    private static function isLocalUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true) || str_ends_with($host, '.localhost');
    }
}
