<?php

namespace Tests\Feature\AppApi;

use App\Models\AppDevice;
use App\Models\User;
use App\Notifications\AppRefreshReuseNotification;
use App\Support\AppApi\AppAuthCodes;
use App\Support\AppApi\AppTokens;
use App\Support\AppApi\AppWebSessions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\TestCase;

/**
 * App API v1 auth endpoints (BRAI-383 / BRAI-428): code exchange with PKCE, refresh rotation with
 * reuse detection, logout and the webview session — plus "Passport token → 401".
 */
class AppAuthTokensTest extends TestCase
{
    use ActsAsAppClient;
    use RefreshDatabase;

    private string $verifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->verifier = Str::random(64);
    }

    /** A consent-page code for the user, bound to $this->verifier. */
    private function code(User $user, string $client = 'desktop'): string
    {
        return AppAuthCodes::issue(AppAuthCodes::EXCHANGE, [
            'user_id' => $user->id,
            'client' => $client,
            'code_challenge' => AppAuthCodes::challengeFor($this->verifier),
            'device_name' => 'from the browser',
        ]);
    }

    private function exchange(string $code, array $overrides = []): TestResponse
    {
        return $this->postJson('/api/v1/auth/exchange', array_replace_recursive([
            'code' => $code,
            'code_verifier' => $this->verifier,
            'device' => ['name' => 'Ada’s MacBook', 'platform' => 'macos', 'app_version' => '0.1.0', 'os_version' => '15.1'],
        ], $overrides));
    }

    private function refresh(string $refreshToken): TestResponse
    {
        return $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $refreshToken]);
    }

    private function assertInvalidGrant(TestResponse $response, int $status): void
    {
        $response->assertStatus($status)->assertJsonPath('code', 'invalid_grant')->assertJsonStructure(['message', 'code']);
    }

    // ── the whole flow ──────────────────────────────────────────────────────────────────────

    public function test_authorize_exchange_refresh_and_web_session_sign_the_app_in(): void
    {
        $user = User::factory()->create();

        // the browser: consent page → Continue
        $location = (string) $this->actingAs($user)
            ->post('/app/authorize', [
                'client' => 'desktop',
                'code_challenge' => AppAuthCodes::challengeFor($this->verifier),
                'code_challenge_method' => 'S256',
                'state' => 'xyz',
                'decision' => 'approve',
            ])->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $callback);
        Auth::logout();
        $this->flushSession();

        // the app: exchange
        $tokens = $this->exchange($callback['code'])
            ->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('expires_in', 900)
            ->assertJsonPath('refresh_expires_in', 5184000)
            ->assertJsonPath('device.name', 'Ada’s MacBook')
            ->assertJsonPath('device.platform', 'macos')
            ->assertJsonStructure(['token_type', 'access_token', 'expires_in', 'refresh_token', 'refresh_expires_in', 'device' => ['id', 'name', 'platform']])
            ->json();
        $this->assertStringStartsWith('bra_', $tokens['access_token']);
        $this->assertStringStartsWith('brr_', $tokens['refresh_token']);

        $device = AppDevice::findOrFail($tokens['device']['id']);
        $this->assertSame($user->id, $device->user_id);
        $this->assertSame(['desktop', 'macos', '0.1.0', '15.1'], [$device->client, $device->platform, $device->app_version, $device->os_version]);
        $this->assertNotNull($device->last_used_at);
        $this->assertSame(AppTokens::hash($tokens['access_token']), $device->access_token_hash);

        // refresh: a new pair, the old one is dead
        $next = $this->refresh($tokens['refresh_token'])->assertOk()->assertJsonPath('device.id', $device->id)->json();
        $this->assertNotSame($tokens['access_token'], $next['access_token']);
        $this->postJson('/api/v1/auth/web-session', [], $this->appHeaders($tokens['access_token']))->assertStatus(401);

        // web session: the webview opens the url and is signed in
        $url = $this->postJson('/api/v1/auth/web-session', [], $this->appHeaders($next['access_token']))
            ->assertOk()
            ->assertJsonStructure(['data' => ['url']])
            ->json('data.url');
        $this->assertMatchesRegularExpression('#^https?://[^/]+/app/session/[A-Za-z0-9]{64}$#', $url);

        // (the desktop webview's user agent: one step; a plain browser gets a confirm page — AppSignInTest)
        $this->withHeader('User-Agent', 'Mozilla/5.0 BrainitiveDesktop/0.4.2')->get($url)->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($user, 'web');
    }

    // ── exchange ────────────────────────────────────────────────────────────────────────────

    public function test_a_verifier_that_does_not_match_burns_the_code(): void
    {
        $user = User::factory()->create();
        $code = $this->code($user);

        $this->assertInvalidGrant($this->exchange($code, ['code_verifier' => Str::random(64)]), 400);
        // the code is gone, even with the right verifier now
        $this->assertInvalidGrant($this->exchange($code), 400);
        $this->assertSame(0, AppDevice::count());
    }

    public function test_a_code_works_once(): void
    {
        $user = User::factory()->create();
        $code = $this->code($user);

        $this->exchange($code)->assertOk();
        $this->assertInvalidGrant($this->exchange($code), 400);
        $this->assertSame(1, AppDevice::count());
    }

    public function test_a_code_expires_after_60_seconds(): void
    {
        $code = $this->code(User::factory()->create());

        $this->travel(61)->seconds();

        $this->assertInvalidGrant($this->exchange($code), 400);
    }

    public function test_unknown_codes_and_web_session_codes_are_refused(): void
    {
        $user = User::factory()->create();

        $this->assertInvalidGrant($this->exchange(Str::random(64)), 400);
        $this->assertInvalidGrant($this->exchange('short'), 400);
        $webSession = AppAuthCodes::issue(AppAuthCodes::WEB_SESSION, ['user_id' => $user->id, 'code_challenge' => AppAuthCodes::challengeFor($this->verifier)]);
        $this->assertInvalidGrant($this->exchange($webSession), 400);
    }

    public function test_the_platform_must_belong_to_the_client(): void
    {
        $user = User::factory()->create();

        $this->exchange($this->code($user), ['device' => ['platform' => 'ios']])
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors('device.platform');
        $this->exchange($this->code($user, 'mobile'), ['device' => ['platform' => 'windows']])
            ->assertStatus(422)->assertJsonValidationErrors('device.platform');

        $this->exchange($this->code($user, 'mobile'), ['device' => ['platform' => 'android']])->assertOk()->assertJsonPath('device.platform', 'android');
        $this->assertSame('mobile', AppDevice::sole()->client);
    }

    public function test_exchange_validates_its_input(): void
    {
        $code = $this->code(User::factory()->create());

        $this->postJson('/api/v1/auth/exchange', [])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['code', 'code_verifier', 'device']);

        foreach ([str_repeat('a', 42), str_repeat('a', 129), str_repeat('a', 50).'!'] as $verifier) {
            $this->exchange($code, ['code_verifier' => $verifier])->assertStatus(422)->assertJsonValidationErrors('code_verifier');
        }
        $this->exchange($code, ['device' => ['platform' => 'beos']])->assertStatus(422)->assertJsonValidationErrors('device.platform');
        $this->exchange($code, ['device' => ['name' => str_repeat('n', 121)]])->assertStatus(422)->assertJsonValidationErrors('device.name');

        // validation failures don't use the code up
        $this->exchange($code)->assertOk();
    }

    public function test_a_suspended_user_cannot_exchange_a_code(): void
    {
        $user = User::factory()->create();
        $code = $this->code($user);
        $user->forceFill(['suspended_at' => now()])->save();

        $this->assertInvalidGrant($this->exchange($code), 400);
        $this->assertSame(0, AppDevice::count());
    }

    public function test_signing_in_beyond_the_device_limit_signs_out_the_least_recently_used(): void
    {
        config(['app-api.max_devices' => 2]);
        $user = User::factory()->create();
        $old = $this->appDevice($user, ['name' => 'Old']);
        $old->forceFill(['last_used_at' => now()->subDays(10)])->save();
        $recent = $this->appDevice($user, ['name' => 'Recent']);
        $recent->forceFill(['last_used_at' => now()->subHour()])->save();
        $oldToken = $this->appToken($user, $old);
        $other = $this->appDevice(User::factory()->create());

        $this->exchange($this->code($user))->assertOk();

        $this->assertSame('limit', $old->fresh()->revoked_reason);
        $this->assertNull($recent->fresh()->revoked_at);
        $this->assertNull($other->fresh()->revoked_at);
        $this->assertSame(2, $user->appDevices()->active()->count());
        $this->postJson('/api/v1/auth/logout', [], $this->appHeaders($oldToken))->assertStatus(401);
    }

    // ── refresh ─────────────────────────────────────────────────────────────────────────────

    public function test_a_refresh_token_presented_again_signs_the_device_out_and_emails_the_user(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $first = $this->exchange($this->code($user))->json();
        $second = $this->refresh($first['refresh_token'])->assertOk()->json();

        // the first refresh token again: reuse
        $this->assertInvalidGrant($this->refresh($first['refresh_token']), 401);

        $device = AppDevice::findOrFail($first['device']['id']);
        $this->assertSame('refresh_reuse', $device->revoked_reason);
        $this->assertNotNull($device->revoked_at);
        Notification::assertSentTo($user, AppRefreshReuseNotification::class, fn ($n) => $n->device->is($device));

        // the whole device is out: the newest pair too
        $this->postJson('/api/v1/auth/web-session', [], $this->appHeaders($second['access_token']))->assertStatus(401);
        $this->assertInvalidGrant($this->refresh($second['refresh_token']), 401);
        Notification::assertSentToTimes($user, AppRefreshReuseNotification::class, 1);
    }

    public function test_refresh_reuse_signs_out_only_that_device_and_its_webview_session(): void
    {
        Notification::fake();
        config(['session.driver' => 'database']);
        $user = User::factory()->create(['remember_token' => 'old-remember-token']);
        DB::table('sessions')->insert(['id' => 'browser-a', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->getTimestamp()]);
        $phone = $this->appDevice($user, ['client' => 'mobile', 'platform' => 'ios']);
        $passport = $this->passportToken($user);
        $first = $this->exchange($this->code($user))->json();
        $device = AppDevice::findOrFail($first['device']['id']);
        $this->refresh($first['refresh_token'])->assertOk();

        // the webview of the reused device holds a web session bound to it
        $this->actingAs($user)->withSession([AppWebSessions::SESSION_KEY => $device->id])->get('/profile')->assertOk();

        $this->assertInvalidGrant($this->refresh($first['refresh_token']), 401);

        $this->assertSame('refresh_reuse', $device->fresh()->revoked_reason);
        // a double refresh must not punish the rest: other apps, browsers, remember-me, CLI / MCP stay
        $this->assertNull($phone->fresh()->revoked_at);
        $this->assertTrue(DB::table('sessions')->where('id', 'browser-a')->exists(), 'other browser sessions stay');
        $this->assertSame('old-remember-token', $user->fresh()->remember_token);
        $this->assertFalse($user->tokens()->firstOrFail()->revoked);
        $this->assertNotEmpty($passport);
        Notification::assertSentToTimes($user, AppRefreshReuseNotification::class, 1);

        // that device's webview session ends on its next request
        $this->get('/profile')->assertRedirect(route('login'));
        $this->assertGuest('web');
    }

    public function test_the_reuse_email_is_branded_account_mail(): void
    {
        $user = User::factory()->create(['name' => 'Ada']);
        $device = $this->appDevice($user, ['name' => '[click](https://evil.example)']);

        $html = (string) (new AppRefreshReuseNotification($device))->toMail($user)->render();

        $this->assertStringContainsString('We signed out one of your devices', $html);
        $this->assertStringContainsString('Brainitive Desktop', $html);
        $this->assertStringNotContainsString('href="https://evil.example"', $html);
    }

    public function test_bad_expired_and_revoked_refresh_tokens_are_refused(): void
    {
        $user = User::factory()->create();

        $this->assertInvalidGrant($this->refresh('brr_'.Str::random(48)), 401);
        $this->assertInvalidGrant($this->refresh('not-a-token'), 401);
        $this->postJson('/api/v1/auth/refresh', [])->assertStatus(422)->assertJsonValidationErrors('refresh_token');

        $expired = $this->exchange($this->code($user))->json();
        AppDevice::findOrFail($expired['device']['id'])->forceFill(['refresh_expires_at' => now()->subSecond()])->save();
        $this->assertInvalidGrant($this->refresh($expired['refresh_token']), 401);

        $revoked = $this->exchange($this->code($user))->json();
        AppTokens::revoke(AppDevice::findOrFail($revoked['device']['id']), 'user');
        $this->assertInvalidGrant($this->refresh($revoked['refresh_token']), 401);

        // an access token is not a refresh token
        $this->assertInvalidGrant($this->refresh($this->exchange($this->code($user))->json('access_token')), 401);
    }

    // ── logout + web session ────────────────────────────────────────────────────────────────

    public function test_logout_signs_only_this_device_out(): void
    {
        $user = User::factory()->create();
        $tokens = $this->exchange($this->code($user))->json();
        $otherToken = $this->appToken($user);

        $this->postJson('/api/v1/auth/logout', [], $this->appHeaders($tokens['access_token']))->assertNoContent();
        Auth::forgetGuards(); // the test client keeps the guard's user between requests

        $this->assertSame('sign_out', AppDevice::findOrFail($tokens['device']['id'])->revoked_reason);
        $this->postJson('/api/v1/auth/web-session', [], $this->appHeaders($tokens['access_token']))->assertStatus(401);
        $this->assertInvalidGrant($this->refresh($tokens['refresh_token']), 401);
        $this->postJson('/api/v1/auth/web-session', [], $this->appHeaders($otherToken))->assertOk();
    }

    public function test_a_web_session_code_is_bound_to_the_device(): void
    {
        $user = User::factory()->create();
        $device = $this->appDevice($user);
        $url = $this->postJson('/api/v1/auth/web-session', [], $this->appHeaders($this->appToken($user, $device)))->json('data.url');
        Auth::forgetGuards();

        // the device is signed out before the webview opens the link
        AppTokens::revoke($device, 'user');

        $this->get($url)->assertRedirect(route('login'));
        $this->assertGuest('web');
    }

    // ── who may call ────────────────────────────────────────────────────────────────────────

    public function test_passport_tokens_and_no_token_get_401_on_every_signed_in_auth_route(): void
    {
        $user = User::factory()->create();

        foreach (['/api/v1/auth/logout', '/api/v1/auth/web-session'] as $uri) {
            foreach ([['mcp:use'], ['capture']] as $scopes) {
                $this->postJson($uri, [], $this->appHeaders($this->passportToken($user, $scopes)))
                    ->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
            }
            $this->post($uri)->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        }

        // a Passport token is no refresh token either
        $this->assertInvalidGrant($this->refresh($this->passportToken($user)), 401);
    }

    public function test_a_suspended_user_gets_403_and_cannot_refresh(): void
    {
        $user = User::factory()->create();
        $tokens = $this->exchange($this->code($user))->json();
        $user->forceFill(['suspended_at' => now()])->save();

        $this->postJson('/api/v1/auth/web-session', [], $this->appHeaders($tokens['access_token']))->assertStatus(403);
        $this->postJson('/api/v1/auth/logout', [], $this->appHeaders($tokens['access_token']))->assertStatus(403);
        $this->assertInvalidGrant($this->refresh($tokens['refresh_token']), 401);
    }

    public function test_exchange_and_refresh_are_throttled_per_ip(): void
    {
        foreach (range(1, 20) as $i) {
            $this->refresh('brr_nope')->assertStatus(401);
        }

        $this->refresh('brr_nope')->assertStatus(429)->assertJsonPath('code', 'rate_limited');
        $this->postJson('/api/v1/auth/exchange', [])->assertStatus(429);
    }
}
