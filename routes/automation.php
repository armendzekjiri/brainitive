<?php

use Illuminate\Support\Facades\Route;

/*
 * Automation (v1.40.0), loaded inside `workspaces/{workspace}` → `projects/{project:slug}` (scopeBindings)
 * in web.php (auth + verified). One section per ticket. Contract: docs/ai/tasks/release-v1.40.0.md.
 * Throttles name their own counter: throttle:N,M,<route name> (RouteThrottleTest).
 */

// ── Ticket 1 (BRAI-63): workflow automation rules — automations.* ─────────────

// ── Ticket 2: recurring issues — recurring-issues.* ───────────────────────────

// ── Ticket 5: helpdesk SLA — helpdesks.sla.* ──────────────────────────────────
