<?php

namespace Tests\Feature\Auth;

use App\Jobs\RevokeAppleToken;
use App\Models\InstanceAdmin;
use App\Models\User;
use App\Support\Auth\AppleLogin;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * App Store guideline 5.1.1(v): deleting an account that signed in with Apple revokes the stored
 * refresh token at Apple (queued, encrypted payload, retried, never blocks the deletion, never logs
 * the token).
 */
class AppleRevokeOnDeleteTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'r1.the-apple-refresh-token';

    private static string $p8;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        if (! isset(self::$p8)) {
            openssl_pkey_export(openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']), $pem);
            self::$p8 = $pem;
        }

        Http::preventStrayRequests();
        config(['services.apple' => [
            'client_id' => 'com.example.brainitive.web',
            'team_id' => 'TEAM123456',
            'key_id' => 'KEY1234567',
            'private_key' => self::$p8,
            'redirect' => 'https://app.test/login/apple/callback',
        ]]);
    }

    private function appleUser(?string $token = self::TOKEN): User
    {
        $user = User::factory()->create(['apple_id' => '001234.abc.1234']);
        $user->forceFill(['apple_refresh_token' => $token])->save();

        return $user;
    }

    // ── deletion dispatches ─────────────────────────────────────────────────────────────────

    public function test_deleting_your_own_account_revokes_the_apple_token(): void
    {
        Queue::fake();
        $user = $this->appleUser();

        $this->actingAs($user)->delete('/profile', ['password' => 'password'])->assertRedirect('/');

        $this->assertNull($user->fresh());
        Queue::assertPushed(RevokeAppleToken::class, fn (RevokeAppleToken $job) => $job->userId === $user->id && $job->refreshToken === self::TOKEN);
        $this->assertContains(ShouldBeEncrypted::class, class_implements(RevokeAppleToken::class));
    }

    public function test_an_admin_deleting_a_user_revokes_the_apple_token(): void
    {
        Queue::fake();
        $admin = User::factory()->create();
        InstanceAdmin::create(['user_id' => $admin->id]);
        $user = $this->appleUser();

        $this->actingAs($admin)
            ->delete(route('admin.users.destroy', $user), ['confirm_email' => $user->email])
            ->assertRedirect(route('admin.users.index'));

        $this->assertNull($user->fresh());
        Queue::assertPushed(RevokeAppleToken::class, 1);
    }

    public function test_no_stored_token_means_nothing_to_revoke(): void
    {
        Queue::fake();
        $user = $this->appleUser(null);

        $this->actingAs($user)->delete('/profile', ['password' => 'password'])->assertRedirect('/');

        $this->assertNull($user->fresh());
        Queue::assertNotPushed(RevokeAppleToken::class);
    }

    public function test_a_token_that_cannot_be_decrypted_does_not_block_the_deletion(): void
    {
        Queue::fake();
        $user = $this->appleUser(null);
        DB::table('users')->where('id', $user->id)->update(['apple_refresh_token' => 'not-a-ciphertext']);

        $this->actingAs($user)->delete('/profile', ['password' => 'password'])->assertRedirect('/');

        $this->assertNull($user->fresh());
        Queue::assertNotPushed(RevokeAppleToken::class);
    }

    public function test_apple_being_down_does_not_block_the_deletion(): void
    {
        // a web request on a sync queue (php-fpm / artisan serve): the revoke runs after the response
        $handled = false;
        $calledAfterResponse = null;
        Event::listen(RequestHandled::class, function () use (&$handled) {
            $handled = true;
        });
        Http::fake([AppleLogin::REVOKE_URL => function () use (&$handled, &$calledAfterResponse) {
            $calledAfterResponse = $handled;

            return Http::response('', 503);
        }]);
        $user = $this->appleUser();
        $this->withoutMiddleware(ValidateCsrfToken::class); // only skipped for unit tests in the console
        $console = new \ReflectionProperty($this->app, 'isRunningInConsole');
        $console->setValue($this->app, false);

        try {
            // the job gets its one attempt once the response is out and gives up quietly
            $this->actingAs($user)->delete('/profile', ['password' => 'password'])->assertRedirect('/');
        } finally {
            $console->setValue($this->app, true);
        }

        $this->assertNull($user->fresh());
        Http::assertSentCount(1);
        $this->assertTrue($calledAfterResponse, 'the deletion request waited for Apple');
    }

    public function test_on_a_sync_queue_a_web_request_revokes_after_the_response(): void
    {
        Bus::fake([RevokeAppleToken::class]);
        $user = $this->appleUser();
        $this->withoutMiddleware(ValidateCsrfToken::class); // only skipped for unit tests in the console
        $console = new \ReflectionProperty($this->app, 'isRunningInConsole');
        $console->setValue($this->app, false);

        try {
            $this->actingAs($user)->delete('/profile', ['password' => 'password'])->assertRedirect('/');
        } finally {
            $console->setValue($this->app, true);
        }

        $this->assertNull($user->fresh());
        Bus::assertDispatchedAfterResponse(RevokeAppleToken::class);
        Bus::assertNotDispatchedSync(RevokeAppleToken::class);
    }

    // ── the job ─────────────────────────────────────────────────────────────────────────────

    public function test_the_job_posts_the_token_with_a_valid_client_secret(): void
    {
        Http::fake([AppleLogin::REVOKE_URL => Http::response('', 200)]);

        (new RevokeAppleToken(7, self::TOKEN))->handle(app(AppleLogin::class));

        Http::assertSent(function (HttpRequest $request) {
            $public = openssl_pkey_get_details(openssl_pkey_get_private(self::$p8))['key'];
            $claims = (array) JWT::decode($request['client_secret'], new Key($public, 'ES256'));

            return $request->url() === 'https://appleid.apple.com/auth/revoke'
                && $request->method() === 'POST'
                && $request->isForm()
                && $request['client_id'] === 'com.example.brainitive.web'
                && $request['token'] === self::TOKEN
                && $request['token_type_hint'] === 'refresh_token'
                && $claims['iss'] === 'TEAM123456' && $claims['sub'] === 'com.example.brainitive.web'
                && $claims['aud'] === 'https://appleid.apple.com';
        });
    }

    public function test_a_refused_token_is_logged_without_the_token_and_dropped(): void
    {
        Http::fake([AppleLogin::REVOKE_URL => Http::response(['error' => 'invalid_grant'], 400)]);
        Log::spy();

        $job = new RevokeAppleToken(7, self::TOKEN);
        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldReceive('attempts')->andReturn(1);
        $queueJob->shouldNotReceive('release');
        $job->setJob($queueJob);
        $job->handle(app(AppleLogin::class));

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $message === 'Apple revoke refused'
            && $context['error'] === 'invalid_grant'
            && ! str_contains(json_encode($context), 'apple-refresh-token'));
    }

    public function test_server_errors_and_rate_limits_are_retried_on_a_real_queue(): void
    {
        Http::fake([AppleLogin::REVOKE_URL => Http::sequence()->push('', 500)->push('', 429)]);

        foreach ([[1, 60], [2, 300]] as [$attempt, $delay]) {
            $job = new RevokeAppleToken(7, self::TOKEN);
            $queueJob = Mockery::mock(Job::class);
            $queueJob->shouldReceive('attempts')->andReturn($attempt);
            $queueJob->shouldReceive('isDeletedOrReleased')->andReturn(false);
            $queueJob->shouldReceive('release')->once()->with($delay);
            $job->setJob($queueJob);
            $job->handle(app(AppleLogin::class));
        }
    }

    public function test_the_last_attempt_gives_up_with_a_log_line_without_the_token(): void
    {
        Http::fake([AppleLogin::REVOKE_URL => Http::response('', 502)]);
        Log::spy();

        $job = new RevokeAppleToken(7, self::TOKEN);
        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldReceive('attempts')->andReturn(5);
        $queueJob->shouldNotReceive('release');
        $job->setJob($queueJob);
        $job->handle(app(AppleLogin::class));

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $message === 'Apple revoke failed'
            && $context['attempts'] === 5
            && ! str_contains(json_encode($context), 'apple-refresh-token'));
    }

    public function test_an_unconfigured_apple_sign_in_sends_nothing(): void
    {
        Http::fake();
        config(['services.apple.private_key' => null]);

        (new RevokeAppleToken(7, self::TOKEN))->handle(app(AppleLogin::class));

        Http::assertNothingSent();
    }
}
