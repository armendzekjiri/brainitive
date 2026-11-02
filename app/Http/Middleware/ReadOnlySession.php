<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\NullSessionHandler;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * For read-only background requests (fetched on every page load): read the session, never write
 * it back. Laravel saves the whole session at the end of each request, last write wins — so such
 * a fetch that started before a form submit and finished after it put back its older copy and
 * wiped the validation errors / flash message the submit had just stored (found by the e2e
 * suite, BRAI-96). Must run inside StartSession (web group): it swaps the handler before saving.
 *
 * Except when this very request signed the user back in from the remember-me cookie: that made a brand-new session
 * (nothing to clobber), and dropping it would leave the browser on a session id no request ever saved — realtime tokens
 * issued here would name a session that doesn't exist (RealtimeSession, Codex follow-up review), and every background
 * request would sign in from the cookie again.
 */
class ReadOnlySession
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->hasSession() && ! Auth::guard('web')->viaRemember()) {
            $request->session()->setHandler(new NullSessionHandler);
        }

        return $response;
    }
}
