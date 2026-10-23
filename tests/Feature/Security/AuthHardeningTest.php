<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Support\AccountLoginThrottle;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Hardening from the 2026-10-23 audit: reset links ignore the request host, login limits hold per
 * account whatever IP is claimed, and the unused widget page that serialized whole models is gone.
 */
class AuthHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_reset_links_use_app_url_not_the_request_host(): void
    {
        Notification::fake();
        config(['app.url' => 'https://app.example.test']);
        $user = User::factory()->create();

        $this->withHeaders(['Host' => 'evil.test', 'X-Forwarded-Host' => 'evil.test'])
            ->post('http://evil.test/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;

            return str_starts_with($url, 'https://app.example.test/reset-password/') && ! str_contains($url, 'evil.test');
        });
    }

    public function test_failed_logins_lock_the_account_whatever_ip_they_claim(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 20; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.9.'.intdiv($i, 250).'.'.($i % 250 + 1)])
                ->post('/login', ['email' => $user->email, 'password' => 'wrong']);
        }

        // right password, fresh IP: still locked for this account
        $this->withServerVariables(['REMOTE_ADDR' => '10.200.0.1'])
            ->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_cli_token_login_has_the_same_account_lock(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 20; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.8.0.'.($i + 1)])
                ->postJson('/api/cli/login', ['email' => $user->email, 'password' => 'wrong'])->assertStatus(422);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.8.1.1'])
            ->postJson('/api/cli/login', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(429)->assertJsonMissing(['token']);
    }

    public function test_a_password_reset_lifts_the_account_lock(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < 20; $i++) {
            AccountLoginThrottle::hit($user->email);
        }
        $this->assertTrue(AccountLoginThrottle::tooManyAttempts($user->email));

        $token = Password::createToken($user);
        $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'])
            ->assertSessionHasNoErrors();

        $this->assertFalse(AccountLoginThrottle::tooManyAttempts($user->email));
        $this->post('/login', ['email' => $user->email, 'password' => 'new-password-123']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_unused_widget_page_is_gone(): void
    {
        $this->get('/widget/helpdesks/'.fake()->uuid())->assertNotFound();
    }
}
