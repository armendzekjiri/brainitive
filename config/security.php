<?php

/*
 * Browser security headers on web pages (App\Http\Middleware\SecurityHeaders). Framing protection
 * (X-Frame-Options / frame-ancestors) lives in FrameProtection.
 */
return [

    /*
     * HTTP Strict Transport Security: browsers only ever use HTTPS for this host once they saw it.
     * Sent on HTTPS responses only, and by default only in production. No `preload`: that is a
     * separate, hard-to-undo decision for the whole domain. Behind a reverse proxy the request
     * counts as HTTPS only when TRUSTED_PROXIES covers the proxy (X-Forwarded-Proto).
     */
    'hsts' => [
        'enabled' => (bool) env('HSTS_ENABLED', env('APP_ENV') === 'production'),
        'max_age' => (int) env('HSTS_MAX_AGE', 31536000),
        'include_subdomains' => (bool) env('HSTS_INCLUDE_SUBDOMAINS', true),
    ],

    /*
     * Content-Security-Policy for scripts, sent as REPORT-ONLY: nothing is blocked, the browser only
     * reports what a nonce-based policy would block (the console, or CSP_REPORT_URI when set), so
     * the policy can be checked against the editor, whiteboards, embeds and the desktop app before
     * it is ever enforced. CSP_MODE: report-only (default) | off. Enforcing is deliberately not an
     * option yet.
     */
    'csp' => [
        'mode' => env('CSP_MODE', 'report-only'),
        'report_uri' => env('CSP_REPORT_URI'),
    ],

];
