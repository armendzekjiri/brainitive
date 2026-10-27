<?php

namespace Tests\Feature\Portal;

use App\Models\InstanceSettings;
use App\Models\PortalIdentity;
use App\Models\PortalLoginToken;
use App\Models\User;
use App\Notifications\PortalLoginLinkNotification;
use App\Support\Portal\PortalAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** v1.42.0 foundation: guest sign-in for the feedback and client portals. */
class PortalAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        Route::middleware(['web', 'portal.identity'])->get('/feedback/test-only/probe', fn (Request $request) => 'hello '.$request->attributes->get('portalIdentity')->email);
    }

    private function requestLink(string $email = 'Jane@Example.com', string $returnTo = '/feedback/test-only/probe'): string
    {
        $this->post(route('guest.login.store'), ['email' => $email, 'name' => 'Jane', 'return_to' => $returnTo])
            ->assertRedirect(route('guest.login', ['return_to' => PortalAuth::safeReturnTo($returnTo)]))
            ->assertSessionHas('portal_link_sent', true);

        $token = null;
        Notification::assertSentTo(PortalIdentity::findByEmail($email), PortalLoginLinkNotification::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });

        return $token;
    }

    public function test_a_guest_signs_in_with_the_emailed_link_and_lands_where_they_were(): void
    {
        $this->get('/feedback/test-only/probe')->assertRedirect(route('guest.login', ['return_to' => '/feedback/test-only/probe']));

        $token = $this->requestLink();
        $identity = PortalIdentity::findByEmail('jane@example.com');
        $this->assertSame('jane@example.com', $identity->email);
        $this->assertSame('Jane', $identity->name);
        $this->assertFalse($identity->isVerified());

        // only the hash is stored
        $this->assertDatabaseMissing('portal_login_tokens', ['token_hash' => $token]);
        $this->assertDatabaseHas('portal_login_tokens', ['token_hash' => hash('sha256', $token)]);

        // opening the link does not spend it (mail scanners)
        $this->get(route('guest.login.show', $token))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Portal/Login')->where('mode', 'confirm')->where('email', 'jane@example.com'));
        $this->get(route('guest.login.show', $token))->assertInertia(fn ($page) => $page->where('mode', 'confirm'));

        $this->post(route('guest.login.consume', $token))->assertRedirect('/feedback/test-only/probe');
        $this->assertTrue($identity->fresh()->isVerified());
        $this->get('/feedback/test-only/probe')->assertOk()->assertSee('hello jane@example.com');
    }

    public function test_a_link_works_once_and_not_after_it_expired(): void
    {
        $token = $this->requestLink();
        $this->post(route('guest.login.consume', $token))->assertRedirect('/feedback/test-only/probe');

        $this->post(route('guest.logout'))->assertRedirect('/');
        $this->get('/feedback/test-only/probe')->assertRedirect();

        $this->post(route('guest.login.consume', $token))->assertRedirect(route('guest.login.show', $token));
        $this->get(route('guest.login.show', $token))->assertInertia(fn ($page) => $page->where('mode', 'invalid')->where('token', null));
        $this->get('/feedback/test-only/probe')->assertRedirect();

        $second = $this->requestLink();
        $this->travel(PortalAuth::LINK_MINUTES + 1)->minutes();
        $this->post(route('guest.login.consume', $second))->assertRedirect(route('guest.login.show', $second));
        $this->get('/feedback/test-only/probe')->assertRedirect();
    }

    public function test_the_answer_never_reveals_an_address_and_mails_per_address_are_limited(): void
    {
        for ($i = 0; $i < PortalAuth::LINKS_PER_EMAIL + 2; $i++) {
            $this->post(route('guest.login.store'), ['email' => 'flood@example.com'])
                ->assertSessionHas('portal_link_sent', true);
        }

        $this->assertSame(PortalAuth::LINKS_PER_EMAIL, PortalLoginToken::count());
        Notification::assertSentToTimes(PortalIdentity::findByEmail('flood@example.com'), PortalLoginLinkNotification::class, PortalAuth::LINKS_PER_EMAIL);
    }

    public function test_return_paths_stay_inside_the_portals(): void
    {
        foreach (['https://evil.test/x', '//evil.test', '/\\evil.test', '/dashboard', '/feedback', "/feedback/a\nb", null, ''] as $bad) {
            $this->assertSame('/', PortalAuth::safeReturnTo($bad), (string) $bad);
        }
        $this->assertSame('/feedback/acme?sort=top', PortalAuth::safeReturnTo('/feedback/acme?sort=top'));
        $this->assertSame('/client/acme/deliverables/1', PortalAuth::safeReturnTo('/client/acme/deliverables/1'));

        $token = $this->requestLink('x@example.com', 'https://evil.test');
        $this->post(route('guest.login.consume', $token))->assertRedirect('/');
    }

    public function test_a_signed_in_member_is_themselves_and_takes_over_their_guest_identity(): void
    {
        $guest = PortalIdentity::create(['email' => 'member@example.com', 'name' => 'Old guest name', 'email_verified_at' => now()]);
        $user = User::factory()->create(['email' => 'Member@Example.com', 'name' => 'Mia Member']);

        $this->actingAs($user)->get('/feedback/test-only/probe')->assertOk()->assertSee('hello member@example.com');

        $this->assertSame($user->id, $guest->fresh()->user_id);
        $this->assertSame(1, PortalIdentity::count());
        $this->assertTrue(PortalAuth::identityForUser($user)->is($guest));
        $this->assertTrue($guest->fresh()->toPortalArray()['is_member']);
    }

    public function test_an_unverified_member_is_treated_as_a_guest(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get('/feedback/test-only/probe')->assertRedirect(route('guest.login', ['return_to' => '/feedback/test-only/probe']));
        $this->assertSame(0, PortalIdentity::count());
    }

    public function test_without_required_verification_an_unconfirmed_account_never_takes_over_a_guest_identity(): void
    {
        // someone registers the victim's address on an instance that doesn't require verification
        InstanceSettings::instance()->update(['settings' => ['require_email_verification' => false]]);
        $victim = PortalIdentity::create(['email' => 'client@example.com', 'name' => 'Real client', 'email_verified_at' => now()]);
        $squatter = User::factory()->unverified()->create(['email' => 'client@example.com']);
        $this->assertTrue($squatter->hasVerifiedEmail(), 'the instance treats everyone as verified');

        $this->actingAs($squatter)->get('/feedback/test-only/probe')->assertRedirect();

        $this->assertNull($victim->fresh()->user_id);
        $this->assertSame(1, PortalIdentity::count());
    }

    public function test_json_calls_without_an_identity_get_401(): void
    {
        $this->getJson('/feedback/test-only/probe')->assertUnauthorized();
    }

    public function test_spent_and_expired_links_are_pruned(): void
    {
        $this->requestLink();
        $this->travel(2)->days();
        $this->artisan('model:prune', ['--model' => [PortalLoginToken::class]])->assertSuccessful();

        $this->assertSame(0, PortalLoginToken::count());
    }
}
