<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\NullSessionHandler;
use Symfony\Component\HttpFoundation\Response;

/**
 * For read-only background requests (fetched on every page load): read the session, never write
 * it back. Laravel saves the whole session at the end of each request, last write wins — so such
 * a fetch that started before a form submit and finished after it put back its older copy and
 * wiped the validation errors / flash message the submit had just stored (found by the e2e
 * suite, BRAI-96). Must run inside StartSession (web group): it swaps the handler before saving.
 */
class ReadOnlySession
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->hasSession()) {
            $request->session()->setHandler(new NullSessionHandler);
        }

        return $response;
    }
}
