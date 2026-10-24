<?php

/*
 * Outgoing webhooks (v1.39.0, BRAI-104). See App\Support\Webhooks\WebhookUrl.
 */
return [
    // Seconds per delivery attempt (connect + response).
    'timeout' => 10,

    // E2E ONLY: lets the Playwright suite deliver to its receiver on 127.0.0.1. Honoured only when
    // APP_ENV=e2e (WebhookUrl::allowPrivate()); every other environment ignores it, so it can't be
    // switched on in production or staging.
    'allow_private_targets' => env('WEBHOOKS_ALLOW_PRIVATE_TARGETS', false),
];
