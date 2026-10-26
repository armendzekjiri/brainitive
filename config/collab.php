<?php

/**
 * Live co-editing of documents (BRAI-54, v1.43.0): the Hocuspocus server in `realtime/`.
 * Off unless both COLLAB_URL and COLLAB_SECRET are set — then documents are edited alone, as before.
 * Setup: docs/ai/tasks/feat-live-docs.md → "Setup".
 */
return [
    // What the browser connects to, e.g. wss://brainitive.app/collab (nginx → 127.0.0.1:1234), or a
    // path on this host ("/collab": Docker's Caddy proxies it to the realtime service)
    'url' => env('COLLAB_URL'),

    // Shared with the realtime server (same value there). Signing keys are derived from it. Empty =
    // derived from APP_KEY (realtime/src/token.js does the same), so Docker needs no extra secret.
    'secret' => env('COLLAB_SECRET') ?: (env('APP_KEY') ? hash_hmac('sha256', 'brainitive-collab', (string) env('APP_KEY')) : null),

    // Lifetime of a connection token (seconds); the browser asks for a new one on every reconnect.
    'token_ttl' => (int) env('COLLAB_TOKEN_TTL', 300),

    // Largest Yjs state the realtime server may store for one document (bytes, before base64).
    'max_state_bytes' => (int) env('COLLAB_MAX_STATE_BYTES', 16 * 1024 * 1024),

    // e2e only (APP_ENV=e2e): live editing only for requests with `X-E2E-Collab: 1`, so the other
    // journeys keep testing the single-user editor while live-docs.spec.ts runs its own server.
    'e2e_opt_in' => (bool) env('COLLAB_E2E_OPT_IN', false),
];
