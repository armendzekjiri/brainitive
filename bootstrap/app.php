<?php

use App\Http\Middleware\AddCorsHeaders;
use App\Http\Middleware\DeferAutomations;
use App\Http\Middleware\EnsureInstanceAdmin;
use App\Http\Middleware\EnsureUserIsNotSuspended;
use App\Http\Middleware\FrameProtection;
use App\Http\Middleware\GuardOAuthFlows;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Self-hosting behind a reverse proxy: TRUSTED_PROXIES = the proxy's address(es) / CIDR ranges.
        // `*` trusts X-Forwarded-* from ANY client (spoofable IPs and hosts) — only when nothing
        // can reach the app except through the proxy.
        if ($trustedProxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: $trustedProxies === '*' ? '*' : array_map('trim', explode(',', $trustedProxies)));
        }

        $middleware->append(GuardOAuthFlows::class);
        // automation rules triggered by a request start after its controller finished (BRAI-63)
        $middleware->append(DeferAutomations::class);

        $middleware->web(append: [
            GuardOAuthFlows::class, // the /oauth/authorize check needs the session (StartSession runs in this group)
            FrameProtection::class,
            EnsureUserIsNotSuspended::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'cors' => AddCorsHeaders::class,
            'instance.admin' => EnsureInstanceAdmin::class,
        ]);

        // Public helpdesk widget/portal (embedded on external sites) — no session, no CSRF
        $middleware->validateCsrfTokens(except: [
            'widget/*',
            'portal/*',
            'f/*', // public lead forms: submit + analytics events (stateless)
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Friendly error pages (Pages/Error.tsx) instead of bare status screens; JSON stays JSON.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            $status = $response->getStatusCode();

            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return $response;
            }

            // Expired session / CSRF token: back to where they were, or to the login page.
            if ($status === 419) {
                $message = 'Your session expired. Please try again.';

                return auth()->check() && ! $request->routeIs('login', 'logout')
                    ? back()->with('error', $message)
                    : redirect()->route('login')->with('status', 'Your session expired. Please sign in again.');
            }

            $friendly = [401, 403, 404, 405, 410, 429, 500, 503];
            // Keep the detailed exception page for 5xx while developing.
            if (! in_array($status, $friendly, true) || ($status >= 500 && config('app.debug'))) {
                return $response;
            }

            // Route-model binding (a missing project, workspace…) fails before
            // HandleInertiaRequests shares `auth` & co., so share it here.
            try {
                Inertia::share(app(HandleInertiaRequests::class)->share($request));
            } catch (Throwable) {
                Inertia::share('auth', ['user' => null]);
            }

            return Inertia::render('Error', ['status' => $status])
                ->toResponse($request)
                ->setStatusCode($status);
        });
    })->create();
