<?php

namespace App\Support\Helpdesks\Mail;

use App\Support\OutboundUrl;

/**
 * Guard for the IMAP / SMTP servers that helpdesk admins type in (v1.40.0, BRAI-79). Same rule as
 * every other server-side connection to a user-chosen host (OutboundUrl): the host must resolve to
 * public addresses only, and the socket connects to the checked address (pinned, TLS still verifies
 * the host name), so DNS can't switch it to an internal one afterwards. Only mail ports are allowed,
 * so the poller can't be used as a port scanner.
 *
 * Private / loopback hosts and unencrypted connections are allowed only in e2e, and in local
 * development with HELPDESK_MAIL_ALLOW_PRIVATE_HOSTS=true. Every other environment ignores the flag.
 */
final class MailHost
{
    public const HOSTNAME = '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i';

    public static function allowPrivate(): bool
    {
        return app()->environment('e2e')
            || (app()->environment('local') && (bool) config('helpdesk_mail.allow_private_hosts'));
    }

    /** e2e only: fake IMAP / SMTP connections (no network). */
    public static function fake(): bool
    {
        return app()->environment('e2e') && (bool) config('helpdesk_mail.fake');
    }

    /** @return list<int> */
    public static function ports(string $kind): array
    {
        return array_map('intval', (array) config($kind === 'smtp' ? 'helpdesk_mail.smtp_ports' : 'helpdesk_mail.imap_ports'));
    }

    /**
     * Why $host:$port may not be used, or null when it may. Doesn't resolve DNS when $resolve is false
     * (format + port only).
     */
    public static function problem(string $host, int $port, string $kind, bool $resolve = true): ?string
    {
        $host = strtolower(trim($host));
        $label = strtoupper($kind);

        if (! filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) && ! preg_match(self::HOSTNAME, $host)) {
            return "The {$label} server must be a host name like mail.example.com.";
        }
        if (! in_array($port, self::ports($kind), true)) {
            return "The {$label} port must be one of ".implode(', ', self::ports($kind)).'.';
        }
        if ($resolve && self::fake()) {
            return null;
        }
        if ($resolve && OutboundUrl::publicAddress($host, self::allowPrivate()) === null) {
            return "The {$label} server must be reachable on the public internet (no localhost or private networks).";
        }

        return null;
    }

    /**
     * The checked address to open the socket to ("[v6]" for IPv6), or a MailboxException.
     */
    public static function connectAddress(string $host, int $port, string $kind): string
    {
        if (($problem = self::problem($host, $port, $kind, resolve: false)) !== null) {
            throw new MailboxException($problem);
        }

        $ip = OutboundUrl::publicAddress(strtolower(trim($host)), self::allowPrivate());
        if ($ip === null) {
            throw new MailboxException('The '.strtoupper($kind).' server must be reachable on the public internet (no localhost or private networks).');
        }

        return str_contains($ip, ':') ? "[{$ip}]" : $ip;
    }
}
