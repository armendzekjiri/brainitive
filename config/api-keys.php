<?php

/*
| Developer API (v1.52.0): personal API keys for the App API /api/v1 (App\Support\ApiKeys).
| Every user on every plan may create keys; the rate limits protect the server.
*/

return [

    // Self-hosted: API_KEYS_ENABLED=false turns personal API keys off for the whole instance (every key
    // answers 401 `api_keys_disabled`, nobody can create one). Existing keys work again when it's back on.
    'enabled' => (bool) env('API_KEYS_ENABLED', true),

    // Per key and minute: GET / HEAD count as reads, everything else as writes (plus 600/min per IP).
    'rate_limits' => [
        'read' => (int) env('API_KEYS_READ_PER_MINUTE', 120),
        'write' => (int) env('API_KEYS_WRITE_PER_MINUTE', 60),
    ],

    // Active keys per user.
    'max_per_user' => 50,

    // Expiry choices offered when creating a key (days; null = never).
    'expiry_days' => [30, 90, 365, null],

];
