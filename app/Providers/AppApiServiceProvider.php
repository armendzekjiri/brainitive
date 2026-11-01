<?php

namespace App\Providers;

use App\Support\AppApi\AppTokens;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

        // throttle:app-api — every /api/v1 request: per token and per IP
        RateLimiter::for('app-api', function (Request $request) {
            $token = $request->bearerToken();

            return [
                Limit::perMinute(240)->by('app-api:token:'.($token ? substr(AppTokens::hash($token), 0, 24) : $request->ip())),
                Limit::perMinute(600)->by('app-api:ip:'.$request->ip()),
            ];
        });

        // throttle:app-auth — code exchange and refresh: no token yet, so per IP
        RateLimiter::for('app-auth', fn (Request $request) => Limit::perMinute(20)->by('app-auth:'.$request->ip()));
    }
}
