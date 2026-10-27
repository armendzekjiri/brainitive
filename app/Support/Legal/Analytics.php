<?php

namespace App\Support\Legal;

use Illuminate\Http\Request;

/**
 * Where the operator's cookieless Umami may run (BRAI-156): only on the configured hosts
 * (brainitive.app), and never on pages that belong to a customer (support pages, lead forms,
 * portals, share links — their visitors are the customer's, not ours) or that carry a secret in
 * the path (password reset, e-mail verification, invitations, calendar feeds).
 *
 * Even there the server renders no tracker: it only hands the browser `clientConfig()`, and the
 * browser loads Umami after the visitor clicks "Allow" (consent, § 25 (1) TDDDG —
 * resources/js/lib/analytics, components/legal/AnalyticsConsentNotice).
 */
class Analytics
{
    /** Path prefixes without analytics; also checked in the browser for client-side navigations. */
    private const EXCLUDED_PREFIXES = [
        '/f', '/support', '/widget', '/portal', '/feedback', '/client', '/guest',
        '/s', '/r', '/changelog', '/share', '/embed-test',
        '/invite', '/reset-password', '/verify-email', '/calendar/feed', '/oauth', '/offline',
    ];

    public static function enabledFor(Request $request): bool
    {
        if (! in_array($request->getHost(), (array) config('legal.analytics.hosts', []), true) && ! self::e2eOptIn($request)) {
            return false;
        }
        if (! filled(config('legal.analytics.script')) || ! filled(config('legal.analytics.website_id'))) {
            return false;
        }

        $path = '/'.ltrim($request->path(), '/');
        foreach (self::EXCLUDED_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return false;
            }
        }

        return true;
    }

    /**
     * What the browser needs to ask for consent and, once given, load the tracker.
     *
     * @return array{script: string, websiteId: string, excluded: list<string>}
     */
    public static function clientConfig(): array
    {
        return [
            'script' => (string) config('legal.analytics.script'),
            'websiteId' => (string) config('legal.analytics.website_id'),
            'excluded' => self::EXCLUDED_PREFIXES,
        ];
    }

    /** @return list<string> */
    public static function excludedPrefixes(): array
    {
        return self::EXCLUDED_PREFIXES;
    }

    /** e2e only: analytics-consent.spec.ts opts its requests in; never outside APP_ENV=e2e. */
    private static function e2eOptIn(Request $request): bool
    {
        return config('legal.analytics.e2e_opt_in') && app()->environment('e2e')
            && $request->header('X-E2E-Analytics') === '1';
    }
}
