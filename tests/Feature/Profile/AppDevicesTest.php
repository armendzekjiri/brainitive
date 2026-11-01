<?php

namespace Tests\Feature\Profile;

use App\Models\User;
use App\Support\AppApi\AppTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\TestCase;

/** Profile → "Desktop & mobile apps" (BRAI-385): the user's app installs, sign one out from the web. */
class AppDevicesTest extends TestCase
{
    use ActsAsAppClient;
    use RefreshDatabase;

    public function test_the_profile_page_lists_the_users_active_app_devices(): void
    {
        $user = User::factory()->create();
        $mac = $this->appDevice($user, ['name' => 'Work Mac', 'push_token' => 'secret-push', 'last_used_at' => now()]);
        AppTokens::issue($mac);
        $revoked = $this->appDevice($user, ['name' => 'Old PC']);
        AppTokens::revoke($revoked, 'user');
        $this->appDevice(User::factory()->create(), ['name' => 'Someone else']);

        $response = $this->actingAs($user)->get(route('profile.edit'))->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Profile/Edit')
            ->has('appDevices', 1)
            ->where('appDevices.0.id', $mac->id)
            ->where('appDevices.0.name', 'Work Mac')
            ->where('appDevices.0.platform', 'macos')
            ->where('appDevices.0.current', false)
            ->where('appDevices.0.has_push_token', true)
            ->missing('appDevices.0.push_token'));
        $this->assertStringNotContainsString('secret-push', $response->getContent());
        $this->assertStringNotContainsString((string) $mac->fresh()->access_token_hash, $response->getContent());
    }

    public function test_sign_out_revokes_the_device_and_its_token(): void
    {
        $user = User::factory()->create();
        $device = $this->appDevice($user);
        $token = $this->appToken($user, $device);

        $this->actingAs($user)->from(route('profile.edit'))
            ->delete(route('profile.app-devices.destroy', $device->id))
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('success');

        $device->refresh();
        $this->assertTrue($device->isRevoked());
        $this->assertSame('user', $device->revoked_reason);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/devices', $this->appHeaders($token))->assertStatus(401);
    }

    public function test_another_users_device_is_a_404(): void
    {
        $user = User::factory()->create();
        $theirs = $this->appDevice(User::factory()->create());

        $this->actingAs($user)->delete(route('profile.app-devices.destroy', $theirs->id))->assertNotFound();
        $this->assertFalse($theirs->fresh()->isRevoked());
    }

    public function test_guests_are_sent_to_login_and_the_e2e_seed_route_does_not_exist(): void
    {
        $device = $this->appDevice(User::factory()->create());

        $this->delete(route('profile.app-devices.destroy', $device->id))->assertRedirect(route('login'));
        $this->assertFalse($device->fresh()->isRevoked());

        // registered only when APP_ENV=e2e
        $this->assertFalse(Route::has('profile.app-devices.e2e-create'));
    }
}
