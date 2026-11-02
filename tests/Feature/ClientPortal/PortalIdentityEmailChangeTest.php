<?php

namespace Tests\Feature\ClientPortal;

use App\Models\ClientPortalMember;
use App\Models\PortalIdentity;
use App\Models\User;
use App\Support\Portal\PortalAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Codex web review O: an account acts in client / feedback portals only as the identity of its CURRENT verified
 * email. After an email change the old identity (and its memberships, decisions, votes) stays with the old address;
 * a guest who signs in by a link to the old address keeps working separately.
 */
class PortalIdentityEmailChangeTest extends TestCase
{
    use InteractsWithClientPortal;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpClientPortal();
        Notification::fake();
    }

    public function test_an_email_change_drops_the_old_identitys_access(): void
    {
        [, $deliverable] = $this->sharedPage();
        $account = User::factory()->create(['email' => 'client@acme.test', 'email_verified_at' => now()]);
        $old = $this->client();

        $this->actingAs($account)->get($this->deliverableUrl($deliverable))->assertOk();
        $this->assertSame($account->id, $old->fresh()->user_id);

        // change the address, then verify the new one
        $this->actingAs($account)->patch(route('profile.update'), ['name' => $account->name, 'email' => 'new-owner@example.test'])->assertSessionHasNoErrors();
        $this->assertNull($old->fresh()->user_id);
        $account->refresh()->forceFill(['email_verified_at' => now()])->save();

        // a stranger to this portal now (404, as for anyone who isn't invited)
        $this->actingAs($account)->get('/client/acme-site')->assertNotFound();
        $this->actingAs($account)->get($this->deliverableUrl($deliverable))->assertNotFound();
        $this->actingAs($account)->get($this->deliverableUrl($deliverable, '/download'))->assertNotFound();
        $this->actingAs($account)->post($this->deliverableUrl($deliverable, '/decision'), ['decision' => 'approved'])->assertNotFound();
        $this->actingAs($account)->post($this->deliverableUrl($deliverable, '/comments'), ['body' => 'hi'])->assertNotFound();
        $this->assertSame(0, $deliverable->decisions()->count());

        $new = PortalAuth::identityForUser($account->fresh());
        $this->assertSame('new-owner@example.test', $new->email);
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame(0, ClientPortalMember::where('portal_identity_id', $new->id)->count());

        // an invitation of the new address works, through the new identity only
        $this->client(null, 'new-owner@example.test');
        $this->actingAs($account)->get('/client/acme-site')->assertOk();
        $this->assertSame(1, ClientPortalMember::where('portal_identity_id', $new->id)->count());
        $this->assertSame(1, ClientPortalMember::where('portal_identity_id', $old->id)->count());
    }

    public function test_a_stale_link_without_the_profile_route_is_not_used_either(): void
    {
        $account = User::factory()->create(['email' => 'client@acme.test', 'email_verified_at' => now()]);
        $old = $this->client();
        PortalAuth::identityForUser($account);

        // the email changed some other way (admin, import): the lookup itself checks the address
        $account->forceFill(['email' => 'moved@example.test'])->save();
        $identity = PortalAuth::identityForUser($account->fresh());

        $this->assertSame('moved@example.test', $identity->email);
        $this->assertNull($old->fresh()->user_id);
    }

    public function test_a_guest_signed_in_to_the_old_address_keeps_working(): void
    {
        [, $deliverable] = $this->sharedPage();
        $account = User::factory()->create(['email' => 'client@acme.test', 'email_verified_at' => now()]);
        $old = $this->client();
        PortalAuth::identityForUser($account);
        $this->actingAs($account)->patch(route('profile.update'), ['name' => $account->name, 'email' => 'new-owner@example.test'])->assertSessionHasNoErrors();
        auth()->logout();

        // the person who controls client@acme.test signs in with an emailed link (a guest session)
        $this->as($old->fresh())->get($this->deliverableUrl($deliverable))->assertOk();
        $this->assertInstanceOf(PortalIdentity::class, $old->fresh());
    }
}
