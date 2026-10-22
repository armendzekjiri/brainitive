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
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : self::resolve($host);

        if ($ips === []) {
            return null;
        }

        foreach ($ips as $ip) {
            if (! $allowPrivate && ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)) {
                return null;
            }
        }

        $pinned = str_contains($ips[0], ':') ? "[{$ips[0]}]" : $ips[0];

        return [
            'allow_redirects' => false,
            'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$pinned}"]],
        ];
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
}
