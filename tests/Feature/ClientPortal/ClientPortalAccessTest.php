<?php

namespace Tests\Feature\ClientPortal;

use App\Models\ClientPortal;
use App\Models\ClientPortalMember;
use App\Models\PortalIdentity;
use App\Models\Project;
use App\Notifications\ClientPortalGuestNotification;
use App\Notifications\PortalLoginLinkNotification;
use App\Support\ClientPortal\Deliverables;
use App\Support\Portal\PortalAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Client portal access (v1.42.0, BRAI-73): invitations, revoke / expiry, scoping of ids, team abilities. */
class ClientPortalAccessTest extends TestCase
{
    use InteractsWithClientPortal, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->setUpClientPortal();
    }

    private function team(string $suffix = ''): string
    {
        return route('client-portal.manage.index', [$this->workspace->slug, $this->project->slug]).$suffix;
    }

    public function test_an_admin_enables_the_portal_and_the_slug_is_validated(): void
    {
        $other = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->admin->id]);
        $other->users()->attach($this->admin->id, ['role' => 'admin']);
        $url = route('client-portal.manage.update', [$this->workspace->slug, $other->slug]);

        $this->actingAs($this->admin)->put($url, ['enabled' => true, 'title' => 'Other', 'slug' => 'acme-site'])->assertSessionHasErrors('slug');
        $this->actingAs($this->admin)->put($url, ['enabled' => true, 'title' => 'Other', 'slug' => 'Bad Slug!'])->assertSessionHasErrors('slug');
        $this->actingAs($this->admin)->put($url, ['enabled' => true, 'title' => '<b>Other</b>', 'slug' => 'other-co', 'welcome' => 'Hi'])->assertSessionHasNoErrors();

        $portal = ClientPortal::where('project_id', $other->id)->firstOrFail();
        $this->assertSame('Other', $portal->title);
        $this->assertTrue($portal->enabled);

        // editors and viewers can't change settings; outsiders can't even see the page
        $this->actingAs($this->editor)->put($url, ['enabled' => false, 'title' => 'x', 'slug' => 'other-co'])->assertForbidden();
        $this->actingAs($this->outsider)->get($this->team())->assertForbidden();
        $this->actingAs($this->viewer)->get($this->team())->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Projects/ClientPortal')->where('canManage', false)->where('canPublish', false));
    }

    public function test_invite_sends_an_invitation_and_a_sign_in_link_back_to_the_portal(): void
    {
        $this->actingAs($this->admin)->post($this->team('/members'), ['email' => 'New@Client.test', 'name' => 'Nina', 'expires_at' => now()->addDays(10)->toDateString()])
            ->assertSessionHasNoErrors();

        $identity = PortalIdentity::findByEmail('new@client.test');
        $this->assertSame('Nina', $identity->name);
        $member = ClientPortalMember::where('portal_identity_id', $identity->id)->firstOrFail();
        $this->assertSame($this->admin->id, $member->invited_by);
        $this->assertTrue($member->isActive());
        $this->assertTrue($member->expires_at->isSameDay(now()->addDays(10)));

        Notification::assertSentTo($identity, ClientPortalGuestNotification::class, fn ($n) => $n->kind === ClientPortalGuestNotification::INVITED);
        Notification::assertSentTo($identity, PortalLoginLinkNotification::class);
        $this->assertDatabaseHas('portal_login_tokens', ['portal_identity_id' => $identity->id, 'return_to' => '/client/acme-site']);

        // no workspace membership
        $this->assertNull($identity->user_id);
        $this->assertSame(0, $this->workspace->users()->where('email', 'new@client.test')->count());
    }

    public function test_client_addresses_are_for_project_admins_and_invitation_mails_are_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($this->admin)->post($this->team('/members'), ['email' => 'repeat@client.test'])->assertSessionHasNoErrors();
        }
        $identity = PortalIdentity::findByEmail('repeat@client.test');
        Notification::assertSentToTimes($identity, ClientPortalGuestNotification::class, 3);

        $this->actingAs($this->viewer)->get($this->team())->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('members.0.email', null)->where('members.0.state', 'active'));
        $this->actingAs($this->admin)->get($this->team())
            ->assertInertia(fn (AssertableInertia $page) => $page->where('members.0.email', 'repeat@client.test'));
    }

    public function test_only_project_admins_invite_and_a_disabled_portal_refuses_invitations(): void
    {
        $this->actingAs($this->editor)->post($this->team('/members'), ['email' => 'x@client.test'])->assertForbidden();

        $this->portal->update(['enabled' => false]);
        $this->actingAs($this->admin)->post($this->team('/members'), ['email' => 'x@client.test'])->assertSessionHasErrors('email');
        $this->assertNull(PortalIdentity::findByEmail('x@client.test'));
    }

    public function test_an_invited_client_sees_the_portal_and_strangers_are_turned_away(): void
    {
        $client = $this->client();
        [, $deliverable] = $this->sharedPage();

        $this->get('/client/acme-site')->assertRedirect(route('guest.login', ['return_to' => '/client/acme-site']));

        $this->as($client)->get('/client/acme-site')->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertInertia(fn (AssertableInertia $page) => $page->component('ClientPortal/Index')
                ->where('portal.title', 'Acme website')
                ->has('deliverables', 1)
                ->where('deliverables.0.uuid', $deliverable->uuid)
                ->where('deliverables.0.status', 'awaiting'));

        // anyone can sign in with an email: for people never invited the portal doesn't exist (no title, no slug probing)
        $stranger = $this->guest('stranger@else.test');
        $this->as($stranger)->get('/client/acme-site')->assertNotFound()->assertDontSee('Acme website');
        $this->as($stranger)->get($this->deliverableUrl($deliverable))->assertNotFound();
        $this->as($stranger)->post($this->deliverableUrl($deliverable, '/decision'), ['decision' => 'approved'])->assertNotFound();
        $this->assertSame(0, $deliverable->decisions()->count());

        // a team member is not automatically a client either
        $this->actingAs($this->admin)->get('/client/acme-site')->assertNotFound();
    }

    public function test_revoked_and_expired_clients_are_denied_pages_downloads_and_writes(): void
    {
        $client = $this->client();
        [, $deliverable] = $this->sharedPage();
        $this->as($client)->get($this->deliverableUrl($deliverable, '/download'))->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="homepage-copy-v1.html"');

        $member = ClientPortalMember::firstOrFail();
        $this->actingAs($this->admin)->delete($this->team('/members/'.$member->id))->assertRedirect();
        $this->assertNotNull($member->fresh()->revoked_at);
        $this->assertSame($this->admin->id, $member->fresh()->revoked_by);
        auth()->logout();

        $this->as($client)->get('/client/acme-site')->assertForbidden()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('ClientPortal/Denied')->where('reason', 'revoked'));
        $this->as($client)->get($this->deliverableUrl($deliverable))->assertForbidden();
        $this->as($client)->get($this->deliverableUrl($deliverable, '/download'))->assertForbidden();
        $this->as($client)->post($this->deliverableUrl($deliverable, '/decision'), ['decision' => 'approved'])->assertForbidden();
        $this->as($client)->post($this->deliverableUrl($deliverable, '/comments'), ['body' => 'hi'])->assertForbidden();
        $this->assertSame(0, $deliverable->decisions()->count());

        // re-invite restores access
        $this->actingAs($this->admin)->post($this->team('/members'), ['email' => 'client@acme.test'])->assertSessionHasNoErrors();
        auth()->logout();
        $this->assertNull($member->fresh()->revoked_at);
        $this->as($client)->get('/client/acme-site')->assertOk();

        // expiry
        $member->fresh()->update(['expires_at' => now()->subMinute()]);
        $this->as($client)->get('/client/acme-site')->assertForbidden()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('reason', 'expired'));
        $this->as($client)->get($this->deliverableUrl($deliverable, '/download'))->assertForbidden();
    }

    public function test_another_portals_ids_and_disabled_or_unknown_portals_are_404(): void
    {
        $client = $this->client();
        [, $deliverable] = $this->sharedPage();

        $otherProject = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->admin->id]);
        $otherPortal = ClientPortal::create(['project_id' => $otherProject->id, 'slug' => 'other-co', 'title' => 'Other', 'enabled' => true]);
        $this->client($otherPortal);

        // the client is a member of both portals, but a deliverable only opens under its own portal
        $this->as($client)->get('/client/other-co/d/'.$deliverable->uuid)->assertNotFound();
        $this->as($client)->post('/client/other-co/d/'.$deliverable->uuid.'/decision', ['decision' => 'approved'])->assertNotFound();
        $this->as($client)->get('/client/acme-site/d/00000000-0000-4000-8000-000000000000')->assertNotFound();
        $this->as($client)->get('/client/acme-site/d/123')->assertNotFound();
        $this->as($client)->get('/client/no-such-portal')->assertNotFound();

        $this->portal->update(['enabled' => false]);
        $this->as($client)->get('/client/acme-site')->assertNotFound();
        $this->as($client)->get($this->deliverableUrl($deliverable))->assertNotFound();
    }

    public function test_team_routes_only_reach_this_projects_portal(): void
    {
        $this->client();
        [, $deliverable] = $this->sharedPage();
        $member = ClientPortalMember::firstOrFail();

        $otherProject = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->admin->id]);
        $otherProject->users()->attach($this->admin->id, ['role' => 'admin']);
        ClientPortal::create(['project_id' => $otherProject->id, 'slug' => 'other-co', 'title' => 'Other', 'enabled' => true]);
        $other = [$this->workspace->slug, $otherProject->slug];

        $this->actingAs($this->admin)->get(route('client-portal.manage.deliverables.show', [...$other, $deliverable->id]))->assertNotFound();
        $this->actingAs($this->admin)->delete(route('client-portal.manage.members.destroy', [...$other, $member->id]))->assertNotFound();
        $this->actingAs($this->admin)->delete(route('client-portal.manage.deliverables.destroy', [...$other, $deliverable->id]))->assertNotFound();
        $this->actingAs($this->admin)->post(route('client-portal.manage.deliverables.comments.store', [...$other, $deliverable->id]), ['body' => 'x', 'visibility' => 'client'])->assertNotFound();
        $this->assertNull($member->fresh()->revoked_at);
        $this->assertNull($deliverable->fresh()->withdrawn_at);

        // a page of this project can't be published into the other project's portal
        $page = $this->publishedPage(['name' => 'Elsewhere']);
        $this->actingAs($this->admin)->post(route('client-portal.manage.deliverables.store', $other), ['kind' => 'page', 'id' => $page->uuid])
            ->assertSessionHasErrors('id');
    }

    public function test_a_withdrawn_deliverable_disappears_for_clients(): void
    {
        $client = $this->client();
        [, $deliverable] = $this->sharedPage();

        $this->actingAs($this->viewer)->delete(route('client-portal.manage.deliverables.destroy', [$this->workspace->slug, $this->project->slug, $deliverable->id]))->assertForbidden();
        $this->actingAs($this->editor)->delete(route('client-portal.manage.deliverables.destroy', [$this->workspace->slug, $this->project->slug, $deliverable->id]))->assertRedirect();
        auth()->logout();

        $this->as($client)->get($this->deliverableUrl($deliverable))->assertNotFound();
        $this->as($client)->get('/client/acme-site')->assertInertia(fn (AssertableInertia $page) => $page->has('deliverables', 0));
    }

    public function test_a_brainitive_user_is_a_client_through_their_identity_when_invited(): void
    {
        [, $deliverable] = $this->sharedPage();
        $this->client(null, $this->outsider->email);

        $this->actingAs($this->outsider)->get($this->deliverableUrl($deliverable))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('ClientPortal/Deliverable'));
        $this->assertSame($this->outsider->id, PortalAuth::identityForUser($this->outsider)->user_id);
    }

    public function test_team_abilities_are_enforced_per_action(): void
    {
        $this->client();
        [, $deliverable] = $this->sharedPage();
        $member = ClientPortalMember::firstOrFail();
        $base = [$this->workspace->slug, $this->project->slug];

        $this->actingAs($this->editor)->delete(route('client-portal.manage.members.destroy', [...$base, $member->id]))->assertForbidden();
        $this->actingAs($this->viewer)->delete(route('client-portal.manage.members.destroy', [...$base, $member->id]))->assertForbidden();
        $this->assertNull($member->fresh()->revoked_at);
        $this->actingAs($this->outsider)->get(route('client-portal.manage.deliverables.show', [...$base, $deliverable->id]))->assertForbidden();
        $this->actingAs($this->viewer)->get(route('client-portal.manage.deliverables.show', [...$base, $deliverable->id]))->assertOk();

        // tags only: no title, no comment
        $this->actingAs($this->admin)->put(route('client-portal.manage.update', $base), ['enabled' => true, 'title' => '<b></b>', 'slug' => 'acme-site'])->assertSessionHasErrors('title');
        $this->actingAs($this->editor)->post(route('client-portal.manage.deliverables.comments.store', [...$base, $deliverable->id]), ['body' => '<b></b>', 'visibility' => 'client'])->assertSessionHasErrors('body');
        auth()->logout();
        $this->as(PortalIdentity::findByEmail('client@acme.test'))->post($this->deliverableUrl($deliverable, '/comments'), ['body' => '<b> </b>'])->assertSessionHasErrors('body');
        $this->assertSame(0, $deliverable->comments()->count());
    }

    public function test_the_portal_of_a_trashed_project_is_offline(): void
    {
        $client = $this->client();
        [, $deliverable] = $this->sharedPage();
        $this->project->delete();

        $this->as($client)->get('/client/acme-site')->assertNotFound();
        $this->as($client)->get($this->deliverableUrl($deliverable))->assertNotFound();
        // a decision that was already in flight still saves and doesn't fail on the missing project
        Deliverables::decide($deliverable->fresh(), $client, 'approved', null);
        $this->assertSame(1, $deliverable->decisions()->count());
        Notification::assertNothingSentTo($this->admin);
    }
}
