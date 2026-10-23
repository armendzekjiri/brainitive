<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_verification_screen_can_be_rendered(): void
    {
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->get('/verify-email');

        $response->assertStatus(200);
    }

    public function test_email_can_be_verified_with_the_signed_link(): void
    {
        $user = User::factory()->unverified()->create();
        Event::fake();

        $response = $this->actingAs($user)->get($this->signedPath($user));

        Event::assertDispatched(Verified::class);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $response->assertRedirect(route('dashboard', absolute: false).'?verified=1');
    }

    public function test_the_link_from_the_email_works_and_points_to_app_url(): void
    {
        config(['app.url' => 'https://app.example.test']);
        $user = User::factory()->unverified()->create();

        $url = (new class extends VerifyEmailNotification
        {
            public function url(mixed $notifiable): string
            {
                return $this->verificationUrl($notifiable);
            }
        })->url($user);

        $this->assertStringStartsWith('https://app.example.test/verify-email/'.$user->id.'/', $url);
        $this->assertStringContainsString('signature=', $url);

        // the signature covers path + query only: it validates on whatever host serves the request
        $this->get(substr($url, strlen('https://app.example.test')))->assertRedirect(route('login'));
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_a_guest_opening_the_link_is_verified_but_not_signed_in(): void
    {
        $user = User::factory()->unverified()->create();

        $this->get($this->signedPath($user))->assertRedirect(route('login'));

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->assertGuest();
    }

    public function test_unsigned_expired_or_tampered_links_are_rejected(): void
    {
        $user = User::factory()->create(); // already verified, e.g. an admin
        $hash = sha1($user->email);

        $this->get("/verify-email/{$user->id}/{$hash}")->assertForbidden();
        $this->get("/verify-email/{$user->id}/{$hash}?expires=".now()->addHour()->timestamp)->assertForbidden();
        $this->get($this->signedPath($user, now()->subMinute()))->assertForbidden();

        $other = User::factory()->unverified()->create();
        $this->get(str_replace("/verify-email/{$user->id}/", "/verify-email/{$other->id}/", $this->signedPath($user)))->assertForbidden();

        $this->assertGuest();
        $this->assertFalse($other->fresh()->hasVerifiedEmail());
    }

    public function test_email_is_not_verified_with_invalid_hash(): void
    {
        $user = User::factory()->unverified()->create();

        $path = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1('wrong-email')], absolute: false);
        $this->get($path)->assertRedirect(route('login'));

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->assertGuest();
    }

    private function signedPath(User $user, $expires = null): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            $expires ?? now()->addHour(),
            ['id' => $user->id, 'hash' => sha1($user->email)],
            absolute: false,
        );
    }
}
