<?php

namespace App\Support\Legal;

use Illuminate\Http\Request;

/**
 * Where the operator's cookieless Umami script may run (BRAI-156): only on the configured hosts
 * (brainitive.app), and never on pages that belong to a customer (support pages, lead forms,
 * portals, share links — their visitors are the customer's, not ours) or that carry a secret in
 * the path (password reset, e-mail verification, invitations, calendar feeds).
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
        if (! in_array($request->getHost(), (array) config('legal.analytics.hosts', []), true)) {
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

    /** @return list<string> */
    public static function excludedPrefixes(): array
    {
        return self::EXCLUDED_PREFIXES;
    }
}
