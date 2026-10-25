<?php

namespace App\Support;

/**
 * Guard for server-side requests to URLs that users choose (bookmark previews, AI
 * endpoints). Only http(s), and the host must resolve to public IP addresses — no
 * localhost, private networks or cloud metadata (169.254.169.254). The connection is
 * pinned to the address that was checked (CURLOPT_RESOLVE), so DNS can't switch it to an
 * internal one afterwards (rebinding). Callers must not follow redirects blindly: check
 * every Location with `options()` again.
 */
class OutboundUrl
{
    /**
     * Guzzle options for a request to $url, or null when it isn't allowed.
     *
     * @return array<string, mixed>|null
     */
    public static function options(string $url, bool $allowPrivate = false): ?array
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = trim($parts['host'] ?? '', '[]');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $ip = self::publicAddress($host, $allowPrivate);

        if ($ip === null) {
            return null;
        }

        $pinned = str_contains($ip, ':') ? "[{$ip}]" : $ip;

        return [
            'allow_redirects' => false,
            'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$pinned}"]],
        ];
    }

    /**
     * The address to connect to for $host (a name or an IP literal), or null when it doesn't resolve
     * or ANY of its addresses is private / loopback / link-local / reserved. Raw sockets to
     * user-chosen hosts (helpdesk IMAP / SMTP, BRAI-79) connect to this address, pinned.
     */
    public static function publicAddress(string $host, bool $allowPrivate = false): ?string
    {
        $host = trim($host, '[]');
        if ($host === '') {
            return null;
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : self::resolve($host);

        if ($ips === []) {
            return null;
        }

        foreach ($ips as $ip) {
            if (! $allowPrivate && (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) || self::embedsIpv4($ip))) {
                return null;
            }
        }

        return $ips[0];
    }

    /** Private networks are allowed for AI endpoints only when the instance opts in. */
    public static function allowPrivateAiEndpoints(): bool
    {
        return (bool) config('services.ai.allow_private_endpoints', false);
    }

    /**
     * @return list<string>
     */
    private static function resolve(string $host): array
    {
        $ips = gethostbynamel($host) ?: [];

        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (! empty($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }

    /** NAT64 (64:ff9b::/96, 64:ff9b:1::/48), 6to4 (2002::/16), Teredo (2001::/32) reach IPv4 hosts behind a gateway. */
    private static function embedsIpv4(string $ip): bool
    {
        if (! str_contains($ip, ':') || ($bin = @inet_pton($ip)) === false) {
            return false;
        }
        $hex = bin2hex($bin);

        return str_starts_with($hex, '0064ff9b')    // 64:ff9b::/32 (covers /96 and 64:ff9b:1::/48)
            || str_starts_with($hex, '2002')        // 6to4
            || str_starts_with($hex, '20010000');   // Teredo
    }
}
