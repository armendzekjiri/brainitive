<?php

namespace Tests\Feature\ApiKeys;

use App\Models\ApiKey;
use App\Models\User;
use App\Support\ApiKeys\ApiKeys;
use App\Support\SignOutEverywhere;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\ApiKeys\Concerns\UsesApiKeys;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * Developer API (v1.52.0): a personal API key authenticates on /api/v1 next to the app device tokens —
 * only its hash is stored, revoked / expired / switched-off keys are 401 with their own code, suspended users
 * are refused, last use is recorded (throttled), and signing out everywhere revokes keys.
 */
class ApiKeyAuthTest extends TestCase
{
    use ActsAsAppClient;
    use ForgetsGuardsBetweenRequests;
    use InteractsWithBoards;
    use RefreshDatabase;
    use UsesApiKeys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
    }

    public function test_a_key_authenticates_and_only_its_hash_is_stored(): void
    {
        ['key' => $key, 'secret' => $secret] = $this->makeApiKey($this->editor, ['issues' => 'read']);

        $this->assertMatchesRegularExpression('/^brai_[A-Za-z0-9]{43}$/', $secret);
        $this->assertSame(hash('sha256', $secret), $key->token_hash);
        $this->assertSame(substr($secret, 0, 13), $key->prefix);
        $this->assertDatabaseMissing('api_keys', ['token_hash' => $secret]);
        $this->assertStringNotContainsString($secret, json_encode(ApiKey::query()->first()->getAttributes()));

        $this->getJson('/api/v1/me', $this->keyHeaders($secret))->assertOk()
            ->assertJsonPath('data.id', $this->editor->id)
            ->assertJsonPath('data.device', null);
        $this->getJson("/api/v1/boards/{$this->board->id}", $this->keyHeaders($secret))->assertOk();

        $key->refresh();
        $this->assertNotNull($key->last_used_at);
        $this->assertSame('127.0.0.1', $key->last_used_ip);
    }

    public function test_last_use_is_written_at_most_once_a_minute(): void
    {
        ['key' => $key, 'secret' => $secret] = $this->makeApiKey($this->editor, ['issues' => 'read']);
        $this->getJson('/api/v1/me', $this->keyHeaders($secret))->assertOk();
        $first = $key->refresh()->last_used_at;

        $this->travel(30)->seconds();
        $this->getJson('/api/v1/me', $this->keyHeaders($secret))->assertOk();
        $this->assertEquals($first, $key->refresh()->last_used_at);

        $this->travel(2)->minutes();
        $this->getJson('/api/v1/me', $this->keyHeaders($secret))->assertOk();
        $this->assertTrue($key->refresh()->last_used_at->gt($first));
    }

    public function test_unknown_revoked_and_expired_keys_are_401_with_their_code(): void
    {
        $this->getJson('/api/v1/me', $this->keyHeaders('brai_'.str_repeat('x', 43)))
            ->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');

        ['key' => $revoked, 'secret' => $revokedSecret] = $this->makeApiKey($this->editor, ['issues' => 'read']);
        ApiKeys::revoke($revoked);
        $this->getJson('/api/v1/me', $this->keyHeaders($revokedSecret))
            ->assertUnauthorized()->assertJsonPath('code', 'api_key_revoked');

        ['secret' => $expiring] = $this->makeApiKey($this->editor, ['issues' => 'read'], days: 30);
        $this->getJson('/api/v1/me', $this->keyHeaders($expiring))->assertOk();
        $this->travel(31)->days();
        $this->getJson('/api/v1/me', $this->keyHeaders($expiring))
            ->assertUnauthorized()->assertJsonPath('code', 'api_key_expired')->assertJsonPath('message', 'This API key has expired.');
    }

    public function test_the_instance_switch_turns_every_key_off(): void
    {
        $secret = $this->apiKey($this->editor, ['issues' => 'read']);
        config(['api-keys.enabled' => false]);

        $this->getJson('/api/v1/me', $this->keyHeaders($secret))
            ->assertUnauthorized()->assertJsonPath('code', 'api_keys_disabled');
        // the apps keep working
        $this->getJson('/api/v1/me', $this->appHeaders($this->appToken($this->editor)))->assertOk();

        config(['api-keys.enabled' => true]);
        $this->getJson('/api/v1/me', $this->keyHeaders($secret))->assertOk();
    }

    public function test_a_suspended_user_is_refused(): void
    {
        $secret = $this->apiKey($this->editor, ['issues' => 'read']);
        $this->editor->forceFill(['suspended_at' => now()])->save();

        $this->getJson('/api/v1/me', $this->keyHeaders($secret))->assertForbidden();
    }

    public function test_signing_out_everywhere_revokes_every_key(): void
    {
        ['key' => $key, 'secret' => $secret] = $this->makeApiKey($this->editor, ['issues' => 'read']);
        SignOutEverywhere::run($this->editor->fresh(), null, 'password_changed');

        $this->assertNotNull($key->refresh()->revoked_at);
        $this->getJson('/api/v1/me', $this->keyHeaders($secret))->assertUnauthorized()->assertJsonPath('code', 'api_key_revoked');
    }

    public function test_device_tokens_are_unaffected_and_keys_never_work_on_passport_routes(): void
    {
        $token = $this->appToken($this->editor);
        $this->getJson('/api/v1/devices', $this->appHeaders($token))->assertOk();
        $this->getJson("/api/v1/boards/{$this->board->id}", $this->appHeaders($token))->assertOk();

        $secret = $this->apiKey($this->editor, $this->everything());
        $this->usePassportKeys();
        $this->getJson('/api/cli/me', $this->keyHeaders($secret))->assertUnauthorized();
        $this->postJson('/mcp', [], $this->keyHeaders($secret))->assertUnauthorized();
    }

    public function test_the_key_never_appears_in_the_users_json(): void
    {
        $this->makeApiKey($this->editor);
        $this->assertArrayNotHasKey('token_hash', ApiKey::query()->first()->toArray());
        $this->assertInstanceOf(User::class, ApiKey::query()->first()->user);
    }
}
