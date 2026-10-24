<?php

namespace App\Http\Middleware;

use App\Support\Automation\AutomationEvents;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Automation rules triggered during a request start once the controller has finished (v1.40.0,
 * BRAI-63): the issue then has its labels and fields, and on the sync queue the redirect already
 * shows what the rule did. Global middleware, so web, MCP and API requests behave the same.
 */
class DeferAutomations
{
    public function handle(Request $request, Closure $next): Response
    {
        return AutomationEvents::defer(fn () => $next($request));
    }
}
