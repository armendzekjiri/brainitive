<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * HSTS on HTTPS responses (production by default) and a nonce-based script CSP in REPORT-ONLY mode
 * on web pages (config/security.php). The nonce is created before the page renders, so Vite's tags,
 * Ziggy's @routes and the layout's inline scripts carry it (Vite::cspNonce()).
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = $this->cspEnabled() ? Vite::useCspNonce() : null;

        $response = $next($request);

        if (config('security.hsts.enabled') && $request->isSecure() && ! $response->headers->has('Strict-Transport-Security')) {
            $response->headers->set('Strict-Transport-Security', $this->hsts());
        }

        if ($nonce !== null && $this->isHtml($response) && ! $response->headers->has('Content-Security-Policy-Report-Only')) {
            $response->headers->set('Content-Security-Policy-Report-Only', $this->scriptPolicy($nonce));
        }

        return $response;
    }

    private function cspEnabled(): bool
    {
        return config('security.csp.mode', 'report-only') === 'report-only';
    }

    private function hsts(): string
    {
        $value = 'max-age='.max(0, (int) config('security.hsts.max_age', 31536000));

        return config('security.hsts.include_subdomains', true) ? $value.'; includeSubDomains' : $value;
    }

    /**
     * "Strict CSP": scripts need this request's nonce; scripts they load are trusted in turn
     * ('strict-dynamic'). 'unsafe-inline' and https: are only a fallback for browsers without
     * CSP 3, which ignore nonces; current browsers ignore both when a nonce is present.
     */
    private function scriptPolicy(string $nonce): string
    {
        $policy = "script-src 'nonce-{$nonce}' 'strict-dynamic' 'unsafe-inline' https:; object-src 'none'; base-uri 'self'";

        $reportUri = config('security.csp.report_uri');
        if (is_string($reportUri) && $reportUri !== '') {
            $policy .= '; report-uri '.$reportUri;
        }

        return $policy;
    }

    private function isHtml(Response $response): bool
    {
        $type = (string) $response->headers->get('Content-Type', '');

        return $type === '' || str_contains(strtolower($type), 'text/html');
    }
}
