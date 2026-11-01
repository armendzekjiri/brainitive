<?php

namespace Tests\Feature\AppApi;

use App\Models\AppDevice;
use App\Models\User;
use App\Support\AppApi\AppAuthCodes;
use App\Support\AppApi\AppDeviceCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\TestCase;

/**
 * Device-code sign-in for the TV app (RFC 8628, BRAI-438): POST /api/v1/auth/device, the confirm page
 * /app/device, POST /api/v1/auth/device/token — happy path, every error, single use, brute force.
 */
class DeviceCodeSignInTest extends TestCase
{
    use ActsAsAppClient;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** @return array{device_code: string, user_code: string, verification_uri: string, verification_uri_complete: string, expires_in: int, interval: int} */
    private function start(array $overrides = []): array
    {
        return $this->postJson('/api/v1/auth/device', array_replace_recursive([
            'client' => 'tv',
            'device' => ['name' => 'Living room TV', 'platform' => 'android_tv', 'app_version' => '0.1.0', 'os_version' => '12'],
        ], $overrides))->assertOk()->json();
    }

    private function poll(string $deviceCode): TestResponse
    {
        Auth::forgetGuards();

        return $this->postJson('/api/v1/auth/device/token', ['device_code' => $deviceCode]);
    }

    private function decide(User $user, string $userCode, string $decision): TestResponse
    {
        return $this->actingAs($user)->post('/app/device', ['user_code' => $userCode, 'decision' => $decision]);
    }

    private function assertDeviceError(TestResponse $response, string $code): void
    {
        $response->assertStatus(400)->assertJsonPath('code', $code)->assertJsonStructure(['message', 'code']);
    }

    // ── the whole flow ──────────────────────────────────────────────────────────────────────

    public function test_a_tv_signs_in_once_the_user_approves_the_code(): void
    {
        $user = User::factory()->create();
        $flow = $this->start();

        $this->assertSame(64, strlen($flow['device_code']));
        $this->assertMatchesRegularExpression('/^[BCDFGHJKLMNPQRSTVWXZ]{4}-[BCDFGHJKLMNPQRSTVWXZ]{4}$/', $flow['user_code']);
        $this->assertSame(route('app.device'), $flow['verification_uri']);
        $this->assertSame(route('app.device', ['code' => $flow['user_code']]), $flow['verification_uri_complete']);
        $this->assertSame(600, $flow['expires_in']);
        $this->assertSame(5, $flow['interval']);

        $this->assertDeviceError($this->poll($flow['device_code']), 'authorization_pending');

        // the QR: the page shows the code and the device, and approves nothing by itself
        $this->actingAs($user)->get($flow['verification_uri_complete'])->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('AppAuth/Device')
                ->where('stage', 'confirm')
                ->where('code', $flow['user_code'])
                ->where('client.name', 'Brainitive TV')
                ->where('device.name', 'Living room TV')
                ->where('device.platform', 'Android TV')
                ->where('email', $user->email));
        $this->travel(6)->seconds();
        $this->assertDeviceError($this->poll($flow['device_code']), 'authorization_pending');

        // the click
        $this->decide($user, $flow['user_code'], 'approve')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('AppAuth/Device')->where('stage', 'approved'));

        $this->travel(6)->seconds();
        $tokens = $this->poll($flow['device_code'])->assertOk()
            ->assertJsonStructure(['token_type', 'access_token', 'expires_in', 'refresh_token', 'refresh_expires_in', 'device' => ['id', 'name', 'platform']])
            ->assertJsonPath('device.platform', 'android_tv')
            ->assertJsonPath('device.name', 'Living room TV')
            ->json();

        $device = AppDevice::findOrFail($tokens['device']['id']);
        $this->assertSame(['tv', 'android_tv', $user->id, '0.1.0', '12'], [$device->client, $device->platform, $device->user_id, $device->app_version, $device->os_version]);

        Auth::forgetGuards();
        $this->getJson('/api/v1/me', $this->appHeaders($tokens['access_token']))->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.device.client', 'tv');

        // refresh works as for every client
        Auth::forgetGuards();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $tokens['refresh_token']])->assertOk()->assertJsonPath('device.id', $device->id);

        // the device code works once
        $this->travel(6)->seconds();
        $this->assertDeviceError($this->poll($flow['device_code']), 'invalid_grant');
    }

    public function test_a_typed_code_ignores_case_spaces_and_dashes(): void
    {
        $user = User::factory()->create();
        $flow = $this->start();
        $typed = strtolower(str_replace('-', ' ', $flow['user_code']));

        $this->actingAs($user)->get('/app/device?code='.urlencode($typed))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('stage', 'confirm')->where('code', $flow['user_code']));
        $this->decide($user, $typed, 'approve')->assertInertia(fn (Assert $page) => $page->where('stage', 'approved'));

        $this->poll($flow['device_code'])->assertOk();
    }

    public function test_the_page_without_a_code_asks_for_one_and_guests_sign_in_first(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/app/device')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('AppAuth/Device')->where('stage', 'enter')->missing('error'));

        Auth::logout();
        $this->get('/app/device?code=BCDF-GHJK')->assertRedirect('/login');
    }

    // ── errors ──────────────────────────────────────────────────────────────────────────────

    public function test_polling_faster_than_the_interval_answers_slow_down_and_grows_the_interval(): void
    {
        $flow = $this->start();

        $this->assertDeviceError($this->poll($flow['device_code']), 'authorization_pending');
        $this->poll($flow['device_code'])->assertStatus(400)->assertJsonPath('code', 'slow_down')->assertJsonPath('interval', 10);

        // the old interval is no longer enough
        $this->travel(6)->seconds();
        $this->poll($flow['device_code'])->assertJsonPath('code', 'slow_down')->assertJsonPath('interval', 15);

        $this->travel(15)->seconds();
        $this->assertDeviceError($this->poll($flow['device_code']), 'authorization_pending');
    }

    public function test_a_denied_code_answers_access_denied(): void
    {
        $user = User::factory()->create();
        $flow = $this->start();

        $this->decide($user, $flow['user_code'], 'deny')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('stage', 'denied'));

        $this->assertDeviceError($this->poll($flow['device_code']), 'access_denied');
        $this->assertSame(0, AppDevice::count());
    }

    public function test_an_expired_code_answers_expired_token_and_no_longer_opens_on_the_page(): void
    {
        $user = User::factory()->create();
        $flow = $this->start();

        $this->travel(601)->seconds();

        $this->assertDeviceError($this->poll($flow['device_code']), 'expired_token');
        $this->actingAs($user)->get($flow['verification_uri_complete'])->assertStatus(422)
            ->assertInertia(fn (Assert $page) => $page->where('stage', 'enter')->has('error'));
        $this->decide($user, $flow['user_code'], 'approve')->assertStatus(422);
        $this->assertDeviceError($this->poll($flow['device_code']), 'expired_token');
    }

    public function test_an_unknown_or_malformed_device_code_is_an_invalid_grant(): void
    {
        $this->assertDeviceError($this->poll(str_repeat('a', 64)), 'invalid_grant');
        $this->assertDeviceError($this->poll('short'), 'invalid_grant');
        $this->postJson('/api/v1/auth/device/token', [])->assertUnprocessable()->assertJsonPath('code', 'validation_failed');
    }

    public function test_a_decided_code_cannot_be_decided_again_by_anyone(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        // approved by the owner → another user can't take it over, nor deny it
        $flow = $this->start();
        $this->decide($owner, $flow['user_code'], 'approve')->assertOk();
        $this->decide($other, $flow['user_code'], 'approve')->assertStatus(422)
            ->assertInertia(fn (Assert $page) => $page->where('stage', 'enter')->has('error'));
        $this->decide($other, $flow['user_code'], 'deny')->assertStatus(422);
        $this->actingAs($other)->get($flow['verification_uri_complete'])->assertStatus(422);
        $this->poll($flow['device_code'])->assertOk();
        $this->assertSame($owner->id, AppDevice::sole()->user_id);

        // denied → nobody can approve it afterwards
        $denied = $this->start();
        $this->decide($owner, $denied['user_code'], 'deny')->assertOk();
        $this->decide($other, $denied['user_code'], 'approve')->assertStatus(422);
        $this->assertDeviceError($this->poll($denied['device_code']), 'access_denied');
    }

    public function test_opening_the_qr_link_never_approves(): void
    {
        $user = User::factory()->create();
        $flow = $this->start();

        $this->actingAs($user)->get($flow['verification_uri_complete'])->assertOk();
        $this->actingAs($user)->get($flow['verification_uri_complete'])->assertOk();

        $this->assertDeviceError($this->poll($flow['device_code']), 'authorization_pending');
        $this->assertSame(0, AppDevice::count());
    }

    public function test_a_user_suspended_after_approving_gets_no_tokens(): void
    {
        $user = User::factory()->create();
        $flow = $this->start();
        $this->decide($user, $flow['user_code'], 'approve');

        $user->forceFill(['suspended_at' => now()])->save();

        $this->assertDeviceError($this->poll($flow['device_code']), 'access_denied');
        $this->assertSame(0, AppDevice::count());
    }

    public function test_only_device_code_clients_with_their_platform_can_start(): void
    {
        $device = ['name' => 'TV', 'platform' => 'android_tv', 'app_version' => '0.1.0'];

        $this->postJson('/api/v1/auth/device', ['client' => 'desktop', 'device' => $device])
            ->assertUnprocessable()->assertJsonValidationErrors('client');
        $this->postJson('/api/v1/auth/device', ['client' => 'tv', 'device' => ['platform' => 'macos'] + $device])
            ->assertUnprocessable()->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors('device.platform');
        $this->postJson('/api/v1/auth/device', ['client' => 'tv'])
            ->assertUnprocessable()->assertJsonValidationErrors(['device', 'device.name', 'device.platform', 'device.app_version']);
    }

    public function test_the_tv_client_cannot_use_the_browser_flow_and_desktop_cannot_sign_in_as_android_tv(): void
    {
        $user = User::factory()->create();
        $verifier = str_repeat('v', 64);

        $this->actingAs($user)->get('/app/authorize?'.http_build_query([
            'client' => 'tv', 'code_challenge' => AppAuthCodes::challengeFor($verifier), 'code_challenge_method' => 'S256', 'state' => 's',
        ]))->assertStatus(400);

        // a PKCE code can't be turned into a tv device …
        $code = AppAuthCodes::issue(AppAuthCodes::EXCHANGE, ['user_id' => $user->id, 'client' => 'tv', 'code_challenge' => AppAuthCodes::challengeFor($verifier)]);
        $this->postJson('/api/v1/auth/exchange', ['code' => $code, 'code_verifier' => $verifier, 'device' => ['name' => 'TV', 'platform' => 'android_tv', 'app_version' => '0.1.0']])
            ->assertStatus(400)->assertJsonPath('code', 'invalid_grant');

        // … and desktop can't claim the TV platform
        $code = AppAuthCodes::issue(AppAuthCodes::EXCHANGE, ['user_id' => $user->id, 'client' => 'desktop', 'code_challenge' => AppAuthCodes::challengeFor($verifier)]);
        $this->postJson('/api/v1/auth/exchange', ['code' => $code, 'code_verifier' => $verifier, 'device' => ['name' => 'TV', 'platform' => 'android_tv', 'app_version' => '0.1.0']])
            ->assertUnprocessable()->assertJsonValidationErrors('device.platform');
    }

    public function test_max_devices_applies_to_tv_sign_ins(): void
    {
        config(['app-api.max_devices' => 2]);
        $user = User::factory()->create();
        $oldest = $this->appDevice($user, ['last_used_at' => now()->subDays(3)]);
        $recent = $this->appDevice($user, ['last_used_at' => now()->subDay()]);

        $flow = $this->start();
        $this->decide($user, $flow['user_code'], 'approve');
        $this->poll($flow['device_code'])->assertOk();

        $this->assertSame('limit', $oldest->fresh()->revoked_reason);
        $this->assertNull($recent->fresh()->revoked_at);
        $this->assertSame(2, $user->appDevices()->active()->count());
    }

    public function test_tv_tokens_are_rejected_by_mcp_the_cli_api_and_the_extension_api(): void
    {
        $this->usePassportKeys();
        $user = User::factory()->create();
        $flow = $this->start();
        $this->decide($user, $flow['user_code'], 'approve');
        $token = $this->poll($flow['device_code'])->assertOk()->json('access_token');

        Auth::forgetGuards();
        $this->getJson('/api/cli/me', $this->appHeaders($token))->assertUnauthorized();
        Auth::forgetGuards();
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], $this->appHeaders($token))->assertUnauthorized();
        Auth::forgetGuards();
        $this->getJson('/api/ext/v1/me', $this->appHeaders($token))->assertUnauthorized();
    }

    public function test_too_many_wrong_codes_lock_the_page_for_that_user(): void
    {
        $user = User::factory()->create();
        $flow = $this->start();

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($user)->get('/app/device?code=BBBB-BBB'.chr(66 + ($i % 2)))->assertStatus(422);
        }

        // even the right code is refused now (no more guessing), but nothing was approved
        $this->actingAs($user)->get($flow['verification_uri_complete'])->assertStatus(429)
            ->assertInertia(fn (Assert $page) => $page->where('stage', 'enter')->where('error', fn ($error) => str_contains($error, 'Too many wrong codes')));
        $this->decide($user, $flow['user_code'], 'approve')->assertStatus(429);
        $this->assertDeviceError($this->poll($flow['device_code']), 'authorization_pending');

        // another user is not affected
        $this->actingAs(User::factory()->create())->get($flow['verification_uri_complete'])->assertOk();
    }

    public function test_user_codes_use_the_readable_alphabet_only(): void
    {
        $this->assertTrue(AppDeviceCodes::isWellFormed('bcdf-ghjk'));
        $this->assertFalse(AppDeviceCodes::isWellFormed('ABCD-EFGH')); // vowels never appear
        $this->assertFalse(AppDeviceCodes::isWellFormed('BCDF-GHJ0'));
        $this->assertSame('BCDF-GHJK', AppDeviceCodes::format('bcdfghjk'));
    }
}
