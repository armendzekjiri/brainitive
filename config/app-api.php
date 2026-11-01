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

    // Device-code sign-in (RFC 8628, the TV app): how long the code on the screen works, and how
    // often the device may ask whether it was approved (faster → `slow_down`, +5 s each time).
    'device_code_ttl' => 10 * 60,
    'device_code_interval' => 5,

    // Signed-in installs per user; signing in on one more revokes the least recently used.
    'max_devices' => 10,

    // First-party clients only, each with one way to sign in and the platforms it runs on:
    //  - `authorization_code`: /app/authorize (PKCE) sends the one-time code to `redirect`;
    //  - `device_code`: RFC 8628 (/api/v1/auth/device), for devices without a usable browser — the
    //    user confirms the code shown on the screen at /app/device on a phone or computer.
    'clients' => [
        'desktop' => ['name' => 'Brainitive Desktop', 'grant' => 'authorization_code', 'redirect' => 'brainitive://auth/callback', 'platforms' => ['macos', 'windows', 'linux']],
        'mobile' => ['name' => 'Brainitive Mobile', 'grant' => 'authorization_code', 'redirect' => 'brainitive://auth/callback', 'platforms' => ['ios', 'android']],
        'tv' => ['name' => 'Brainitive TV', 'grant' => 'device_code', 'redirect' => null, 'platforms' => ['android_tv']],
    ],

    // Oldest app versions this server works with; /api/v1/instance tells the apps.
    'min_versions' => [
        'desktop' => env('APP_API_MIN_DESKTOP_VERSION', '0.1.0'),
        'mobile' => env('APP_API_MIN_MOBILE_VERSION', '0.1.0'),
    ],

];
