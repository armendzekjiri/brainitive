<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Vite;
use Tests\TestCase;

/**
 * HSTS and the report-only script CSP (Codex config review 6, config/security.php).
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_web_pages_get_a_report_only_script_csp_whose_nonce_the_page_uses(): void
    {
        $response = $this->get(route('login'))->assertOk();

        $policy = (string) $response->headers->get('Content-Security-Policy-Report-Only');
        $this->assertMatchesRegularExpression("/^script-src 'nonce-[A-Za-z0-9]{40}' 'strict-dynamic' 'unsafe-inline' https:; object-src 'none'; base-uri 'self'$/", $policy);
        preg_match("/'nonce-([A-Za-z0-9]+)'/", $policy, $m);

        // the layout's inline scripts (theme, Ziggy's routes) carry this request's nonce
        $this->assertStringContainsString('<script nonce="'.$m[1].'">', $response->getContent());
        // nothing is enforced: no blocking script policy, framing protection unchanged
        $response->assertHeader('Content-Security-Policy', "frame-ancestors 'self'")
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN');

        // a fresh nonce per request
        $again = (string) $this->get(route('login'))->headers->get('Content-Security-Policy-Report-Only');
        $this->assertNotSame($policy, $again);
    }

    public function test_the_preload_link_header_carries_no_nonce(): void
    {
        // the real Vite manifest (public/build): ~140 preloads on a board page, a nonce on each grew
        // the Link header by ~7 KB (Node's 16 KB header limit, nginx fastcgi buffers)
        $this->withVite();
        // a running `composer run dev` writes public/hot (dev-server mode: no preloads); use the build
        Vite::useHotFile(storage_path('framework/testing/no-vite-hot-file'));
        $response = $this->get(route('login'))->assertOk();

        $link = (string) $response->headers->get('Link');
        $this->assertStringContainsString('rel="modulepreload"', $link);
        $this->assertStringNotContainsString('nonce', $link);
        // the tags in the page keep it
        $this->assertMatchesRegularExpression('/<link rel="modulepreload"[^>]* nonce="[A-Za-z0-9]{40}"/', $response->getContent());
    }

    public function test_json_responses_get_no_csp_and_the_mode_can_be_switched_off(): void
    {
        // an Inertia visit answers JSON: no page, no policy
        $inertia = ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request())];
        $this->get(route('login'), $inertia)->assertOk()->assertHeader('X-Inertia', 'true')
            ->assertHeaderMissing('Content-Security-Policy-Report-Only');

        config(['security.csp.mode' => 'off']);
        $this->get(route('login'))->assertOk()->assertHeaderMissing('Content-Security-Policy-Report-Only');
    }

    public function test_a_report_uri_is_only_added_when_configured(): void
    {
        $this->assertStringNotContainsString('report-uri', (string) $this->get(route('login'))->headers->get('Content-Security-Policy-Report-Only'));

        config(['security.csp.report_uri' => 'https://reports.example.com/csp']);
        $this->assertStringEndsWith('; report-uri https://reports.example.com/csp', (string) $this->get(route('login'))->headers->get('Content-Security-Policy-Report-Only'));
    }

    public function test_hsts_is_sent_on_https_responses_only_when_enabled(): void
    {
        // off outside production by default (this is APP_ENV=testing)
        $this->assertFalse(config('security.hsts.enabled'));
        $this->get('https://localhost/login')->assertHeaderMissing('Strict-Transport-Security');

        config(['security.hsts.enabled' => true]);
        $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        // never over plain HTTP
        $this->get('http://localhost/login')->assertHeaderMissing('Strict-Transport-Security');

        config(['security.hsts.include_subdomains' => false, 'security.hsts.max_age' => 600]);
        $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security', 'max-age=600');
    }
}
