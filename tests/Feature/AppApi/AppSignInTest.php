<?php

namespace Tests\Feature\AppApi;

use App\Models\InstanceSettings;
use App\Models\User;
use App\Support\AppApi\AppAuthCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\TestCase;

/**
 * Native sign-in, browser side (BRAI-355 / BRAI-383): /app/authorize consent page and decision,
 * every login method coming back to it, and /app/session/{code} for the app's webview.
 */
class AppSignInTest extends TestCase
{
    use ActsAsAppClient;
    use RefreshDatabase;

    private const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        InstanceSettings::flushCache();
    }

    protected function tearDown(): void
    {
        InstanceSettings::flushCache();
        parent::tearDown();
    }

    /** @return array<string, string> */
    private function params(array $overrides = []): array
    {
        return array_filter($overrides + [
            'client' => 'desktop',
            'code_challenge' => self::CHALLENGE,
            'code_challenge_method' => 'S256',
            'state' => 'state-123',
            'device_name' => 'Ada’s MacBook',
        ], fn ($v) => $v !== null);
    }

    private function authorizeUrl(array $overrides = []): string
    {
        return '/app/authorize?'.http_build_query($this->params($overrides));
    }

    /** Back on /app/authorize with the same parameters (url.intended normalises the query order). */
    private function assertBackOnConsent(TestResponse $response, string $url): void
    {
        $location = (string) $response->assertRedirect()->headers->get('Location');
        $this->assertSame('/app/authorize', parse_url($location, PHP_URL_PATH));
        parse_str((string) parse_url($location, PHP_URL_QUERY), $back);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $sent);
        ksort($back);
        ksort($sent);
        $this->assertSame($sent, $back);
    }

    // ── consent page ────────────────────────────────────────────────────────────────────────

    public function test_a_signed_in_user_sees_the_consent_page(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);

        $this->actingAs($user)->get($this->authorizeUrl())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('AppAuth/Authorize')
                ->where('email', 'ada@example.com')
                ->where('client.name', 'Brainitive Desktop')
                ->where('request.state', 'state-123')
                ->where('request.code_challenge', self::CHALLENGE)
                ->where('request.device_name', 'Ada’s MacBook')
                ->missing('error'));
    }

    public function test_a_guest_signs_in_with_a_password_and_comes_back_to_the_consent_page(): void
    {
        $user = User::factory()->create();
        $url = $this->authorizeUrl();

        $this->get($url)->assertRedirect(route('login'));

        $this->assertBackOnConsent($this->post('/login', ['email' => $user->email, 'password' => 'password']), $url);
        $this->assertAuthenticatedAs($user);
    }

    public function test_google_and_github_logins_come_back_to_the_consent_page(): void
    {
        foreach (['google' => 'login.google.callback', 'github' => 'login.github.callback'] as $provider => $callback) {
            $user = User::factory()->create();
            $url = $this->authorizeUrl(['state' => 'via-'.$provider]);
            Auth::logout();
            $this->flushSession();

            $this->get($url)->assertRedirect(route('login'));

            $social = (new SocialiteUser)->setRaw(['email_verified' => true])
                ->map(['id' => $provider.'-'.$user->id, 'email' => $user->email, 'name' => $user->name]);
            $driver = Mockery::mock(Provider::class);
            $driver->shouldReceive('user')->andReturn($social);
            Socialite::shouldReceive('driver')->with($provider)->andReturn($driver);

            $this->assertBackOnConsent($this->get(route($callback, ['code' => 'c', 'state' => 's'])), $url);
            $this->assertAuthenticatedAs($user);
        }
    }

    public function test_a_microsoft_login_comes_back_to_the_consent_page(): void
    {
        $tid = '72f988bf-86f1-41af-91ab-2d7cd011db47';
        $oid = '00000000-0000-0000-66f3-3332eca7ea81';
        config(['services.microsoft' => [
            'client_id' => 'client-123', 'client_secret' => 'secret', 'tenant' => 'common',
            'redirect' => 'https://app.test/calendar/connections/outlook/callback',
            'login_redirect' => 'https://app.test/login/microsoft/callback',
        ]]);
        $user = User::factory()->create(['microsoft_id' => $tid.':'.$oid]);
        $url = $this->authorizeUrl();

        $this->get($url)->assertRedirect(route('login'));
        $this->get(route('login.microsoft'))->assertRedirect();
        $flow = session('auth_microsoft_oauth');

        $b64 = fn (array $part) => rtrim(strtr(base64_encode((string) json_encode($part)), '+/', '-_'), '=');
        $claims = [
            'aud' => 'client-123', 'iss' => 'https://login.microsoftonline.com/'.$tid.'/v2.0',
            'iat' => time(), 'nbf' => time(), 'exp' => time() + 3600, 'nonce' => $flow['nonce'],
            'tid' => $tid, 'oid' => $oid, 'sub' => 'sub', 'name' => 'Ada', 'ver' => '2.0',
        ];
        Http::preventStrayRequests();
        Http::fake(['login.microsoftonline.com/*' => Http::response([
            'token_type' => 'Bearer', 'expires_in' => 3600, 'access_token' => 'at',
            'id_token' => $b64(['typ' => 'JWT', 'alg' => 'RS256']).'.'.$b64($claims).'.c2ln',
        ])]);

        $this->assertBackOnConsent($this->get(route('login.microsoft.callback', ['code' => 'auth-code', 'state' => $flow['state']])), $url);
        $this->assertAuthenticatedAs($user);
    }

    public function test_an_unverified_account_must_verify_its_email_first(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get($this->authorizeUrl())->assertRedirect(route('verification.notice'));
        $this->actingAs($user)->post('/app/authorize', $this->params(['decision' => 'approve']))
            ->assertRedirect(route('verification.notice'));
    }

    public function test_invalid_parameters_show_an_error_page_and_never_redirect_to_the_app(): void
    {
        $user = User::factory()->create();

        $invalid = [
            ['client' => 'evil'],
            ['client' => null],
            ['code_challenge_method' => 'plain'],
            ['code_challenge_method' => null],
            ['code_challenge' => 'too-short'],
            ['code_challenge' => str_repeat('a', 42).'='],
            ['state' => null],
            ['state' => str_repeat('s', 256)],
            ['device_name' => str_repeat('d', 121)],
        ];

        foreach ($invalid as $overrides) {
            $this->actingAs($user)->get($this->authorizeUrl($overrides))
                ->assertStatus(400)
                ->assertInertia(fn (Assert $page) => $page->component('AppAuth/Authorize')->has('error')->missing('request'));

            $response = $this->actingAs($user)->post('/app/authorize', $this->params($overrides + ['decision' => 'approve']));
            $response->assertStatus(400);
            $this->assertNull($response->headers->get('Location'));
        }
    }

    // ── decision ────────────────────────────────────────────────────────────────────────────

    public function test_continue_sends_a_pkce_bound_code_to_the_app(): void
    {
        $user = User::factory()->create();

        $location = (string) $this->actingAs($user)->post('/app/authorize', $this->params(['decision' => 'approve']))
            ->assertRedirect()->headers->get('Location');

        $this->assertStringStartsWith('brainitive://auth/callback?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('state-123', $query['state']);
        $this->assertSame(64, strlen($query['code']));

        $this->assertSame([
            'user_id' => $user->id,
            'client' => 'desktop',
            'code_challenge' => self::CHALLENGE,
            'device_name' => 'Ada’s MacBook',
        ], AppAuthCodes::consume(AppAuthCodes::EXCHANGE, $query['code']));
    }

    public function test_an_inertia_post_gets_a_location_the_browser_navigates_to(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
            ->post('/app/authorize', $this->params(['decision' => 'approve', 'state' => 'a b&c']));

        $response->assertStatus(409);
        $location = (string) $response->headers->get('X-Inertia-Location');
        $this->assertStringStartsWith('brainitive://auth/callback?code=', $location);
        $this->assertStringEndsWith('&state=a%20b%26c', $location);
    }

    public function test_cancel_tells_the_app_access_was_denied(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/app/authorize', $this->params(['decision' => 'deny']))
            ->assertRedirect('brainitive://auth/callback?error=access_denied&state=state-123');
    }

    // ── webview session ─────────────────────────────────────────────────────────────────────

    private function webSessionCode(User $user, ?int $deviceId = null): string
    {
        return AppAuthCodes::issue(AppAuthCodes::WEB_SESSION, [
            'user_id' => $user->id,
            'device_id' => $deviceId ?? $this->appDevice($user)->id,
        ]);
    }

    public function test_a_web_session_code_signs_the_webview_in_and_remembers_it(): void
    {
        $user = User::factory()->create();

        $response = $this->get('/app/session/'.$this->webSessionCode($user))
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user, 'web');
        $remember = collect($response->headers->getCookies())->first(fn ($c) => str_starts_with($c->getName(), 'remember_web_'));
        $this->assertNotNull($remember, 'remember-me cookie, so the webview stays signed in across app restarts');
        $this->assertGreaterThan(now()->addYear()->getTimestamp(), $remember->getExpiresTime());
    }

    public function test_a_web_session_code_works_once(): void
    {
        $user = User::factory()->create();
        $code = $this->webSessionCode($user);

        $this->get('/app/session/'.$code)->assertRedirect(route('dashboard', absolute: false));
        Auth::logout();
        $this->flushSession();

        $this->get('/app/session/'.$code)->assertRedirect(route('login'))->assertSessionHas('status');
        $this->assertGuest();
        $this->get('/app/session/nope')->assertRedirect(route('login'));
    }

    public function test_a_web_session_needs_an_active_device_and_an_active_user(): void
    {
        $user = User::factory()->create();
        $device = $this->appDevice($user);
        $device->forceFill(['revoked_at' => now(), 'revoked_reason' => 'user'])->save();

        $this->get('/app/session/'.$this->webSessionCode($user, $device->id))->assertRedirect(route('login'));
        $this->assertGuest();

        // another user's device id in the payload
        $other = User::factory()->create();
        $this->get('/app/session/'.$this->webSessionCode($user, $this->appDevice($other)->id))->assertRedirect(route('login'));
        $this->assertGuest();

        $suspended = User::factory()->create(['suspended_at' => now()]);
        $this->get('/app/session/'.$this->webSessionCode($suspended))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_web_session_replaces_another_signed_in_user(): void
    {
        $someoneElse = User::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($someoneElse)->get('/app/session/'.$this->webSessionCode($user))->assertRedirect();

        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_only_a_relative_target_is_followed(): void
    {
        $user = User::factory()->create();
        $dashboard = route('dashboard', absolute: false);

        $this->get('/app/session/'.$this->webSessionCode($user).'?to='.urlencode('/my-work?tab=today'))
            ->assertRedirect('/my-work?tab=today');

        foreach (['//evil.example', 'https://evil.example/', '/\\evil.example', 'my-work', "/x\r\nLocation: //evil"] as $to) {
            $this->get('/app/session/'.$this->webSessionCode($user).'?to='.urlencode($to))->assertRedirect($dashboard);
        }
    }
}
