<?php

namespace App\Http\Middleware;

use App\Support\ApiKeys\ApiKeys;
use App\Support\AppApi\AppApiErrors;
use App\Support\AppApi\AppClients;
use App\Support\AppApi\AppTokens;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The App API routes a client may call (BRAI-377): `config/app-api.php` → `clients.*.abilities`, matched
 * against the route name without `app.v1.`. Desktop and mobile may call everything; the TV (device-code
 * sign-in) a read-mostly list, so a phished approval can't change the account, export data, sign other
 * devices out or open a web session. Everything else → 403 `client_not_allowed`. Runs after `auth:app`.
 */
class EnsureAppClientAbility
{
    public function handle(Request $request, Closure $next): Response
    {
        // personal API keys have no device: AuthorizeApiKey decides what they may call (Developer API, v1.52.0)
        if (ApiKeys::looksLike($request->bearerToken())) {
            return $next($request);
        }

        // the guard puts the device on the request when it resolves the token; a guard that already knew the
        // user (one app instance serving several requests, as in tests) didn't — look it up then
        $device = AppTokens::currentDevice($request) ?? AppTokens::deviceForAccessToken($request->bearerToken());
        $route = $request->route()?->getName();
        $route = is_string($route) && str_starts_with($route, 'app.v1.') ? substr($route, strlen('app.v1.')) : null;

        if (! $device || ! AppClients::allows((string) $device->client, $route)) {
            return AppApiErrors::json(403, 'This app can’t use this part of Brainitive. Open it on the web, the desktop or the mobile app.', code: AppApiErrors::CLIENT_NOT_ALLOWED);
        }

        return $next($request);
    }
}
