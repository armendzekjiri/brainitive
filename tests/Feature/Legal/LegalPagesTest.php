<?php

namespace Tests\Feature\Legal;

use App\Support\Legal\Analytics;
use App\Support\Legal\LegalInfo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Legal pages (BRAI-156): Brainitive Cloud shows its documents with the operator's facts from
 * config/legal.php; a self-hosted install shows only its own operator's data — never Brainitive's.
 */
class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    private const CLOUD_FACTS = ['hello@brainitive.app', 'OVHcloud', 'MXroute', 'stats.novabrainhub.de'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['legal.operator' => array_fill_keys(array_keys(config('legal.operator')), null)]);
        config(['legal.documents' => ['privacy_url' => null, 'terms_url' => null, 'imprint_url' => null]]);
    }

    public function test_cloud_pages_render_with_the_operators_facts(): void
    {
        config(['legal.cloud' => true]);

        foreach (['/privacy' => 'Privacy', '/terms' => 'Terms', '/cookies' => 'Cookies', '/imprint' => 'Legal/Imprint', '/legal/subprocessors' => 'Legal/Subprocessors', '/legal/dpa' => 'Legal/Dpa'] as $url => $component) {
            $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component($component)
                ->where('legal.cloud', true)
                ->where('legal.operator.email', 'hello@brainitive.app')
                ->where('legal.operator.name', null) // the operator doesn't publish a name (yet)
                ->where('legal.providers.hosting', 'OVHcloud')
                ->where('legal.providers.email', 'MXroute')
                ->has('legal.document.version')
                ->has('legal.document.updated'));
        }
        $this->get('/refund')->assertOk();
    }

    public function test_configured_operator_values_win_over_the_cloud_defaults(): void
    {
        config(['legal.cloud' => true, 'legal.operator.name' => 'Jane Operator', 'legal.operator.email' => 'legal@example.com', 'legal.providers.hosting_location' => 'Gravelines, France']);

        $this->get('/imprint')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('legal.operator.name', 'Jane Operator')
            ->where('legal.operator.email', 'legal@example.com')
            ->where('legal.providers.hosting_location', 'Gravelines, France'));
    }

    public function test_self_hosted_install_never_shows_brainitives_operator_data(): void
    {
        config(['legal.cloud' => false]);

        foreach (['/privacy', '/terms', '/cookies'] as $url) {
            $response = $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('legal.cloud', false)
                ->where('legal.operator.email', null)
                ->where('legal.providers.hosting', null)
                ->where('legal.providers.email', null)
                ->where('legal.imprint', null)
                ->where('legal.subprocessors', null)
                ->where('legal.dpa', null));
            foreach (self::CLOUD_FACTS as $fact) {
                $response->assertDontSee($fact, false);
            }
        }

        // nothing to show → no imprint; Cloud-only documents don't exist here
        $this->get('/imprint')->assertNotFound();
        $this->get('/legal/subprocessors')->assertNotFound();
        $this->get('/legal/dpa')->assertNotFound();
        $this->get('/refund')->assertNotFound();
    }

    public function test_self_hosted_imprint_shows_only_the_installs_own_operator(): void
    {
        config(['legal.cloud' => false, 'legal.operator.name' => 'Example GmbH', 'legal.operator.address' => "Street 1\n12345 Town", 'legal.operator.email' => 'info@example.com']);

        $this->get('/imprint')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Legal/Imprint')
            ->where('legal.operator.name', 'Example GmbH')
            ->where('legal.operator.email', 'info@example.com')
            ->where('legal.providers.hosting', null))
            ->assertDontSee('hello@brainitive.app', false);
    }

    public function test_self_hosted_imprint_can_point_to_the_operators_own_page(): void
    {
        config(['legal.cloud' => false, 'legal.documents.imprint_url' => 'https://example.com/imprint']);

        $this->get('/imprint')->assertRedirect('https://example.com/imprint');
        $this->assertSame('https://example.com/imprint', LegalInfo::links()['imprint']);

        config(['legal.documents.imprint_url' => 'javascript:alert(1)']);
        $this->assertNull(LegalInfo::links()['imprint'], 'only http(s) links');
    }

    public function test_cloud_mode_is_detected_from_app_url_when_not_set(): void
    {
        config(['legal.cloud' => null, 'app.url' => 'https://www.brainitive.app']);
        $this->assertTrue(LegalInfo::isCloud());

        config(['app.url' => 'https://dev.brainitive.app']);
        $this->assertTrue(LegalInfo::isCloud());

        config(['app.url' => 'https://brainitive.example.org']);
        $this->assertFalse(LegalInfo::isCloud());
    }

    public function test_every_page_shares_the_footer_links(): void
    {
        config(['legal.cloud' => true]);

        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('legalLinks.imprint', route('imprint'))
            ->where('legalLinks.privacy', route('privacy'))
            ->where('legalLinks.subprocessors', route('legal.subprocessors')));

        config(['legal.cloud' => false]);
        $this->get('/login')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('legalLinks.imprint', null)
            ->where('legalLinks.subprocessors', null)
            ->where('legalLinks.terms', route('terms')));
    }

    // ── analytics ─────────────────────────────────────────────────────────────

    public function test_analytics_only_on_brainitive_app_and_never_on_customer_or_secret_pages(): void
    {
        $on = fn (string $url) => Analytics::enabledFor(Request::create($url));

        $this->assertTrue($on('https://www.brainitive.app/'));
        $this->assertTrue($on('https://brainitive.app/privacy'));
        $this->assertTrue($on('https://www.brainitive.app/workspaces'));

        $this->assertFalse($on('http://localhost/'), 'self-hosted / dev / e2e');
        $this->assertFalse($on('https://dev.brainitive.app/'));
        foreach (['/f/demo', '/f/demo/embed', '/support/acme', '/widget/helpdesks/x/embed', '/portal/tickets/x', '/feedback/acme', '/client/acme',
            '/guest/login', '/s/abc', '/r/abc', '/changelog/abc', '/share/plans/abc', '/invite/abc', '/reset-password/abc', '/verify-email/1/abc', '/calendar/feed/abc.ics'] as $path) {
            $this->assertFalse($on('https://www.brainitive.app'.$path), $path);
        }
    }

    public function test_the_server_never_renders_the_tracker_only_the_consent_config_on_brainitive_app(): void
    {
        $this->get('/')->assertOk()
            ->assertDontSee('stats.novabrainhub.de', false)
            ->assertDontSee('window.brainitiveAnalytics', false);

        // brainitive.app: no <script src=…stats…> — the browser loads it only after "Allow"
        $html = $this->get('https://www.brainitive.app/')->assertOk()->getContent();
        $this->assertStringContainsString('window.brainitiveAnalytics = ', $html);
        $this->assertStringContainsString('stats.novabrainhub.de\\/script.js', $html, 'the script URL is only data');
        $this->assertDoesNotMatchRegularExpression('#<script[^>]+src="https://stats\.novabrainhub\.de#', $html);
        $this->assertStringContainsString('"excluded":["\\/f"', $html);

        $this->get('https://www.brainitive.app/f/missing')->assertDontSee('brainitiveAnalytics', false);
    }

    public function test_the_e2e_analytics_opt_in_only_works_in_the_e2e_environment(): void
    {
        $request = fn () => tap(Request::create('http://127.0.0.1:8010/'), fn (Request $r) => $r->headers->set('X-E2E-Analytics', '1'));

        config(['legal.analytics.e2e_opt_in' => true]);
        $this->assertFalse(Analytics::enabledFor($request()), 'APP_ENV=testing ignores the header');

        $this->app['env'] = 'e2e';
        try {
            $this->assertTrue(Analytics::enabledFor($request()));
            $this->assertFalse(Analytics::enabledFor(Request::create('http://127.0.0.1:8010/')), 'without the header');
            $support = Request::create('http://127.0.0.1:8010/support/acme');
            $support->headers->set('X-E2E-Analytics', '1');
            $this->assertFalse(Analytics::enabledFor($support), 'customer pages stay excluded');

            config(['legal.analytics.e2e_opt_in' => false]);
            $this->assertFalse(Analytics::enabledFor($request()), 'only with ANALYTICS_E2E_OPT_IN');
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    // ── no third-party requests from our own assets ──────────────────────────

    public function test_no_third_party_fonts_cdns_or_favicon_services_in_the_sources(): void
    {
        $sources = [
            resource_path('css/app.css'),
            resource_path('views/app.blade.php'),
            resource_path('views/excalidraw-editor.blade.php'),
            resource_path('js/components/editor/nodes/EquationNode.tsx'),
            app_path('Jobs/FetchLinkMetadataJob.php'),
            ...glob(resource_path('views/widgets/*.blade.php')),
        ];
        foreach ($sources as $file) {
            $content = file_get_contents($file);
            foreach (['fonts.googleapis.com', 'fonts.gstatic.com', 'cdn.quilljs.com', 'cdn.jsdelivr.net', 'unpkg.com', 'esm.sh', 'https://www.google.com/s2/favicons'] as $host) {
                $this->assertStringNotContainsString($host, $content, basename($file)." loads {$host}");
            }
        }

        $this->assertFileExists(resource_path('fonts/reenie-beanie/OFL.txt'), 'the self-hosted font ships with its licence');
        $this->assertStringContainsString('EXCALIDRAW_ASSET_PATH', file_get_contents(resource_path('views/app.blade.php')));
        $this->assertStringContainsString('EXCALIDRAW_ASSET_PATH', file_get_contents(resource_path('views/excalidraw-editor.blade.php')));
    }

    public function test_the_built_assets_have_no_google_fonts_import(): void
    {
        $manifest = public_path('build/manifest.json');
        if (! file_exists($manifest)) {
            $this->markTestSkipped('no frontend build');
        }
        foreach (glob(public_path('build/assets/*.css')) as $css) {
            $this->assertStringNotContainsString('fonts.googleapis.com', file_get_contents($css), basename($css));
        }
    }
}
