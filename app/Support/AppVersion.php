<?php

namespace App\Support;

/**
 * The running Brainitive version (BRAI-433), e.g. "1.48.0".
 *
 * One source of truth: the newest entry of `CHANGELOG` in resources/js/components/HelpModal.tsx —
 * the same entry the release workflow tags (.github/scripts/changelog-release.mjs). The file ships
 * with every Docker image and git checkout, so there is no generated file or second version string
 * to keep in step. `config('app.version')` overrides it (tests; no env var).
 */
final class AppVersion
{
    /** The changelog sits near the top of the file; never read the whole 100 KB. */
    private const READ_BYTES = 65536;

    private static ?string $memo = null;

    private static bool $resolved = false;

    /** "MAJOR.MINOR.PATCH", or null when the changelog can't be read (a stripped package). */
    public static function current(): ?string
    {
        $override = config('app.version');
        if (is_string($override) && $override !== '') {
            return self::normalize($override);
        }

        if (! self::$resolved) {
            self::$memo = self::fromChangelog();
            self::$resolved = true;
        }

        return self::$memo;
    }

    /** "v1.48.0" / "1.48.0" → "1.48.0"; anything else (pre-release suffixes included) → null. */
    public static function normalize(?string $version): ?string
    {
        if (! is_string($version) || ! preg_match('/^v?(\d{1,4})\.(\d{1,4})\.(\d{1,4})$/', trim($version), $m)) {
            return null;
        }

        return ((int) $m[1]).'.'.((int) $m[2]).'.'.((int) $m[3]);
    }

    /** True when $candidate is a strictly higher version than $than (both normalised first). */
    public static function isNewer(?string $candidate, ?string $than): bool
    {
        $candidate = self::normalize($candidate);
        $than = self::normalize($than);

        return $candidate !== null && $than !== null && version_compare($candidate, $than, '>');
    }

    public static function flush(): void
    {
        self::$memo = null;
        self::$resolved = false;
    }

    private static function fromChangelog(): ?string
    {
        // the tracked folder is lowercase; a checkout on a case-insensitive disk may say "Components"
        foreach (['js/components/HelpModal.tsx', 'js/Components/HelpModal.tsx'] as $relative) {
            $path = resource_path($relative);
            if (! is_file($path) || ! is_readable($path)) {
                continue;
            }

            $head = (string) @file_get_contents($path, false, null, 0, self::READ_BYTES);
            $start = strpos($head, 'const CHANGELOG = [');
            if ($start === false) {
                continue;
            }

            if (preg_match("/version:\s*'(v\d+\.\d+\.\d+)'/", substr($head, $start), $m)) {
                return self::normalize($m[1]);
            }
        }

        return null;
    }
}
