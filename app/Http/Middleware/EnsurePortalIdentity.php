<?php

namespace App\Http\Middleware;

use App\Support\Portal\PortalAuth;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `portal.identity` (v1.42.0): the route needs a signed-in guest or Brainitive user. The identity is
 * put on the request as attribute `portalIdentity`. Pages redirect to the sign-in form and come
 * back afterwards; JSON calls get 401.
 */
class EnsurePortalIdentity
{
    public function handle(Request $request, Closure $next): Response
    {
        $identity = PortalAuth::current($request);

        if (! $identity) {
            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                abort(401, 'Sign in with your email first.');
            }

            $back = $request->isMethod('GET') ? $request->getRequestUri() : (parse_url((string) $request->headers->get('referer'), PHP_URL_PATH) ?: null);

            return redirect()->route('guest.login', ['return_to' => PortalAuth::safeReturnTo($back)]);
        }

        $request->attributes->set('portalIdentity', $identity);

        return $next($request);
    }
}
