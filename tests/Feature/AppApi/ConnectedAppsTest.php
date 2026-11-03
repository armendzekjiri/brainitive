<?php

namespace Tests\Feature\AppApi;

use App\Jobs\ExportPersonalData;
use App\Models\DataExport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Passport\Token;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\TestCase;

/** App API v1 connected apps (devices + MCP / CLI / extension) and "Download my data" (BRAI-394). */
class ConnectedAppsTest extends TestCase
{
    use ActsAsAppClient;
    use ForgetsGuardsBetweenRequests;
    use RefreshDatabase;

    public function test_lists_devices_and_passport_clients_without_secrets(): void
    {
        $user = User::factory()->create();
        $mac = $this->appDevice($user, ['name' => 'Work Mac']);
        $token = $this->appToken($user, $mac);
        $phone = $this->appDevice($user, ['client' => 'mobile', 'platform' => 'ios', 'name' => 'iPhone', 'last_used_at' => now()->subDay()]);
        $mcp = $this->passportToken($user, ['mcp:use']);
        $user->createToken('brainitive-cli'); // `brainitive login`
        $this->appDevice(User::factory()->create(), ['name' => 'Someone else']);

        $response = $this->getJson('/api/v1/me/connected-apps', $this->appHeaders($token))->assertOk();

        $apps = collect($response->json('data'));
        $this->assertSame(["device:{$mac->id}", "device:{$phone->id}"], $apps->take(2)->pluck('id')->all());
        $this->assertSame(['desktop', 'mobile'], $apps->take(2)->pluck('kind')->all());
        $this->assertTrue($apps[0]['current']);
        $this->assertFalse($apps[1]['current']);
        $this->assertSame('ios', $apps[1]['platform']);

        // both Passport tokens share the personal access client here: one row, the CLI wins the kind
        $oauth = $apps->where('kind', '!=', 'desktop')->where('kind', '!=', 'mobile')->values();
        $this->assertCount(1, $oauth);
        $this->assertSame('cli', $oauth[0]['kind']);
        $this->assertSame('Brainitive CLI', $oauth[0]['name']);
        $this->assertStringStartsWith('oauth:', $oauth[0]['id']);
        $this->assertContains('mcp:use', $oauth[0]['scopes']);
        $this->assertMatchesRegularExpression('/Z$/', $oauth[0]['expires_at']);

        $this->assertSame(['id', 'kind', 'name', 'platform', 'app_version', 'scopes', 'current', 'connected_at', 'last_used_at', 'expires_at'], array_keys($apps[0]));
        $this->assertStringNotContainsString($mcp, $response->getContent());
        $this->assertStringNotContainsString('hash', $response->getContent());
        $this->assertStringNotContainsString('Someone else', $response->getContent());
    }

    public function test_revokes_a_passport_client_and_a_device(): void
    {
        $user = User::factory()->create();
        $token = $this->appToken($user);
        $other = $this->appDevice($user, ['name' => 'Old PC']);
        $otherToken = $this->appToken($user, $other);
        $mcp = $this->passportToken($user, ['mcp:use']);
        $clientId = Token::where('user_id', $user->id)->value('client_id');

        $this->deleteJson("/api/v1/me/connected-apps/oauth:{$clientId}", [], $this->appHeaders($token))->assertNoContent();
        $this->assertTrue(Token::where('user_id', $user->id)->where('client_id', $clientId)->get()->every->revoked);
        $this->getJson('/api/cli/me', ['Authorization' => 'Bearer '.$mcp])->assertUnauthorized();

        $this->deleteJson("/api/v1/me/connected-apps/device:{$other->id}", [], $this->appHeaders($token))->assertNoContent();
        $this->assertSame('user', $other->fresh()->revoked_reason);
        $this->getJson('/api/v1/me', $this->appHeaders($otherToken))->assertUnauthorized();

        // gone now, and the list no longer has them
        $this->deleteJson("/api/v1/me/connected-apps/oauth:{$clientId}", [], $this->appHeaders($token))->assertNotFound();
        $ids = array_column($this->getJson('/api/v1/me/connected-apps', $this->appHeaders($token))->json('data'), 'id');
        $this->assertNotContains("device:{$other->id}", $ids);
        $this->assertNotContains("oauth:{$clientId}", $ids);
    }

    public function test_other_peoples_apps_are_404(): void
    {
        $user = User::factory()->create();
        $token = $this->appToken($user);
        $stranger = User::factory()->create();
        $theirDevice = $this->appDevice($stranger);
        $this->passportToken($stranger, ['mcp:use']);
        $theirClient = Token::where('user_id', $stranger->id)->value('client_id');

        $this->deleteJson("/api/v1/me/connected-apps/device:{$theirDevice->id}", [], $this->appHeaders($token))->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->deleteJson("/api/v1/me/connected-apps/oauth:{$theirClient}", [], $this->appHeaders($token))->assertNotFound();
        foreach (['something-else', 'oauth:not-a-uuid', 'device:abc', 'device:0'] as $bad) {
            $this->deleteJson("/api/v1/me/connected-apps/{$bad}", [], $this->appHeaders($token))->assertNotFound();
        }
        $this->assertNull($theirDevice->fresh()->revoked_at);
        $this->assertFalse((bool) Token::where('user_id', $stranger->id)->value('revoked'));
    }

    public function test_requests_a_data_export_and_reads_its_state(): void
    {
        Bus::fake([ExportPersonalData::class]);
        $user = User::factory()->create();
        $token = $this->appToken($user);

        $this->getJson('/api/v1/me/data-export', $this->appHeaders($token))->assertOk()
            ->assertExactJson(['data' => ['latest' => null, 'exports' => [], 'available_in' => 0]]);

        $response = $this->postJson('/api/v1/me/data-export', [], $this->appHeaders($token))->assertStatus(202);
        $response->assertJsonPath('data.latest.status', 'queued')->assertJsonPath('data.latest.download_url', null);
        $this->assertGreaterThan(0, $response->json('data.available_in'));
        Bus::assertDispatched(ExportPersonalData::class);

        // one per hour, as on the web
        $this->postJson('/api/v1/me/data-export', [], $this->appHeaders($token))
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors('export');

        // ready: a signed link for the owner's web session, and the same link as a relative path
        $export = DataExport::where('user_id', $user->id)->sole();
        $export->forceFill(['status' => DataExport::READY, 'file_path' => $export->directory().'/data.zip', 'file_size' => 1234, 'finished_at' => now(), 'expires_at' => now()->addDay()])->save();

        $ready = $this->getJson('/api/v1/me/data-export', $this->appHeaders($token))->assertOk();
        $ready->assertJsonPath('data.latest.id', $export->uuid)->assertJsonPath('data.latest.status', 'ready')->assertJsonPath('data.latest.file_size', 1234);
        $this->assertStringContainsString('signature=', $ready->json('data.latest.download_url'));
        $this->assertStringStartsWith('/data-exports/'.$export->uuid.'/download?', $ready->json('data.latest.download_path'));
        $this->assertStringNotContainsString('data.zip', $ready->getContent());

        // another person's exports never show
        $this->getJson('/api/v1/me/data-export', $this->appHeaders($this->appToken(User::factory()->create())))->assertOk()->assertJsonPath('data.latest', null);
        RateLimiter::clear(DataExport::limiterKey($user));
    }

    public function test_tokens_are_checked(): void
    {
        $user = User::factory()->create();
        $passport = $this->passportToken($user);

        foreach ([
            ['GET', '/api/v1/me/connected-apps'],
            ['DELETE', '/api/v1/me/connected-apps/device:1'],
            ['GET', '/api/v1/me/data-export'],
            ['POST', '/api/v1/me/data-export'],
        ] as [$method, $uri]) {
            $this->json($method, $uri)->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
            $this->json($method, $uri, [], $this->appHeaders($passport))->assertUnauthorized();
        }
        $this->assertSame(0, DataExport::count());
    }
}
