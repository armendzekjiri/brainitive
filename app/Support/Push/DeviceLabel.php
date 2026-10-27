<?php

namespace App\Support\Push;

/**
 * Friendly names for a push subscription (BRAI-154): browser + OS from the user agent captured when
 * the browser subscribed, and which push service its endpoint belongs to.
 */
class DeviceLabel
{
    /** @return array{browser: string|null, os: string|null, platform: string} */
    public static function parse(?string $userAgent): array
    {
        $ua = (string) $userAgent;

        $browser = match (true) {
            $ua === '' => null,
            str_contains($ua, 'Edg/') || str_contains($ua, 'EdgA/') || str_contains($ua, 'EdgiOS/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'SamsungBrowser/') => 'Samsung Internet',
            str_contains($ua, 'Firefox/') || str_contains($ua, 'FxiOS/') => 'Firefox',
            str_contains($ua, 'CriOS/') || str_contains($ua, 'Chrome/') || str_contains($ua, 'Chromium/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => null,
        };

        [$os, $platform] = match (true) {
            $ua === '' => [null, 'other'],
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') || str_contains($ua, 'iPod') => [str_contains($ua, 'iPad') ? 'iPad' : 'iPhone', 'ios'],
            str_contains($ua, 'Android') => ['Android', 'android'],
            str_contains($ua, 'Windows') => ['Windows', 'windows'],
            str_contains($ua, 'CrOS') => ['ChromeOS', 'chromeos'],
            str_contains($ua, 'Macintosh') || str_contains($ua, 'Mac OS X') => ['macOS', 'macos'],
            str_contains($ua, 'Linux') => ['Linux', 'linux'],
            default => [null, 'other'],
        };

        return ['browser' => $browser, 'os' => $os, 'platform' => $platform];
    }

    /** "Chrome on Windows", "Safari on iPhone", or null when nothing is known. */
    public static function label(?string $userAgent): ?string
    {
        ['browser' => $browser, 'os' => $os] = self::parse($userAgent);

        return match (true) {
            $browser && $os => "{$browser} on {$os}",
            default => $browser ?? $os,
        };
    }

    /** @return array{host: string, name: string} */
    public static function service(string $endpoint): array
    {
        $host = strtolower((string) parse_url($endpoint, PHP_URL_HOST));

        $name = match (true) {
            $host === 'fcm.googleapis.com', $host === 'android.googleapis.com' => 'Chrome/Edge (Google push)',
            $host === 'updates.push.services.mozilla.com' => 'Firefox',
            $host === 'web.push.apple.com', str_ends_with($host, '.push.apple.com') => 'Safari / iPhone',
            str_ends_with($host, '.notify.windows.com') => 'Edge (Windows push)',
            default => $host,
        };

        return ['host' => $host, 'name' => $name];
    }
}
