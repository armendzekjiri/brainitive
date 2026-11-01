<?php

namespace App\Support\Updates;

use App\Models\InstanceSettings;
use App\Models\User;
use App\Support\AppVersion;
use App\Support\Legal\LegalInfo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "A new version is available" for self-hosted instance admins (BRAI-433). Awareness only — nothing
 * is downloaded or installed.
 *
 * The scheduler runs {@see run()} once a day (`brainitive:check-update`): one anonymous GET of the
 * public repository's latest release, nothing about the instance is sent. Pages only read the stored
 * result (`instance_settings.settings.update_check`), so no request ever waits for GitHub.
 */
final class UpdateCheck
{
    public const REPOSITORY = 'armendzekjiri/brainitive';

    public const ENDPOINT = 'https://api.github.com/repos/'.self::REPOSITORY.'/releases/latest';

    public const RELEASES_URL = 'https://github.com/'.self::REPOSITORY.'/releases';

    public const UPGRADE_DOCS_URL = 'https://docs.brainitive.app/self-hosting/upgrading';

    /** users.onboarding_state key: the release this admin dismissed the in-app notice for */
    public const DISMISSED_KEY = 'update_notice_dismissed';

    private const SETTINGS_KEY = 'update_check';

    /** BRAINITIVE_UPDATE_CHECK: forced on / off, else on unless this is Brainitive Cloud (incl. dev). */
    public static function enabled(): bool
    {
        $explicit = config('app.update_check');
        if ($explicit !== null && $explicit !== '') {
            return filter_var($explicit, FILTER_VALIDATE_BOOL);
        }

        return ! LegalInfo::isCloud();
    }

    /**
     * Ask GitHub for the latest release and store it. Never throws: offline, rate-limited (403) or an
     * odd answer is logged at info level and the last good result is kept.
     *
     * @return array{status: 'disabled'|'failed'|'ok', reason?: string}
     */
    public static function run(): array
    {
        if (! self::enabled()) {
            return ['status' => 'disabled'];
        }

        // the e2e server never talks to GitHub (specs record a release with --fake-latest)
        if (app()->environment('e2e')) {
            return self::failed('not fetched on the e2e server');
        }

        try {
            $response = Http::timeout(5)->connectTimeout(3)
                ->withHeaders([
                    'Accept' => 'application/vnd.github+json',
                    'User-Agent' => 'Brainitive',
                    'X-GitHub-Api-Version' => '2022-11-28',
                ])
                ->get(self::ENDPOINT);
        } catch (Throwable $e) {
            return self::failed('GitHub unreachable: '.$e->getMessage());
        }

        if (! $response->successful()) {
            return self::failed('GitHub answered HTTP '.$response->status());
        }

        $release = $response->json();
        if (! is_array($release) || ($release['draft'] ?? false) || ($release['prerelease'] ?? false)) {
            return self::failed('no published stable release');
        }

        $version = AppVersion::normalize(is_string($release['tag_name'] ?? null) ? $release['tag_name'] : null);
        if ($version === null) {
            return self::failed('latest release tag is not vMAJOR.MINOR.PATCH');
        }

        $url = is_string($release['html_url'] ?? null) && str_starts_with($release['html_url'], 'https://github.com/')
            ? $release['html_url']
            : self::RELEASES_URL.'/tag/v'.$version;

        self::record($version, $url, self::date($release['published_at'] ?? null));

        return ['status' => 'ok'];
    }

    /** Store a release as the latest one (also used by the e2e-only `--fake-latest`). */
    public static function record(string $version, ?string $url = null, ?string $publishedAt = null): void
    {
        $version = AppVersion::normalize($version) ?? throw new \InvalidArgumentException("Not a version: {$version}");

        self::write([
            'latest_version' => $version,
            'release_url' => $url ?? self::RELEASES_URL.'/tag/v'.$version,
            'published_at' => $publishedAt,
            'checked_at' => now()->toIso8601String(),
            'failed_at' => null,
        ]);
    }

    /** Forget the stored result (`brainitive:check-update --clear`). */
    public static function clear(): void
    {
        $settings = InstanceSettings::instance();
        $all = $settings->settings ?? [];
        unset($all[self::SETTINGS_KEY]);
        $settings->settings = $all;
        $settings->save();
    }

    /**
     * Everything the admin console shows.
     *
     * @return array{enabled: bool, current: ?string, latest: ?string, release_url: ?string, published_at: ?string, checked_at: ?string, failed_at: ?string, update_available: bool, releases_url: string, upgrade_docs_url: string}
     */
    public static function status(): array
    {
        $stored = self::stored();
        $current = AppVersion::current();
        $enabled = self::enabled();

        return [
            'enabled' => $enabled,
            'current' => $current,
            'latest' => $stored['latest_version'],
            'release_url' => $stored['release_url'],
            'published_at' => $stored['published_at'],
            'checked_at' => $stored['checked_at'],
            'failed_at' => $stored['failed_at'],
            'update_available' => $enabled && AppVersion::isNewer($stored['latest_version'], $current),
            'releases_url' => self::RELEASES_URL,
            'upgrade_docs_url' => self::UPGRADE_DOCS_URL,
        ];
    }

    /**
     * The in-app notice (shared prop `updateNotice`): only for instance admins, only when a newer
     * release is known and this admin hasn't dismissed that release (or a newer one).
     *
     * @return array{current: string, latest: string, release_url: ?string, published_at: ?string, upgrade_docs_url: string}|null
     */
    public static function noticeFor(?User $user, bool $knownInstanceAdmin = false): ?array
    {
        if (! $user || ! self::enabled() || ! ($knownInstanceAdmin || $user->isInstanceAdmin())) {
            return null;
        }

        $status = self::status();
        if (! $status['update_available']) {
            return null;
        }

        $dismissed = $user->onboarding_state[self::DISMISSED_KEY] ?? null;
        if (is_string($dismissed) && ! AppVersion::isNewer($status['latest'], $dismissed)) {
            return null;
        }

        return [
            'current' => (string) $status['current'],
            'latest' => (string) $status['latest'],
            'release_url' => $status['release_url'],
            'published_at' => $status['published_at'],
            'upgrade_docs_url' => self::UPGRADE_DOCS_URL,
        ];
    }

    public static function dismiss(User $user, string $version): void
    {
        $state = $user->onboarding_state ?? [];
        $state[self::DISMISSED_KEY] = AppVersion::normalize($version);
        $user->onboarding_state = $state;
        $user->save();
    }

    /** @return array{latest_version: ?string, release_url: ?string, published_at: ?string, checked_at: ?string, failed_at: ?string} */
    private static function stored(): array
    {
        $raw = InstanceSettings::current()?->settings[self::SETTINGS_KEY] ?? [];
        $raw = is_array($raw) ? $raw : [];
        $string = fn (string $key) => is_string($raw[$key] ?? null) && $raw[$key] !== '' ? $raw[$key] : null;

        return [
            'latest_version' => AppVersion::normalize($string('latest_version')),
            'release_url' => $string('release_url'),
            'published_at' => $string('published_at'),
            'checked_at' => $string('checked_at'),
            'failed_at' => $string('failed_at'),
        ];
    }

    /** @param  array<string, mixed>  $values */
    private static function write(array $values): void
    {
        // a fresh row (not the per-request memo) so a concurrent admin save isn't overwritten with stale values
        $settings = InstanceSettings::instance();
        $all = $settings->settings ?? [];
        $current = is_array($all[self::SETTINGS_KEY] ?? null) ? $all[self::SETTINGS_KEY] : [];
        $all[self::SETTINGS_KEY] = array_merge($current, $values);
        $settings->settings = $all;
        $settings->save();
    }

    /** @return array{status: 'failed', reason: string} */
    private static function failed(string $reason): array
    {
        Log::info('Update check skipped: '.$reason);

        try {
            self::write(['failed_at' => now()->toIso8601String()]);
        } catch (Throwable) {
            // no settings table yet (before the first migrate): nothing to remember
        }

        return ['status' => 'failed', 'reason' => $reason];
    }

    private static function date(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }
}
