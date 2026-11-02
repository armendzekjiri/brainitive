<?php

namespace Tests\Feature\AppApi;

use App\Models\InstanceAdmin;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\TestCase;

/**
 * Account events sign out every desktop / mobile app of the user (BRAI-428): suspension, a
 * password change and a password reset. Other users' devices stay signed in.
 */
class AppRevocationTest extends TestCase
{
    use ActsAsAppClient;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_suspending_a_user_signs_out_their_apps(): void
    {
        $admin = User::factory()->create();
        InstanceAdmin::create(['user_id' => $admin->id]);
        $user = User::factory()->create();
        $devices = [$this->appDevice($user), $this->appDevice($user, ['client' => 'mobile', 'platform' => 'ios'])];
        $bystander = $this->appDevice($admin);

        $this->actingAs($admin)->post(route('admin.users.suspend', $user))->assertSessionHas('success');

        foreach ($devices as $device) {
            $this->assertSame('suspended', $device->fresh()->revoked_reason);
            $this->assertNull($device->fresh()->access_token_hash);
        }
        $this->assertNull($bystander->fresh()->revoked_at);
    }

    public function test_changing_the_password_signs_out_the_apps(): void
    {
        $user = User::factory()->create();
        $device = $this->appDevice($user);
        $other = $this->appDevice(User::factory()->create());

        $this->actingAs($user)->from('/profile')->put('/password', [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertSessionHasNoErrors();

        $this->assertSame('password_changed', $device->fresh()->revoked_reason);
        $this->assertNull($other->fresh()->revoked_at);
    }

    public function test_a_wrong_current_password_signs_nothing_out(): void
    {
        $user = User::factory()->create();
        $device = $this->appDevice($user);

        $this->actingAs($user)->from('/profile')->put('/password', [
            'current_password' => 'wrong',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertSessionHasErrors('current_password');

        $this->assertNull($device->fresh()->revoked_at);
    }

    public function test_resetting_the_password_signs_out_the_apps(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $device = $this->appDevice($user);
        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])->assertSessionHasNoErrors();

            return true;
        });

        $this->assertSame('password_changed', $device->fresh()->revoked_reason);
    }
}
