<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Settings → Members "Invite" on the web (WorkspaceInvites, shared with the App API since BRAI-390). */
class WorkspaceInvitesTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $admin;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->admin = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->admin->id]);
        $this->workspace->addUser($this->admin, 'admin');
        $this->editor = User::factory()->create();
        $this->workspace->addUser($this->editor, 'editor');
    }

    public function test_the_web_invite_still_asks_before_sending_and_adds_existing_accounts(): void
    {
        $friend = User::factory()->create(['email' => 'friend@example.com']);
        $url = route('workspaces.members.invite', $this->workspace->slug);

        $this->actingAs($this->admin)->post($url, ['email' => 'friend@example.com', 'role' => 'viewer'])
            ->assertRedirect()->assertSessionHas('invite_result', 'member_added');
        $this->assertSame('viewer', $friend->workspaceRole($this->workspace));

        $this->actingAs($this->admin)->postJson($url, ['email' => 'nobody@example.com', 'role' => 'editor'])
            ->assertStatus(409)->assertJson(['confirm_required' => true, 'email' => 'nobody@example.com', 'role' => 'editor']);
        $this->assertSame(0, $this->workspace->invitations()->count());

        $this->actingAs($this->admin)->post($url, ['email' => 'nobody@example.com', 'role' => 'editor', 'confirm_send_invitation' => true])
            ->assertRedirect()->assertSessionHas('invite_result', 'invitation_sent');
        $this->assertSame(1, $this->workspace->invitations()->count());

        $this->actingAs($this->admin)->post($url, ['email' => 'friend@example.com', 'role' => 'viewer'])->assertSessionHasErrors('email');
        $this->actingAs($this->editor)->post($url, ['email' => 'x@example.com', 'role' => 'viewer'])->assertForbidden();
    }
}
