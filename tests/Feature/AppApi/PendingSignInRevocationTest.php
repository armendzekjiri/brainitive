<?php

namespace Tests\Feature\AppApi;

use App\Models\AppDevice;
use App\Models\User;
use App\Support\AppApi\AppAuthCodes;
use App\Support\SignOutEverywhere;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\TestCase;

/**
 * Codex review, finding 2: a sign-in the user approved before "sign out everywhere" (password change / reset,
 * suspension, account takeover) must not mint tokens afterwards — neither a consent-page code (PKCE exchange)
 * nor an approved TV device code. Both carry the user's `app_auth_generation` from the approval; SignOutEverywhere
 * bumps it, and AppDeviceSignIn compares it under the user row lock it already takes.
 */
class PendingSignInRevocationTest extends TestCase
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

    /** What /app/authorize's Continue hands the app (signed in as the user). */
    private function approvedCode(User $user): string
    {
        $state = Str::random(16);
        $location = $this->actingAs($user)->post('/app/authorize', [
            'client' => 'desktop',
            'code_challenge' => AppAuthCodes::challengeFor($this->verifier),
            'code_challenge_method' => 'S256',
            'state' => $state,
            'decision' => 'approve',
        ], ['X-Inertia' => 'true'])->assertStatus(409)->headers->get('X-Inertia-Location');
        parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);
        $this->assertSame($state, $query['state'] ?? null);
        Auth::forgetGuards();
        $this->flushSession();

        return (string) $query['code'];
    }

    private function exchange(string $code): TestResponse
    {
        Auth::forgetGuards();

        return $this->postJson('/api/v1/auth/exchange', [
            'code' => $code,
            'code_verifier' => $this->verifier,
            'device' => ['name' => 'Ada’s MacBook', 'platform' => 'macos', 'app_version' => '0.1.0'],
        ]);
    }

    /** A TV code the user approved at /app/device. */
    private function approvedDeviceCode(User $user): string
    {
        $flow = $this->postJson('/api/v1/auth/device', [
            'client' => 'tv',
            'device' => ['name' => 'Living room TV', 'platform' => 'android_tv', 'app_version' => '0.1.0'],
        ])->assertOk()->json();
        $this->actingAs($user)->post('/app/device', ['user_code' => $flow['user_code'], 'decision' => 'approve'])->assertOk();
        Auth::forgetGuards();
        $this->flushSession();

        return $flow['device_code'];
    }

    private function poll(string $deviceCode): TestResponse
    {
        Auth::forgetGuards();

        return $this->postJson('/api/v1/auth/device/token', ['device_code' => $deviceCode]);
    }

    public function test_an_auth_code_approved_before_sign_out_everywhere_is_dead(): void
    {
        $user = User::factory()->create();
        $code = $this->approvedCode($user);

        SignOutEverywhere::run($user);

        $this->exchange($code)->assertStatus(400)->assertJsonPath('code', 'invalid_grant');
        $this->assertSame(0, AppDevice::count());
    }

    public function test_an_approved_tv_code_is_dead_after_sign_out_everywhere(): void
    {
        $user = User::factory()->create();
        $deviceCode = $this->approvedDeviceCode($user);

        SignOutEverywhere::run($user);

        $this->poll($deviceCode)->assertStatus(400)->assertJsonPath('code', 'access_denied');
        $this->assertSame(0, AppDevice::count());
        // handed out once, also as a refusal
        $this->poll($deviceCode)->assertStatus(400)->assertJsonPath('code', 'invalid_grant');
    }

    public function test_a_password_reset_kills_pending_approvals(): void
    {
        $user = User::factory()->create();
        $code = $this->approvedCode($user);
        $deviceCode = $this->approvedDeviceCode($user);

        $this->post('/reset-password', [
            'token' => Password::createToken($user),
            'email' => $user->email,
            'password' => 'a-new-Password-123!',
            'password_confirmation' => 'a-new-Password-123!',
        ])->assertSessionHasNoErrors();

        $this->exchange($code)->assertStatus(400)->assertJsonPath('code', 'invalid_grant');
        $this->travel(10)->seconds();
        $this->poll($deviceCode)->assertStatus(400)->assertJsonPath('code', 'access_denied');
        $this->assertSame(0, AppDevice::count());
    }

    public function test_a_password_change_kills_pending_approvals(): void
    {
        $user = User::factory()->create();
        $code = $this->approvedCode($user);

        $this->actingAs($user)->put('/password', [
            'current_password' => 'password',
            'password' => 'a-new-Password-123!',
            'password_confirmation' => 'a-new-Password-123!',
        ])->assertSessionHasNoErrors();

        $this->exchange($code)->assertStatus(400)->assertJsonPath('code', 'invalid_grant');
        $this->assertSame(0, AppDevice::count());
    }

    public function test_approvals_after_the_sign_out_and_of_other_users_still_work_and_win_once(): void
    {
        $user = User::factory()->create();
        $bystander = User::factory()->create();
        $theirs = $this->approvedCode($bystander);

        SignOutEverywhere::run($user);

        // a fresh approval after the sign-out works — once: a second (concurrent) exchange of it loses
        $code = $this->approvedCode($user);
        $this->exchange($code)->assertOk();
        $this->exchange($code)->assertStatus(400)->assertJsonPath('code', 'invalid_grant');

        $this->poll($this->approvedDeviceCode($user))->assertOk();
        $this->exchange($theirs)->assertOk();
        $this->assertSame(2, AppDevice::where('user_id', $user->id)->count());
    }
}
