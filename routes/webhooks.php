<?php

use Illuminate\Support\Facades\Route;

/*
 * Outgoing webhooks (v1.39.0, ticket 3), loaded inside the `workspaces/{workspace}` group of web.php
 * (auth + verified). Workspace admins only: Gate::authorize('update', $workspace).
 * Route names: webhooks.* (index, store, update, destroy, test, deliveries, redeliver).
 * Throttles name their own counter: throttle:N,M,<route name> (RouteThrottleTest).
 * Contract: docs/ai/tasks/release-v1.39.0.md.
 */

// ── Ticket 3: outgoing webhooks ───────────────────────────────────────────────
