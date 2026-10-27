<?php

namespace App\Support\Mail;

use App\Models\Helpdesk;
use App\Notifications\Messages\BrandedMailMessage;
use App\Support\Helpdesks\Mail\TicketMailer;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Str;

/**
 * Helpers for the branded mail layout (BRAI-155): the vendor/mail + vendor/notifications templates,
 * the `brainitive` theme and App\Notifications\Messages\BrandedMailMessage.
 */
final class BrandedMail
{
    /** App names that are framework defaults, never shown to a recipient. */
    private const PLACEHOLDER_NAMES = ['', 'laravel', 'brainitive'];

    /** Called once from AppServiceProvider::boot(). */
    public static function register(): void
    {
        // Echoed values in mail templates can't open Markdown links / images or HTML (defence in depth on
        // top of the notification classes' plain() helpers).
        Markdown::withSecuredEncoding();

        // Laravel's password reset mail on the branded layout; the URL is still built by the
        // createUrlUsing callback (APP_URL, never the request host).
        ResetPassword::toMailUsing(function (object $notifiable, string $token) {
            $url = ResetPassword::$createUrlCallback
                ? call_user_func(ResetPassword::$createUrlCallback, $notifiable, $token)
                : url(route('password.reset', ['token' => $token, 'email' => $notifiable->getEmailForPasswordReset()], false));
            $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);

            return BrandedMailMessage::account($notifiable, 'You get this email because someone asked to reset the password of your Brainitive account.')
                ->subject('Reset your Brainitive password')
                ->headline('Reset your password')
                ->preheader("Choose a new password — the link works for {$minutes} minutes.")
                ->line('We received a request to reset the password for your account. Choose a new one with the button below.')
                ->action('Reset password', $url)
                ->line("The link works for {$minutes} minutes. If you didn't ask for a new password, ignore this email — nothing changes.");
        });
    }

    /** The instance's own name for the footer ("Brainitive — Acme"), or null when it is a default. */
    public static function instanceName(): ?string
    {
        $name = trim((string) config('app.name'));

        return in_array(mb_strtolower($name), self::PLACEHOLDER_NAMES, true) ? null : self::line($name, 60);
    }

    /**
     * Who writes to a helpdesk's customers: the connected mailbox's sender name (the same name the
     * threaded replies use, TicketMailer), else the helpdesk name.
     */
    public static function helpdeskName(?Helpdesk $helpdesk): string
    {
        if (! $helpdesk) {
            return 'Support';
        }

        return self::line((string) (TicketMailer::mailbox($helpdesk)?->from_name ?: $helpdesk->name), 80) ?: 'Support';
    }

    /** The recipient's display name, if the notifiable has one. */
    public static function nameOf(object $notifiable): ?string
    {
        $name = method_exists($notifiable, 'displayName') ? $notifiable->displayName() : ($notifiable->name ?? null);

        return is_string($name) && trim($name) !== '' ? $name : null;
    }

    /** "Hi Ann," (first name, plain text) or "Hi there,". */
    public static function greeting(?string $name): string
    {
        $name = self::line((string) $name, 60);
        $first = Str::before($name, ' ');
        $name = mb_strlen($first) >= 2 ? $first : $name;

        // a visitor-typed name is never a link or host name in our mail
        if ($name === '' || preg_match('#://|www\.|@|\w\.[a-z]{2,}#i', $name)) {
            return 'Hi there,';
        }

        return "Hi {$name},";
    }

    /**
     * One line of text (no line breaks, at most $limit characters). Not escaped here: the templates echo it
     * escaped, and headline / eyebrow / footer are HTML blocks that Markdown never parses.
     */
    public static function line(string $text, int $limit = 200): string
    {
        return Str::limit(trim(preg_replace('/\s+/u', ' ', $text) ?? ''), $limit);
    }

    /**
     * Plain text as one HTML fragment that Markdown never parses: escaped, line breaks kept as <br />
     * (no blank line can end the surrounding HTML block early) and backslashes as entities (the secured
     * Markdown encoding would otherwise turn "\\" into "\" after rendering).
     */
    public static function htmlText(string $text): string
    {
        $text = trim(preg_replace("/\R{3,}/u", "\n\n", $text) ?? $text);

        return str_replace('\\', '&#92;', nl2br(e($text), false));
    }

    /** Stored, sanitised rich text for a raw mail block: only backslashes become entities (see htmlText()). */
    public static function rawHtml(string $html): string
    {
        return str_replace('\\', '&#92;', $html);
    }

    /** The absolute URL of a named route, always on APP_URL (never the host of the request that sent the mail). */
    public static function appRoute(string $name, array $parameters = []): string
    {
        return rtrim((string) (config('app.url') ?: url('/')), '/').route($name, $parameters, false);
    }

    /**
     * The hidden preview line under the subject in inboxes: the first non-empty entry.
     *
     * @param  iterable<mixed>  $lines
     */
    public static function preheaderFrom(iterable $lines): ?string
    {
        foreach ($lines as $line) {
            // **bold** and [label](url) read as plain words in an inbox preview
            $text = self::line(preg_replace(['/\[([^\]]*)\]\([^)]*\)/u', '/[*_`#]+/u'], ['$1', ''], (string) $line) ?? '', 150);
            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }
}
