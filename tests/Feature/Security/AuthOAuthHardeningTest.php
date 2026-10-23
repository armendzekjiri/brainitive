<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Middleware\GuardOAuthFlows;
use App\Models\Helpdesk;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketCreatedNotification;
use App\Support\OutboundUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Passport\ClientRepository;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\Feature\Concerns\UsesPassportKeys;
use Tests\TestCase;

/**
 * Auth / OAuth hardening (security audit 2026-10-23, batch 4) + the batch 2 review follow-ups.
 */
class AuthOAuthHardeningTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;
    use UsesPassportKeys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        $this->usePassportKeys();
        Notification::fake();
    }

    // ── account pre-registration ────────────────────────────────────────────────

    public function test_social_login_takes_over_an_unverified_preregistered_account_and_locks_the_squatter_out(): void
    {
        $squatter = User::factory()->unverified()->create(['email' => 'victim@corp.example', 'password' => Hash::make('attacker-pass')]);

        $this->fakeGoogle('victim@corp.example', verified: true);
        $this->get('/login/google/callback')->assertRedirect();
        $this->assertAuthenticatedAs($squatter->fresh());
        Auth::logout();

        $this->assertNotNull($squatter->fresh()->email_verified_at);
        $this->assertFalse(Auth::attempt(['email' => 'victim@corp.example', 'password' => 'attacker-pass']), 'the squatter password no longer works');
    }

    public function test_google_emails_that_google_has_not_verified_are_refused(): void
    {
        $existing = User::factory()->create(['email' => 'someone@corp.example']);

        $this->fakeGoogle('someone@corp.example', verified: false);
        $this->get('/login/google/callback')->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNull($existing->fresh()->google_id);
    }

    // ── OAuth / tokens ──────────────────────────────────────────────────────────

    public function test_oauth_client_registration_only_accepts_known_redirect_hosts(): void
    {
        $register = fn (string $uri) => $this->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [$uri]]);

        $register('https://evil.example/cb')->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');
        $register('https://claude.ai.evil.example/cb')->assertStatus(400);
        $register('http://claude.ai/cb')->assertStatus(400);
        $register('https://claude.ai/api/mcp/auth_callback')->assertSuccessful();
        $register('https://abcdefghijklmnop.chromiumapp.org/')->assertSuccessful();
        $register('http://localhost:33418/callback')->assertSuccessful();

        $this->assertFalse(GuardOAuthFlows::allowedRedirect('https://user@claude.ai/cb'));
        $this->assertTrue(GuardOAuthFlows::allowedRedirect('claude://callback'));
    }

    public function test_oauth_client_registration_is_throttled(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/oauth/register', ['client_name' => 'App '.$i, 'redirect_uris' => ['http://localhost:9000/cb']]);
        }
        $this->postJson('/oauth/register', ['client_name' => 'One more', 'redirect_uris' => ['http://localhost:9000/cb']])->assertStatus(429);
    }

    public function test_unverified_accounts_cannot_connect_apps_or_get_cli_tokens(): void
    {
        $user = User::factory()->unverified()->create(['password' => Hash::make('secret-pass')]);

        $this->actingAs($user)->get('/oauth/authorize?client_id=x&response_type=code')->assertRedirect(route('verification.notice'));
        Auth::logout();

        $this->postJson('/api/cli/login', ['email' => $user->email, 'password' => 'secret-pass'])->assertForbidden()->assertJsonMissing(['token']);
    }

    public function test_a_password_change_revokes_tokens_and_apps_can_be_disconnected(): void
    {
        app(ClientRepository::class)->createPersonalAccessGrantClient('Brainitive CLI', 'users');
        $user = User::factory()->create(['password' => Hash::make('old-password-1')]);
        $token = fn () => $this->postJson('/api/cli/login', ['email' => $user->email, 'password' => 'old-password-1'])->assertOk()->json('token');

        $first = $token();
        Auth::forgetGuards();
        $this->assertSame(1, $user->tokens()->where('revoked', false)->count());

        // connected apps list it, and disconnecting revokes it
        $apps = $this->actingAs($user)->get(route('profile.edit'))->viewData('page')['props']['connectedApps'];
        $this->assertCount(1, $apps);
        $this->actingAs($user)->delete(route('profile.connected-apps.destroy', $apps[0]['client_id']))->assertRedirect();
        $this->assertSame(0, $user->tokens()->where('revoked', false)->count());

        $token();
        $this->actingAs($user)->put(route('password.update'), [
            'current_password' => 'old-password-1', 'password' => 'new-password-2', 'password_confirmation' => 'new-password-2',
        ])->assertSessionHasNoErrors();
        $this->assertSame(0, $user->tokens()->where('revoked', false)->count(), 'a new password ends all app / CLI access');
        $this->assertNotEmpty($first);
    }

    // ── outbound requests ───────────────────────────────────────────────────────

    public function test_push_subscriptions_only_accept_real_push_services(): void
    {
        $keys = ['p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM', 'auth' => 'tBHItJI5svbpez7KI4CCXg'];

        $this->actingAs($this->viewer)->postJson(route('push-subscriptions.store'), ['endpoint' => 'http://169.254.169.254/latest/meta-data/', 'keys' => $keys])->assertJsonValidationErrors('endpoint');
        $this->actingAs($this->viewer)->postJson(route('push-subscriptions.store'), ['endpoint' => 'https://fcm.googleapis.com.evil.example/x', 'keys' => $keys])->assertJsonValidationErrors('endpoint');
        $this->actingAs($this->viewer)->postJson(route('push-subscriptions.store'), ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc', 'keys' => $keys, 'contentEncoding' => 'bogus'])->assertJsonValidationErrors('contentEncoding');
        $this->actingAs($this->viewer)->postJson(route('push-subscriptions.store'), ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc', 'keys' => $keys])->assertOk();

        $this->assertTrue(PushSubscriptionController::isPushService('https://wns2-par02p.notify.windows.com/w/?token=x'));
    }

    public function test_outbound_urls_refuse_ipv6_ranges_that_embed_ipv4(): void
    {
        foreach (['http://[64:ff9b::a9fe:a9fe]/', 'http://[64:ff9b:1::a9fe:a9fe]/', 'http://[2002:a9fe:a9fe::1]/', 'http://[2001:0:4136:e378::1]/'] as $url) {
            $this->assertNull(OutboundUrl::options($url), $url);
        }
    }

    public function test_the_saved_ai_key_is_never_sent_to_a_different_endpoint(): void
    {
        Http::fake();
        $admin = User::factory()->create();
        $this->workspace->addUser($admin, 'admin');
        $this->workspace->update(['settings' => ['ai_endpoint' => 'https://api.openai.com', 'ai_api_key' => Crypt::encryptString('sk-real')]]);

        $this->actingAs($admin)->postJson(route('ai.models', $this->workspace->slug), ['ai_endpoint' => 'https://8.8.8.8/attacker'])->assertStatus(422);
        Http::assertNothingSent();
    }

    // ── headers / enumeration ───────────────────────────────────────────────────

    public function test_app_pages_cannot_be_framed_by_other_sites_but_embeds_can(): void
    {
        $this->actingAs($this->viewer)->get(route('workspaces.show', $this->workspace->slug))
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'self'");

        $helpdesk = Helpdesk::create(['project_id' => $this->project->id, 'name' => 'Support', 'slug' => 'support', 'created_by' => $this->editor->id]);
        $this->get(route('helpdesks.widget.embed', $helpdesk->uuid))->assertHeader('Content-Security-Policy', 'frame-ancestors *')->assertHeaderMissing('X-Frame-Options');
    }

    public function test_password_reset_does_not_reveal_whether_an_account_exists(): void
    {
        $known = $this->post('/forgot-password', ['email' => $this->viewer->email]);
        $unknown = $this->post('/forgot-password', ['email' => 'nobody@nowhere.example']);

        $unknown->assertSessionHasNoErrors();
        $this->assertSame($known->getSession()->get('status'), $unknown->getSession()->get('status'));
    }

    // ── batch 2 review follow-ups ───────────────────────────────────────────────

    public function test_mention_paths_reject_control_characters_and_double_slashes(): void
    {
        foreach (["/\t/evil.com", "/\n/evil.com", "/\r/evil.com", '/ /evil.com', url('//evil.com/x'), 'https://'.parse_url(config('app.url'), PHP_URL_HOST).'//evil.com/x'] as $url) {
            $this->assertNull(NotificationController::appPath($url), json_encode($url));
        }
        $this->assertSame('/workspaces/a?b=1', NotificationController::appPath('/workspaces/a?b=1'));
    }

    public function test_ticket_watchers_follow_the_same_rules_as_board_watchers(): void
    {
        $helpdesk = Helpdesk::create(['project_id' => $this->project->id, 'name' => 'Support', 'slug' => 'support', 'created_by' => $this->editor->id]);
        $this->postJson(route('helpdesks.tickets.store-public', $helpdesk->uuid), ['subject' => 'Q', 'description' => 'x', 'contact_email' => 'c@example.com'])->assertOk();
        $ticket = Ticket::latest('id')->firstOrFail();
        $route = route('tickets.watch', [$this->workspace->slug, $this->project->slug, $helpdesk->slug, $ticket->id]);

        $this->actingAs($this->viewer)->post($route, ['user_id' => $this->viewer->id, 'watch' => true])->assertSessionHasNoErrors();
        $this->actingAs($this->viewer)->post($route, ['user_id' => $this->editor->id, 'watch' => true])->assertForbidden();
        $this->actingAs($this->editor)->post($route, ['user_id' => $this->outsider->id, 'watch' => true])->assertSessionHasErrors('user_id');
    }

    public function test_public_ticket_confirmations_are_capped_per_address(): void
    {
        $helpdesk = Helpdesk::create(['project_id' => $this->project->id, 'name' => 'Support', 'slug' => 'support', 'created_by' => $this->editor->id]);
        for ($i = 0; $i < 6; $i++) {
            $this->postJson(route('helpdesks.tickets.store-public', $helpdesk->uuid), ['subject' => 'Q'.$i, 'description' => 'x', 'contact_email' => 'victim@example.com'])->assertOk();
        }

        $contact = Ticket::with('contact')->firstOrFail()->contact;
        $this->assertCount(3, Notification::sent($contact, TicketCreatedNotification::class));
    }

    private function fakeGoogle(string $email, bool $verified): void
    {
        $socialite = (new SocialiteUser)->setRaw(['email_verified' => $verified])->map(['id' => 'g-'.md5($email), 'email' => $email, 'name' => 'Victim']);
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($socialite);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }
}
