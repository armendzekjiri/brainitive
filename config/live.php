<?php

/**
 * Live channels (BRAI-151, v1.44.0): pub/sub, presence and short signals on the realtime server of
 * live co-editing (config/collab.php) — same process, same port, same secret. Off whenever live
 * editing is off. Plan: docs/ai/tasks/feat-live-foundation.md.
 */
return [
    // Switch live channels off while keeping live co-editing.
    'enabled' => (bool) env('LIVE_ENABLED', true),

    // What the browser connects to. Empty = COLLAB_URL + "?live=1" (the realtime server tells the two
    // apart by that query, so nginx's `location = /collab` and Docker's Caddy need no change).
    'url' => env('LIVE_URL'),

    // Where Brainitive publishes events: the realtime server's own HTTP address, never the public URL
    // (prod http://127.0.0.1:6201, Docker http://realtime:1234). Empty = derived from the live URL when
    // that is ws://127.0.0.1|localhost:<port> (local dev); otherwise nothing is published (presence and
    // signals still work, the bell keeps polling).
    'publish_url' => env('LIVE_PUBLISH_URL'),

    // Lifetime of a channel token (seconds): only used to join; access is re-checked every minute.
    'token_ttl' => (int) env('LIVE_TOKEN_TTL', 120),
];
