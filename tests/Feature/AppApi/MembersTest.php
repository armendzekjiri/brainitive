<?php

namespace Tests\Feature\AppApi;

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\WorkspaceInvitationNotification;
use App\Support\Entitlements\ConfigEntitlements;
use App\Support\Entitlements\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\TestCase;

/** App API v1 members (BRAI-390): workspace / project members for pickers, workspace invitations. */
class MembersTest extends TestCase
{
    use ActsAsAppClient;
    use ForgetsGuardsBetweenRequests;
    use RefreshDatabase;

    private Workspace $workspace;

    private User $admin;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->admin = User::factory()->create(['name' => 'Ada Admin', 'email' => 'ada@example.com']);
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->admin->id]);
        $this->workspace->addUser($this->admin, 'admin');
        $this->editor = User::factory()->create(['name' => 'Eve Editor', 'email' => 'eve@example.com']);
        $this->workspace->addUser($this->editor, 'editor');
    }

    public function test_lists_workspace_members_by_name_with_their_role_and_filters(): void
    {
        $viewer = User::factory()->create(['name' => 'Bob Viewer', 'email' => 'bob@other.test']);
        $this->workspace->addUser($viewer, 'viewer');
        User::factory()->create(['name' => 'Alice Outsider']);
        $token = $this->appToken($this->editor);
        $url = "/api/v1/workspaces/{$this->workspace->slug}/members";

        $response = $this->getJson($url, $this->appHeaders($token))->assertOk();
        $response->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.name', 'Ada Admin')
            ->assertJsonPath('data.0.role', 'admin')
            ->assertJsonPath('data.1.name', 'Bob Viewer')
            ->assertJsonPath('data.1.role', 'viewer')
            ->assertJsonPath('data.2.name', 'Eve Editor')
            ->assertJsonPath('next_cursor', null);
        $this->assertSame(['id', 'name', 'email', 'avatar_url', 'role'], array_keys($response->json('data.0')));

        // by name or email, case-insensitive; % is matched literally
        $this->getJson("{$url}?q=BOB", $this->appHeaders($token))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $viewer->id);
        $this->getJson("{$url}?q=other.test", $this->appHeaders($token))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("{$url}?q=%25", $this->appHeaders($token))->assertOk()->assertJsonCount(0, 'data');

        $page = $this->getJson("{$url}?limit=2", $this->appHeaders($token))->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("{$url}?limit=2&cursor=".urlencode($page->json('next_cursor')), $this->appHeaders($token))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Eve Editor');
    }

    public function test_lists_project_members_with_their_project_role(): void
    {
        $project = Project::factory()->create(['workspace_id' => $this->workspace->id]);
        $project->addUser($this->editor, 'editor');
        $project->addUser($this->admin, 'admin');
        $hidden = Project::factory()->create(['workspace_id' => $this->workspace->id]);

        $this->getJson("/api/v1/projects/{$project->id}/members", $this->appHeaders($this->appToken($this->editor)))->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Ada Admin')
            ->assertJsonPath('data.0.role', 'admin')
            ->assertJsonPath('data.1.role', 'editor');

        // a project the editor isn't in, another workspace: 404
        $this->getJson("/api/v1/projects/{$hidden->id}/members", $this->appHeaders($this->appToken($this->editor)))->assertNotFound();
        $this->getJson("/api/v1/workspaces/{$this->workspace->slug}/members", $this->appHeaders($this->appToken(User::factory()->create())))
            ->assertNotFound()->assertJsonPath('code', 'not_found');
    }

    public function test_an_admin_adds_an_existing_account_at_once(): void
    {
        $friend = User::factory()->create(['email' => 'friend@example.com']);

        $this->postJson("/api/v1/workspaces/{$this->workspace->slug}/invitations", ['email' => 'Friend@Example.com', 'role' => 'member'], $this->appHeaders($this->appToken($this->admin)))
            ->assertCreated()
            ->assertJsonPath('data.result', 'member_added')
            ->assertJsonPath('data.email', 'friend@example.com')
            ->assertJsonPath('data.member.id', $friend->id)
            ->assertJsonPath('data.expires_at', null);

        $this->assertSame('member', $friend->workspaceRole($this->workspace));
        Notification::assertNothingSent();
    }

    public function test_an_unknown_address_gets_an_invitation_email(): void
    {
        $token = $this->appToken($this->admin);
        $url = "/api/v1/workspaces/{$this->workspace->slug}/invitations";

        $response = $this->postJson($url, ['email' => 'new@example.com', 'role' => 'editor'], $this->appHeaders($token))
            ->assertCreated()
            ->assertJsonPath('data.result', 'invitation_sent')
            ->assertJsonPath('data.member', null);
        $this->assertMatchesRegularExpression('/Z$/', $response->json('data.expires_at'));

        $invitation = $this->workspace->invitations()->sole();
        $this->assertSame('editor', $invitation->role);
        Notification::assertSentOnDemand(WorkspaceInvitationNotification::class);

        // a repeat refreshes the open invitation instead of adding another
        $this->postJson($url, ['email' => 'new@example.com', 'role' => 'viewer'], $this->appHeaders($token))->assertCreated();
        $this->assertSame('viewer', $this->workspace->invitations()->sole()->role);
    }

    public function test_invite_validation_permissions_and_limits(): void
    {
        $url = "/api/v1/workspaces/{$this->workspace->slug}/invitations";
        $adminToken = $this->appToken($this->admin);

        $this->postJson($url, ['email' => 'eve@example.com', 'role' => 'editor'], $this->appHeaders($adminToken))
            ->assertStatus(422)->assertJsonValidationErrors('email');
        $this->postJson($url, ['email' => 'not-an-email', 'role' => 'owner'], $this->appHeaders($adminToken))
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors(['email', 'role']);

        // only admins invite (403, before validation); outsiders don't learn the workspace exists
        $this->postJson($url, ['email' => 'ada@example.com', 'role' => 'editor'], $this->appHeaders($this->appToken($this->editor)))->assertForbidden();
        $this->postJson($url, ['email' => 'ada@example.com', 'role' => 'editor'], $this->appHeaders($this->appToken(User::factory()->create())))->assertNotFound();

        // owner + editor = 2 members
        $this->app->instance(Entitlements::class, new ConfigEntitlements(['enforced' => true, 'members' => 2]));
        $this->postJson($url, ['email' => 'third@example.com', 'role' => 'viewer'], $this->appHeaders($adminToken))
            ->assertStatus(402)->assertJsonPath('code', 'limit_reached')->assertJsonPath('limit.kind', 'members');
        $this->assertSame(0, $this->workspace->invitations()->count());
    }

    public function test_tokens_are_checked(): void
    {
        $project = Project::factory()->create(['workspace_id' => $this->workspace->id]);
        $passport = $this->passportToken($this->admin);

        foreach ([
            ['GET', "/api/v1/workspaces/{$this->workspace->slug}/members"],
            ['GET', "/api/v1/projects/{$project->id}/members"],
            ['POST', "/api/v1/workspaces/{$this->workspace->slug}/invitations"],
        ] as [$method, $uri]) {
            $this->json($method, $uri)->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
            $this->json($method, $uri, [], $this->appHeaders($passport))->assertUnauthorized();
        }
    }
}
