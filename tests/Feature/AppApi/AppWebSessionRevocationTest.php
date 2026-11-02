<?php

namespace Tests\Feature\AppApi;

use App\Models\AppDevice;
use App\Models\User;
use App\Support\AppApi\AppAuthCodes;
use App\Support\AppApi\AppTokens;
use App\Support\AppApi\AppWebSessions;
use App\Support\SignOutEverywhere;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\TestCase;

/**
 * A web session an app's webview opened ends with its app device (BRAI-377): `/app/session` binds the session to
 * the device, EndRevokedAppDeviceSessions logs it out once the device is revoked or gone. Other sessions stay.
 */
class AppWebSessionRevocationTest extends TestCase
{
    use ActsAsAppClient;
    use RefreshDatabase;

    private const DESKTOP_UA = 'Mozilla/5.0 (Macintosh) AppleWebKit/605.1.15 (KHTML, like Gecko) BrainitiveDesktop/0.4.2';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** The webview opens a fresh web-session link of the device. */
    private function webviewSignIn(User $user, AppDevice $device): void
    {
        $code = AppAuthCodes::issue(AppAuthCodes::WEB_SESSION, ['user_id' => $user->id, 'device_id' => $device->id]);
        $this->withHeader('User-Agent', self::DESKTOP_UA)->get('/app/session/'.$code)->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_revoking_the_device_in_profile_logs_its_webview_session_out(): void
    {
        $user = User::factory()->create();
        $device = $this->appDevice($user);
        $this->webviewSignIn($user, $device);
        $this->get('/profile')->assertOk();
        $remember = $user->fresh()->remember_token;

        // "Sign out" in Profile → Desktop & mobile apps (here from that same session)
        $this->delete("/profile/app-devices/{$device->id}")->assertRedirect();
        $this->assertSame('user', $device->fresh()->revoked_reason);

        $this->get('/profile')->assertRedirect(route('login'))->assertSessionHas('status');
        $this->assertGuest('web');
        $this->assertNotSame($remember, $user->fresh()->remember_token, 'the webview’s remember-me cookie is dead too');
        $this->assertFalse(session()->has(AppWebSessions::SESSION_KEY));
    }

    public function test_json_requests_of_that_session_get_401(): void
    {
        $user = User::factory()->create();
        $device = $this->appDevice($user);
        $this->webviewSignIn($user, $device);

        AppTokens::revoke($device, 'user');

        $this->getJson('/profile')->assertUnauthorized();
        $this->assertGuest('web');
    }

    public function test_other_sessions_of_the_user_stay(): void
    {
        $user = User::factory()->create();
        $revoked = $this->appDevice($user);
        $active = $this->appDevice($user, ['name' => 'Other Mac']);
        AppTokens::revoke($revoked, 'user');

        // a session of another, still signed-in device
        $this->actingAs($user)->withSession([AppWebSessions::SESSION_KEY => $active->id])->get('/profile')->assertOk();
        $this->assertAuthenticatedAs($user, 'web');
        $this->flushSession();

        // an ordinary browser session (no device)
        $this->actingAs($user)->get('/profile')->assertOk();
        $this->assertAuthenticatedAs($user, 'web');

        // only the revoked device's session goes
        $this->actingAs($user)->withSession([AppWebSessions::SESSION_KEY => $revoked->id])->get('/profile')->assertRedirect(route('login'));
        $this->assertGuest('web');
    }

    public function test_a_device_of_another_user_or_a_deleted_one_ends_the_session(): void
    {
        $user = User::factory()->create();
        $foreign = $this->appDevice(User::factory()->create());

        $this->actingAs($user)->withSession([AppWebSessions::SESSION_KEY => $foreign->id])->get('/profile')->assertRedirect(route('login'));
        $this->assertGuest('web');

        $device = $this->appDevice($user);
        $this->webviewSignIn($user, $device);
        $this->get('/profile')->assertOk();

        // deleted without AppTokens::revoke: the cached answer holds for at most a minute (one query a minute)
        DB::table('app_devices')->where('id', $device->id)->delete();
        DB::enableQueryLog();
        $this->get('/profile')->assertOk();
        $this->assertSame([], array_filter(DB::getQueryLog(), fn (array $q) => str_starts_with($q['query'], 'select "user_id" from "app_devices"')), 'cached: no device check');
        DB::disableQueryLog();

        $this->travel(61)->seconds();
        $this->get('/profile')->assertRedirect(route('login'));
        $this->assertGuest('web');
    }

    public function test_sign_out_everywhere_revokes_the_apps_and_ends_their_webview_sessions(): void
    {
        $user = User::factory()->create();
        $device = $this->appDevice($user);
        $this->webviewSignIn($user, $device);
        $bystander = $this->appDevice(User::factory()->create());

        SignOutEverywhere::run($user, appReason: 'signed_out_everywhere');

        $this->assertSame('signed_out_everywhere', $device->fresh()->revoked_reason);
        $this->assertNull($bystander->fresh()->revoked_at);
        $this->get('/profile')->assertRedirect(route('login'));
        $this->assertGuest('web');
    }

    // Codex review, finding 1: the webview's remember-me cookie must not bring a revoked device's session back.

    /** The recaller cookie a remembered login hands out (`id|token|password hash`, as SessionGuard writes it). */
    private function recallerFor(User $user): array
    {
        if (! $user->remember_token) {
            $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
        }

        return [Auth::guard('web')->getRecallerName() => $user->id.'|'.$user->remember_token.'|'.$user->getAuthPassword()];
    }

    /** A brand-new cookie jar / session: what a webview has once its session expired or was lost. */
    private function loseTheSession(): void
    {
        $this->flushSession();
        Auth::forgetGuards();
        $this->app['session']->driver()->flush();
        $this->defaultCookies = [];
    }

    public function test_the_webview_session_is_not_remembered_and_an_old_recaller_cookie_is_expired(): void
    {
        $user = User::factory()->create();
        $device = $this->appDevice($user);
        $code = AppAuthCodes::issue(AppAuthCodes::WEB_SESSION, ['user_id' => $user->id, 'device_id' => $device->id]);

        $response = $this->withHeader('User-Agent', self::DESKTOP_UA)
            ->withCookies($this->recallerFor($user))
            ->get('/app/session/'.$code)
            ->assertRedirect(route('dashboard', absolute: false));

        $name = Auth::guard('web')->getRecallerName();
        $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === $name);
        $this->assertNotNull($cookie, 'the response expires the recaller cookie');
        $this->assertTrue($cookie->isCleared(), 'no remember-me cookie for an app webview');
    }

    public function test_a_device_revoked_before_its_session_expired_cannot_come_back_with_a_recaller(): void
    {
        $user = User::factory()->create();
        $device = $this->appDevice($user);
        $this->webviewSignIn($user, $device);
        $recaller = $this->recallerFor($user->fresh());

        AppTokens::revoke($device, 'user');
        $this->loseTheSession();

        $this->withHeader('User-Agent', self::DESKTOP_UA)->withCookies($recaller)
            ->get('/profile')->assertRedirect(route('login'));
        $this->assertGuest('web');
    }

    public function test_a_device_revoked_after_its_session_expired_cannot_come_back_with_a_recaller(): void
    {
        $user = User::factory()->create();
        $device = $this->appDevice($user);
        $this->webviewSignIn($user, $device);
        $recaller = $this->recallerFor($user->fresh());

        $this->loseTheSession();
        AppTokens::revoke($device, 'user');

        $this->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) BrainitiveMobile/1.0.0')
            ->withCookies($recaller)
            ->get('/profile')->assertRedirect(route('login'));
        $this->assertGuest('web');
        $this->assertNotNull($user->fresh()->remember_token);
    }

    public function test_a_normal_browsers_remember_me_still_works(): void
    {
        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => 'on'])->assertRedirect();
        $this->assertAuthenticatedAs($user, 'web');
        $recaller = $this->recallerFor($user->fresh());

        $this->loseTheSession();

        $this->withHeader('User-Agent', 'Mozilla/5.0 (Macintosh) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15')
            ->withCookies($recaller)
            ->get('/profile')->assertOk();
        $this->assertAuthenticatedAs($user, 'web');
    }
}
