<?php

namespace Tests\Feature\AppApi;

use App\Models\AppDevice;
use App\Models\User;
use App\Support\AppApi\AppAuthCodes;
use App\Support\AppApi\AppTokens;
use App\Support\AppApi\AppWebSessions;
use App\Support\SignOutEverywhere;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
}
