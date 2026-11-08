<?php

namespace App\Providers;

use App\Support\ApiKeys\ApiKeyAccess;
use App\Support\ApiKeys\ApiKeyGate;
use App\Support\ApiKeys\ApiKeys;
use App\Support\AppApi\AppTokens;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * App API v1 for the desktop and mobile apps (BRAI-380 / BRAI-428): the `app` guard and its
 * throttles. Routes: routes/app-api.php (loaded in bootstrap/app.php under /api/v1, group `app-api`).
 */
class AppApiServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // guard `app` (config/auth.php): opaque device tokens, never Passport ones
        Auth::viaRequest('app-token', fn (Request $request) => AppTokens::userFromRequest($request));

        // personal API keys (Developer API, v1.52.0): deny-only — a key's user may do less, never more
        Gate::before(fn ($user, string $ability, array $arguments = []) => ApiKeyGate::before($user, $ability, $arguments));
        // a job run on the sync queue inside a key's request is system work: unscoped, like on a worker
        Queue::before(fn () => ApiKeyAccess::suspend());
        Queue::after(fn () => ApiKeyAccess::resume());
        Queue::failing(fn () => ApiKeyAccess::resume());

        // throttle:app-api — every /api/v1 request: per token and per IP
        RateLimiter::for('app-api', function (Request $request) {
            $token = $request->bearerToken();

            // personal API keys (Developer API): per key, reads and writes apart (config/api-keys.php)
            if (ApiKeys::looksLike($token)) {
                $write = ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true);
                $kind = $write ? 'write' : 'read';

                // the key's limit last: its numbers are the X-RateLimit-* headers the caller sees
                return [
                    Limit::perMinute(600)->by('app-api:ip:'.$request->ip()),
                    Limit::perMinute(max(1, (int) config('api-keys.rate_limits.'.$kind)))->by('api-key:'.$kind.':'.substr(ApiKeys::hash($token), 0, 32)),
                ];
            }

            return [
                Limit::perMinute(240)->by('app-api:token:'.($token ? substr(AppTokens::hash($token), 0, 24) : $request->ip())),
                Limit::perMinute(600)->by('app-api:ip:'.$request->ip()),
            ];
        });

        // throttle:app-auth — code exchange and refresh: no token yet, so per IP
        RateLimiter::for('app-auth', fn (Request $request) => Limit::perMinute(20)->by('app-auth:'.$request->ip()));

        // Device-code sign-in (TV, RFC 8628). Starting one: per IP. Polling: per IP, generous because
        // several wallboards can share an office IP — the per-code `interval` (slow_down) is the real limit.
        RateLimiter::for('app-device-start', fn (Request $request) => Limit::perMinute(10)->by('app-device-start:'.$request->ip()));
        RateLimiter::for('app-device-poll', fn (Request $request) => Limit::perMinute(120)->by('app-device-poll:'.$request->ip()));

        // /app/device (web): typing / confirming codes, per signed-in user and per IP (brute force)
        RateLimiter::for('app-device-entry', fn (Request $request) => [
            Limit::perMinute(15)->by('app-device-entry:user:'.($request->user()?->id ?? $request->ip())),
            Limit::perMinute(40)->by('app-device-entry:ip:'.$request->ip()),
        ]);
    }
}
