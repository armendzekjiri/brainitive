<?php

namespace Tests\Feature\AppApi;

use App\Http\Middleware\EnsureUserIsNotSuspended;
use App\Models\User;
use App\Support\AppApi\AppAuthCodes;
use App\Support\AppApi\AppTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\TestCase;

/**
 * The App API v1 foundation (BRAI-380 / BRAI-428): the `app` guard, isolation from Passport in
 * both directions, the error shape and the single-use codes.
 */
class AppApiFoundationTest extends TestCase
{
    use ActsAsAppClient;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A probe route with the real stack, so the foundation is tested without any endpoint.
        Route::middleware(['app-api', 'auth:app', EnsureUserIsNotSuspended::class])->prefix('api/v1')
            ->get('_probe', fn (Request $request) => [
                'user' => $request->user()->id,
                'device' => AppTokens::currentDevice($request)?->id,
            ]);
        Route::middleware('app-api')->prefix('api/v1')
            ->post('_probe/validate', fn (Request $request) => $request->validate(['name' => 'required']));
    }

    public function test_an_app_token_signs_in_and_carries_its_device(): void
    {
        $user = User::factory()->create();
        $device = $this->appDevice($user);
        $token = $this->appToken($user, $device);

        $this->getJson('/api/v1/_probe', $this->appHeaders($token))
            ->assertOk()
            ->assertExactJson(['user' => $user->id, 'device' => $device->id]);

        $this->assertNotNull($device->fresh()->last_used_at);
    }

    public function test_no_token_answers_401_in_the_error_shape(): void
    {
        $this->get('/api/v1/_probe')
            ->assertStatus(401)
            ->assertExactJson(['message' => 'Unauthenticated.', 'code' => 'unauthenticated']);
    }

    public function test_a_passport_token_is_rejected_on_the_app_api(): void
    {
        $user = User::factory()->create();

        foreach ([['mcp:use'], ['capture'], ['mcp:use', 'capture']] as $scopes) {
            $this->getJson('/api/v1/_probe', $this->appHeaders($this->passportToken($user, $scopes)))
                ->assertStatus(401)
                ->assertJsonPath('code', 'unauthenticated');
        }
    }

    public function test_an_app_token_is_rejected_by_mcp_and_the_cli_api(): void
    {
        $this->usePassportKeys();
        $user = User::factory()->create();
        $token = $this->appToken($user);

        $this->getJson('/api/cli/me', $this->appHeaders($token))->assertStatus(401);
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], $this->appHeaders($token))
            ->assertStatus(401);
    }

    public function test_expired_revoked_and_refresh_tokens_do_not_sign_in(): void
    {
        $user = User::factory()->create();

        $expired = $this->appDevice($user);
        $expiredToken = $this->appToken($user, $expired);
        $expired->forceFill(['access_expires_at' => now()->subSecond()])->save();
        $this->getJson('/api/v1/_probe', $this->appHeaders($expiredToken))->assertStatus(401);

        $revoked = $this->appDevice($user);
        $revokedToken = $this->appToken($user, $revoked);
        AppTokens::revoke($revoked, 'user');
        $this->getJson('/api/v1/_probe', $this->appHeaders($revokedToken))->assertStatus(401);

        // the refresh token is not an access token
        $pair = AppTokens::issue($this->appDevice($user));
        $this->getJson('/api/v1/_probe', $this->appHeaders($pair['refresh_token']))->assertStatus(401);
    }

    public function test_issuing_a_new_pair_kills_the_old_one_and_remembers_its_refresh_token(): void
    {
        $user = User::factory()->create();
        $device = $this->appDevice($user);
        $first = AppTokens::issue($device);
        $second = AppTokens::issue($device);

        $this->getJson('/api/v1/_probe', $this->appHeaders($first['access_token']))->assertStatus(401);
        $this->getJson('/api/v1/_probe', $this->appHeaders($second['access_token']))->assertOk();
        $this->assertSame(AppTokens::hash($first['refresh_token']), $device->fresh()->previous_refresh_hash);
        $this->assertDatabaseMissing('app_devices', ['access_token_hash' => $second['access_token']]);
    }

    public function test_a_suspended_user_gets_403(): void
    {
        $user = User::factory()->create(['suspended_at' => now()]);

        $this->getJson('/api/v1/_probe', $this->appHeaders($this->appToken($user)))->assertStatus(403);
    }

    public function test_errors_share_one_shape(): void
    {
        $this->getJson('/api/v1/does-not-exist')
            ->assertStatus(404)
            ->assertExactJson(['message' => 'Not found.', 'code' => 'not_found']);

        // no Accept header from a native client: still JSON, never a redirect or an HTML page
        $this->post('/api/v1/_probe/validate')
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['message', 'code', 'errors' => ['name']]);

        $this->delete('/api/v1/_probe')
            ->assertStatus(405)
            ->assertJsonPath('code', 'method_not_allowed');
    }

    public function test_codes_work_once(): void
    {
        $code = AppAuthCodes::issue(AppAuthCodes::EXCHANGE, ['user_id' => 7]);

        $this->assertNull(AppAuthCodes::consume(AppAuthCodes::WEB_SESSION, $code));
        $this->assertSame(['user_id' => 7], AppAuthCodes::consume(AppAuthCodes::EXCHANGE, $code));
        $this->assertNull(AppAuthCodes::consume(AppAuthCodes::EXCHANGE, $code));
        $this->assertNull(AppAuthCodes::consume(AppAuthCodes::EXCHANGE, 'nope'));
    }

    public function test_pkce_challenge_matches_rfc_7636(): void
    {
        // RFC 7636 appendix B
        $this->assertSame(
            'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
            AppAuthCodes::challengeFor('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'),
        );
    }
}
