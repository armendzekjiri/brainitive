<?php

/*
| App API v1 (/api/v1) for the first-party desktop and mobile apps — BRAI-380 / BRAI-428.
| Separate from Passport (MCP, CLI, browser extension): own tokens, own guard `app`.
*/

return [

    // Access tokens are short; the app refreshes them. Refresh tokens rotate on every use.
    'access_ttl' => 15 * 60,
    'refresh_ttl' => 60 * 24 * 60 * 60,

    // Single-use sign-in codes (browser → app, app → webview session), kept in the cache.
    'code_ttl' => 60,

    // Signed-in installs per user; signing in on one more revokes the least recently used.
    'max_devices' => 10,

    // First-party clients only. The redirect is where /app/authorize sends the one-time code.
    'clients' => [
        'desktop' => ['name' => 'Brainitive Desktop', 'redirect' => 'brainitive://auth/callback'],
        'mobile' => ['name' => 'Brainitive Mobile', 'redirect' => 'brainitive://auth/callback'],
    ],

    // Oldest app versions this server works with; /api/v1/instance tells the apps.
    'min_versions' => [
        'desktop' => env('APP_API_MIN_DESKTOP_VERSION', '0.1.0'),
        'mobile' => env('APP_API_MIN_MOBILE_VERSION', '0.1.0'),
    ],

];
