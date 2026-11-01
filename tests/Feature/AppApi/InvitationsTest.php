<?php

namespace Tests\Feature\AppApi;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Notifications\InvitationAcceptedNotification;
use App\Support\Entitlements\ConfigEntitlements;
use App\Support\Entitlements\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\TestCase;

/** App API v1 invitations (BRAI-386): show + accept, with the web's rules. */
class InvitationsTest extends TestCase
{
    use ActsAsAppClient;
    use ForgetsGuardsBetweenRequests;
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->owner = User::factory()->create(['name' => 'Erin Owner']);
        $this->workspace = Workspace::factory()->create(['name' => 'Acme', 'owner_id' => $this->owner->id]);
        $this->workspace->addUser($this->owner, 'admin');
    }

    private function invite(string $email, array $attributes = []): WorkspaceInvitation
    {
        return $this->workspace->invitations()->create(['email' => $email, 'role' => 'editor', 'invited_by' => $this->owner->id, ...$attributes]);
    }

    public function test_shows_an_invitation_for_the_invited_user(): void
    {
        $user = User::factory()->create(['email' => 'joiner@example.com']);
        $invitation = $this->invite('Joiner@Example.com');

        $this->getJson("/api/v1/invitations/{$invitation->token}", $this->appHeaders($this->appToken($user)))
            ->assertOk()
            ->assertExactJson(['data' => [
                'email' => 'Joiner@Example.com',
                'role' => 'editor',
                'expires_at' => $invitation->expires_at->toIso8601ZuluString(),
                'expired' => false,
                'accepted' => false,
                'email_matches' => true,
                'already_member' => false,
                'can_accept' => true,
                'workspace' => ['name' => 'Acme', 'slug' => null],
                'inviter' => ['name' => 'Erin Owner'],
            ]]);
    }

    public function test_show_reports_other_email_expired_and_accepted_states(): void
    {
        $other = User::factory()->create(['email' => 'other@example.com']);
        $token = $this->appToken($other);

        $this->getJson('/api/v1/invitations/'.$this->invite('joiner@example.com')->token, $this->appHeaders($token))
            ->assertOk()->assertJsonPath('data.email_matches', false)->assertJsonPath('data.can_accept', false);

        $expired = $this->invite('other@example.com');
        $expired->forceFill(['expires_at' => now()->subDay()])->save();
        $this->getJson("/api/v1/invitations/{$expired->token}", $this->appHeaders($token))
            ->assertOk()->assertJsonPath('data.expired', true)->assertJsonPath('data.can_accept', false);

        $member = User::factory()->create(['email' => 'member@example.com']);
        $this->workspace->addUser($member, 'viewer');
        $done = $this->invite('member@example.com', ['accepted_at' => now()]);
        $this->getJson("/api/v1/invitations/{$done->token}", $this->appHeaders($this->appToken($member)))
            ->assertOk()
            ->assertJsonPath('data.accepted', true)
            ->assertJsonPath('data.already_member', true)
            ->assertJsonPath('data.workspace.slug', $this->workspace->slug);
    }

    public function test_an_unknown_token_is_a_404(): void
    {
        $token = $this->appToken(User::factory()->create());

        $this->getJson('/api/v1/invitations/'.str_repeat('a', 64), $this->appHeaders($token))
            ->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->postJson('/api/v1/invitations/'.str_repeat('a', 64).'/accept', [], $this->appHeaders($token))->assertNotFound();
        $this->getJson('/api/v1/invitations/not-a-token!', $this->appHeaders($token))->assertNotFound();
    }

    public function test_accepting_joins_the_workspace_and_returns_it(): void
    {
        $user = User::factory()->create(['email' => 'joiner@example.com']);
        $invitation = $this->invite('joiner@example.com');

        $this->postJson("/api/v1/invitations/{$invitation->token}/accept", [], $this->appHeaders($this->appToken($user)))
            ->assertOk()
            ->assertExactJson(['data' => ['id' => $this->workspace->id, 'slug' => $this->workspace->slug, 'name' => 'Acme', 'role' => 'editor']]);

        $this->assertTrue($this->workspace->hasUser($user));
        $this->assertNotNull($invitation->fresh()->accepted_at);
        Notification::assertSentTo($this->owner, InvitationAcceptedNotification::class);
    }

    public function test_accepting_again_is_fine_for_a_member_and_a_conflict_for_anyone_else(): void
    {
        $user = User::factory()->create(['email' => 'joiner@example.com']);
        $token = $this->appToken($user);
        $invitation = $this->invite('joiner@example.com');

        $this->postJson("/api/v1/invitations/{$invitation->token}/accept", [], $this->appHeaders($token))->assertOk();
        $this->postJson("/api/v1/invitations/{$invitation->token}/accept", [], $this->appHeaders($token))
            ->assertOk()->assertJsonPath('data.slug', $this->workspace->slug);
        $this->assertSame(1, $this->workspace->users()->where('users.id', $user->id)->count());

        // removed meanwhile: the used invitation doesn't bring them back
        $this->workspace->removeUser($user);
        $this->postJson("/api/v1/invitations/{$invitation->token}/accept", [], $this->appHeaders($token))
            ->assertStatus(409)->assertJsonPath('code', 'conflict');
        $this->assertFalse($this->workspace->hasUser($user));
    }

    public function test_an_existing_member_accepting_just_closes_the_invitation(): void
    {
        $user = User::factory()->create(['email' => 'joiner@example.com']);
        $this->workspace->addUser($user, 'viewer');
        $invitation = $this->invite('joiner@example.com');

        $this->postJson("/api/v1/invitations/{$invitation->token}/accept", [], $this->appHeaders($this->appToken($user)))
            ->assertOk()->assertJsonPath('data.role', 'viewer');

        $this->assertNotNull($invitation->fresh()->accepted_at);
        $this->assertSame(1, $this->workspace->users()->where('users.id', $user->id)->count());
        Notification::assertNothingSent();
    }

    public function test_a_different_email_cannot_accept(): void
    {
        $user = User::factory()->create(['email' => 'other@example.com']);
        $invitation = $this->invite('joiner@example.com');

        $this->postJson("/api/v1/invitations/{$invitation->token}/accept", [], $this->appHeaders($this->appToken($user)))
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden')
            ->assertJsonPath('message', 'This invitation was sent to a different email address. Sign in with the account that received it.');

        $this->assertFalse($this->workspace->hasUser($user));
        $this->assertNull($invitation->fresh()->accepted_at);
    }

    public function test_an_expired_invitation_cannot_be_accepted(): void
    {
        $user = User::factory()->create(['email' => 'joiner@example.com']);
        $invitation = $this->invite('joiner@example.com');
        $invitation->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->postJson("/api/v1/invitations/{$invitation->token}/accept", [], $this->appHeaders($this->appToken($user)))
            ->assertStatus(410)
            ->assertExactJson(['message' => 'This invitation has expired.', 'code' => 'gone']);

        $this->assertFalse($this->workspace->hasUser($user));
    }

    public function test_an_unverified_email_cannot_accept(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'joiner@example.com']);
        $invitation = $this->invite('joiner@example.com');

        $this->postJson("/api/v1/invitations/{$invitation->token}/accept", [], $this->appHeaders($this->appToken($user)))
            ->assertForbidden()->assertJsonPath('code', 'forbidden');

        $this->assertFalse($this->workspace->hasUser($user));
    }

    public function test_a_workspace_over_its_member_limit_blocks_with_402(): void
    {
        $this->app->instance(Entitlements::class, new ConfigEntitlements(['enforced' => true, 'members' => 2]));
        $user = User::factory()->create(['email' => 'joiner@example.com']);
        $invitation = $this->invite('joiner@example.com');
        $this->workspace->addUser(User::factory()->create(), 'viewer'); // 2 members + 1 open invitation

        $this->postJson("/api/v1/invitations/{$invitation->token}/accept", [], $this->appHeaders($this->appToken($user)))
            ->assertStatus(402)
            ->assertJsonPath('code', 'limit_reached')
            ->assertJsonPath('limit.kind', 'members')
            ->assertJsonPath('limit.limit', 2)
            ->assertJsonPath('limit.workspace', $this->workspace->slug)
            ->assertJsonPath('limit.billing_url', null) // not an admin of it
            ->assertJsonStructure(['message', 'code', 'limit' => ['kind', 'limit', 'usage', 'plan', 'workspace', 'billing_url']]);

        $this->assertFalse($this->workspace->hasUser($user));
        $this->assertNull($invitation->fresh()->accepted_at);
    }

    public function test_passport_tokens_and_no_token_get_401(): void
    {
        $user = User::factory()->create(['email' => 'joiner@example.com']);
        $invitation = $this->invite('joiner@example.com');
        $passport = $this->passportToken($user);

        foreach ([[], $this->appHeaders($passport)] as $headers) {
            $this->getJson("/api/v1/invitations/{$invitation->token}", $headers)->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
            $this->postJson("/api/v1/invitations/{$invitation->token}/accept", [], $headers)->assertStatus(401);
        }

        $this->assertFalse($this->workspace->hasUser($user));
    }
}
