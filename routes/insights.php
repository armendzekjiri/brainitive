<?php

use Illuminate\Support\Facades\Route;

/*
 * Admin insights (v1.39.0, ticket 4), loaded inside the `workspaces/{workspace}` group of web.php
 * (auth + verified). Workspace admins only: Gate::authorize('update', $workspace).
 * Route names: insights.* — `insights.index` redirects to the first tab. The sidebar link only
 * appears once `insights.index` exists (route().has). Contract: docs/ai/tasks/release-v1.39.0.md.
 */

// ── Ticket 4: admin insights ──────────────────────────────────────────────────
