<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Laravel's AddLinkHeadersForPreloadedAssets without the CSP nonce in the Link header.
 *
 * With Vite::useCspNonce() (SecurityHeaders) Laravel adds `nonce="…"` to every preloaded asset,
 * and a board page preloads ~140 chunks: the Link header grows by ~7 KB, past Node's 16 KB header
 * limit (Playwright's request client failed with "Header overflow") and towards nginx's fastcgi
 * buffer. The <link rel="modulepreload"> tags in the page keep their nonce; the header is only a
 * hint, so it goes out exactly as before the CSP.
 */
class AddLinkHeadersForPreloadedAssets
{
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        $response = $next($request);

        $assets = Vite::preloadedAssets();
        if ($response instanceof Response && $assets !== []) {
            $links = [];
            foreach ($assets as $url => $attributes) {
                $attributes = array_filter($attributes, fn ($attribute) => ! str_starts_with((string) $attribute, 'nonce='));
                $links[] = "<{$url}>; ".implode('; ', $attributes);
            }
            $response->header('Link', implode(', ', $links), false);
        }

        return $response;
    }
}
