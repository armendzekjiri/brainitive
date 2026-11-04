<?php

namespace Tests\Feature\AppApi;

use App\Jobs\SendMobilePush;
use App\Models\AppDevice;
use App\Models\User;
use App\Support\AppApi\AppTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\TestCase;

/** App API v1 devices (BRAI-385): list, update the calling device, revoke. */
class DevicesTest extends TestCase
{
    use ActsAsAppClient;
    use ForgetsGuardsBetweenRequests;
    use RefreshDatabase;

    public function test_lists_the_users_active_devices_and_marks_the_calling_one(): void
    {
        $user = User::factory()->create();
        $mac = $this->appDevice($user, ['name' => 'Work Mac', 'os_version' => '15.1']);
        $token = $this->appToken($user, $mac);
        $phone = $this->appDevice($user, ['client' => 'mobile', 'platform' => 'ios', 'name' => 'iPhone', 'last_used_at' => now()->subDay()]);
        $gone = $this->appDevice($user, ['name' => 'Old PC']);
        AppTokens::revoke($gone, 'user');
        $this->appDevice(User::factory()->create(), ['name' => 'Someone else']);

        $response = $this->getJson('/api/v1/devices', $this->appHeaders($token))->assertOk();

        $response->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $mac->id) // just used by this request
            ->assertJsonPath('data.0.current', true)
            ->assertJsonPath('data.0.name', 'Work Mac')
            ->assertJsonPath('data.0.os_version', '15.1')
            ->assertJsonPath('data.1.id', $phone->id)
            ->assertJsonPath('data.1.current', false)
            ->assertJsonPath('data.1.client', 'mobile')
            ->assertJsonPath('data.1.platform', 'ios');

        $this->assertSame(
            ['id', 'client', 'platform', 'name', 'app_version', 'os_version', 'has_push_token', 'current', 'last_used_at', 'created_at'],
            array_keys($response->json('data.0')),
        );
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $response->json('data.0.created_at'));
        $this->assertStringNotContainsString('hash', $response->getContent());
    }

    public function test_the_calling_device_updates_itself_and_the_push_token_is_stored_encrypted(): void
    {
        $user = User::factory()->create();
        $device = $this->appDevice($user);
        $token = $this->appToken($user, $device);

        $response = $this->patchJson('/api/v1/devices/current', [
            'name' => 'Armend’s MacBook',
            'app_version' => '0.2.0',
            'os_version' => '15.2',
            'push_token' => 'apns-secret-token',
        ], $this->appHeaders($token))->assertOk();

        $response->assertJsonPath('data.id', $device->id)
            ->assertJsonPath('data.name', 'Armend’s MacBook')
            ->assertJsonPath('data.app_version', '0.2.0')
            ->assertJsonPath('data.has_push_token', true)
            ->assertJsonPath('data.current', true)
            ->assertJsonMissingPath('data.push_token');
        $this->assertStringNotContainsString('apns-secret-token', $response->getContent());

        $raw = DB::table('app_devices')->where('id', $device->id)->value('push_token');
        $this->assertNotSame('apns-secret-token', $raw);
        $this->assertSame('apns-secret-token', $device->fresh()->push_token);

        // the list never shows it either
        $this->assertStringNotContainsString('apns-secret-token', $this->getJson('/api/v1/devices', $this->appHeaders($token))->getContent());

        // only what's sent changes; null removes the push token
        $this->patchJson('/api/v1/devices/current', ['push_token' => null], $this->appHeaders($token))
            ->assertOk()->assertJsonPath('data.has_push_token', false)->assertJsonPath('data.name', 'Armend’s MacBook');
        $this->assertNull($device->fresh()->push_token);
    }

    public function test_setting_a_push_token_takes_it_off_the_users_other_devices(): void
    {
        $user = User::factory()->create();
        $old = $this->appDevice($user, ['client' => 'mobile', 'platform' => 'ios', 'push_token' => 'ExponentPushToken[same-phone]']);
        $tablet = $this->appDevice($user, ['client' => 'mobile', 'platform' => 'ios', 'push_token' => 'ExponentPushToken[tablet]']);
        $someoneElse = $this->appDevice(User::factory()->create(), ['client' => 'mobile', 'platform' => 'ios', 'push_token' => 'ExponentPushToken[same-phone]']);
        $new = $this->appDevice($user, ['client' => 'mobile', 'platform' => 'ios']); // the app signed in again on that phone
        $token = $this->appToken($user, $new);

        $this->patchJson('/api/v1/devices/current', ['push_token' => 'ExponentPushToken[same-phone]'], $this->appHeaders($token))
            ->assertOk()->assertJsonPath('data.has_push_token', true);

        $this->assertSame('ExponentPushToken[same-phone]', $new->fresh()->push_token);
        $this->assertNull($old->fresh()->push_token);
        $this->assertSame('ExponentPushToken[tablet]', $tablet->fresh()->push_token);
        $this->assertSame('ExponentPushToken[same-phone]', $someoneElse->fresh()->push_token); // another account: untouched
    }

    public function test_update_validates(): void
    {
        $user = User::factory()->create();
        $token = $this->appToken($user);

        $this->patchJson('/api/v1/devices/current', ['name' => '', 'app_version' => str_repeat('1', 40)], $this->appHeaders($token))
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['name', 'app_version']);
    }

    public function test_revoking_another_device_signs_it_out(): void
    {
        $user = User::factory()->create();
        $token = $this->appToken($user);
        $other = $this->appDevice($user, ['name' => 'Laptop']);
        $otherToken = $this->appToken($user, $other);

        $this->deleteJson("/api/v1/devices/{$other->id}", [], $this->appHeaders($token))->assertNoContent();

        $other->refresh();
        $this->assertTrue($other->isRevoked());
        $this->assertSame('user', $other->revoked_reason);
        $this->assertNull($other->access_token_hash);
        $this->getJson('/api/v1/devices', $this->appHeaders($otherToken))->assertStatus(401);
        // the caller stays signed in
        $this->getJson('/api/v1/devices', $this->appHeaders($token))->assertOk()->assertJsonCount(1, 'data');
        // gone from the list, and a second revoke is a 404
        $this->deleteJson("/api/v1/devices/{$other->id}", [], $this->appHeaders($token))->assertNotFound();
    }

    public function test_revoking_the_calling_device_is_a_sign_out(): void
    {
        $user = User::factory()->create();
        $device = $this->appDevice($user);
        $token = $this->appToken($user, $device);

        $this->deleteJson("/api/v1/devices/{$device->id}", [], $this->appHeaders($token))->assertNoContent();

        $this->assertSame('sign_out', $device->fresh()->revoked_reason);
        $this->getJson('/api/v1/devices', $this->appHeaders($token))->assertStatus(401);
    }

    public function test_another_users_device_is_a_404_and_stays_signed_in(): void
    {
        $user = User::factory()->create();
        $token = $this->appToken($user);
        $stranger = User::factory()->create();
        $theirs = $this->appDevice($stranger);
        $theirToken = $this->appToken($stranger, $theirs);

        $this->deleteJson("/api/v1/devices/{$theirs->id}", [], $this->appHeaders($token))
            ->assertNotFound()
            ->assertExactJson(['message' => 'Not found.', 'code' => 'not_found']);
        $this->deleteJson('/api/v1/devices/999999', [], $this->appHeaders($token))->assertNotFound();

        $this->assertFalse($theirs->fresh()->isRevoked());
        $this->getJson('/api/v1/devices', $this->appHeaders($theirToken))->assertOk();
    }

    public function test_passport_tokens_and_no_token_get_401_on_every_device_route(): void
    {
        $user = User::factory()->create();
        $device = $this->appDevice($user);
        $passport = $this->passportToken($user);

        foreach ([[], $this->appHeaders($passport)] as $headers) {
            $this->getJson('/api/v1/devices', $headers)->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
            $this->patchJson('/api/v1/devices/current', ['name' => 'x'], $headers)->assertStatus(401);
            $this->deleteJson("/api/v1/devices/{$device->id}", [], $headers)->assertStatus(401);
        }

        $this->assertFalse($device->fresh()->isRevoked());
        $this->assertSame('Test Mac', $device->fresh()->name);
    }

    public function test_test_push_queues_a_push_to_the_calling_device_only(): void
    {
        Queue::fake();
        config(['app-api.push.enabled' => true]);
        $user = User::factory()->create();
        $phone = $this->appDevice($user, ['client' => 'mobile', 'platform' => 'ios', 'push_token' => 'ExponentPushToken[phone-secret]']);
        $this->appDevice($user, ['client' => 'mobile', 'platform' => 'android', 'push_token' => 'ExponentPushToken[other-secret]']);
        $token = $this->appToken($user, $phone);

        $this->postJson('/api/v1/devices/current/test-push', [], $this->appHeaders($token))
            ->assertStatus(202)
            ->assertExactJson(['data' => ['status' => 'queued']]);

        Queue::assertPushed(SendMobilePush::class, 1);
        Queue::assertPushed(SendMobilePush::class, fn (SendMobilePush $job) => $job->deviceIds === [$phone->id]
            && $job->userId === $user->id
            && $job->title === 'Test notification'
            && $job->url === null && $job->notificationId === null
            && ! str_contains(serialize($job), 'secret'));
    }

    public function test_test_push_without_a_push_token_or_with_push_off_is_a_409(): void
    {
        Queue::fake();
        config(['app-api.push.enabled' => true]);
        $user = User::factory()->create();
        $phone = $this->appDevice($user, ['client' => 'mobile', 'platform' => 'ios']);
        $phoneToken = $this->appToken($user, $phone);
        $other = User::factory()->create(); // 3 a minute per user
        $mac = $this->appDevice($other, ['push_token' => 'ExponentPushToken[mac]']); // desktop: never pushed to
        $macToken = $this->appToken($other, $mac);

        $this->postJson('/api/v1/devices/current/test-push', [], $this->appHeaders($phoneToken))
            ->assertStatus(409)->assertJsonPath('code', 'no_push_token');
        $this->postJson('/api/v1/devices/current/test-push', [], $this->appHeaders($macToken))
            ->assertStatus(409)->assertJsonPath('code', 'no_push_token');
        $phone->forceFill(['push_token' => 'not-an-expo-token'])->save();
        $this->postJson('/api/v1/devices/current/test-push', [], $this->appHeaders($phoneToken))
            ->assertStatus(409)->assertJsonPath('code', 'no_push_token');
        // a sign-in that can't be refreshed any more is never pushed to (AppDevice::eligibleForPush)
        $phone->forceFill(['push_token' => 'ExponentPushToken[phone]', 'refresh_expires_at' => now()->subMinute()])->save();
        $this->postJson('/api/v1/devices/current/test-push', [], $this->appHeaders($phoneToken))
            ->assertStatus(409)->assertJsonPath('code', 'no_push_token');

        config(['app-api.push.enabled' => false]);
        $this->postJson('/api/v1/devices/current/test-push', [], $this->appHeaders($macToken))
            ->assertStatus(409)->assertJsonPath('code', 'push_disabled');

        Queue::assertNothingPushed();
    }

    public function test_test_push_is_limited_to_three_a_minute_and_needs_an_app_token(): void
    {
        Queue::fake();
        config(['app-api.push.enabled' => true]);
        $user = User::factory()->create();
        $phone = $this->appDevice($user, ['client' => 'mobile', 'platform' => 'ios', 'push_token' => 'ExponentPushToken[phone]']);
        $token = $this->appToken($user, $phone);

        $this->postJson('/api/v1/devices/current/test-push')->assertStatus(401);
        $this->postJson('/api/v1/devices/current/test-push', [], $this->appHeaders($this->passportToken($user)))->assertStatus(401);

        foreach (range(1, 3) as $i) {
            $this->postJson('/api/v1/devices/current/test-push', [], $this->appHeaders($token))->assertStatus(202);
        }
        $this->postJson('/api/v1/devices/current/test-push', [], $this->appHeaders($token))->assertStatus(429);
        Queue::assertPushed(SendMobilePush::class, 3);
    }

    public function test_the_model_never_serialises_secrets(): void
    {
        $device = $this->appDevice(User::factory()->create(), ['push_token' => 'secret']);
        AppTokens::issue($device);

        $array = AppDevice::find($device->id)->toArray();
        foreach (['push_token', 'access_token_hash', 'refresh_token_hash', 'previous_refresh_hash'] as $key) {
            $this->assertArrayNotHasKey($key, $array);
        }
    }
}
