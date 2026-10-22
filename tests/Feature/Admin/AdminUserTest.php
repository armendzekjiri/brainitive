<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAuditLog;
use App\Models\Board;
use App\Models\Item;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminUserTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdmin();
        Notification::fake();
    }

    public function test_the_user_list_shows_counts_but_no_content(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.users.index', ['q' => $this->owner->email]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Users', false)
                ->has('users.data', 1)
                ->where('users.data.0.email', $this->owner->email)
                ->where('users.data.0.owned_workspaces_count', 1)
                ->where('users.data.0.workspaces_count', 1)
                ->where('users.data.0.projects_count', 1)
                ->missing('users.data.0.password'));

        $this->actingAs($this->admin)
            ->getJson(route('admin.users.show', $this->owner))
            ->assertOk()
            ->assertJsonPath('workspaces.0.name', 'Acme')
            ->assertJsonPath('workspaces.0.role', 'owner')
            ->assertJsonPath('workspaces.0.projects_count', 1)
            ->assertJsonPath('workspaces.0.all_projects', true);
    }

    public function test_admins_can_hand_out_a_reset_link_or_email_without_seeing_a_password(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.users.password-reset-email', $this->owner))
            ->assertSessionHas('success');

        $this->actingAs($this->admin)
            ->postJson(route('admin.users.password-reset-link', $this->owner))
            ->assertOk()
            ->assertJsonStructure(['url', 'expires_in_minutes']);

        Notification::assertSentTo($this->owner, ResetPassword::class);
        $this->assertSame(2, AdminAuditLog::where('target_id', $this->owner->id)->count());
    }

    public function test_admins_can_create_accounts_that_set_their_own_password(): void
    {
        $this->actingAs($this->admin)
            ->postJson(route('admin.users.store'), ['name' => 'New Person', 'email' => 'new@example.com'])
            ->assertOk()
            ->assertJsonStructure(['url', 'message']);

        $user = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_suspended_users_are_signed_out_and_cannot_sign_in(): void
    {
        $member = $this->member();

        $this->actingAs($this->admin)->post(route('admin.users.suspend', $member))->assertSessionHas('success');
        $this->assertTrue($member->fresh()->isSuspended());

        $this->actingAs($member->fresh())->get(route('workspaces.index'))->assertRedirect(route('login'));
        $this->assertGuest();

        $this->post('/login', ['email' => $member->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->actingAs($this->admin)->post(route('admin.users.unsuspend', $member));
        auth()->guard('web')->logout();
        $this->post('/login', ['email' => $member->email, 'password' => 'password']);
        $this->assertAuthenticatedAs($member->fresh());
    }

    public function test_admins_cannot_lock_themselves_or_the_instance_out(): void
    {
        $this->actingAs($this->admin)->post(route('admin.users.suspend', $this->admin))->assertSessionHas('error');
        $this->actingAs($this->admin)->delete(route('admin.users.admin.revoke', $this->admin))->assertSessionHas('error');
        $this->actingAs($this->admin)->delete(route('admin.users.destroy', $this->admin), ['confirm_email' => $this->admin->email])->assertSessionHas('error');

        $this->assertFalse($this->admin->fresh()->isSuspended());
        $this->assertTrue($this->admin->fresh()->isInstanceAdmin());
    }

    public function test_admin_role_can_be_granted_and_revoked(): void
    {
        $this->actingAs($this->admin)->post(route('admin.users.admin.grant', $this->owner));
        $this->assertTrue($this->owner->isInstanceAdmin());

        $this->actingAs($this->admin)->delete(route('admin.users.admin.revoke', $this->owner));
        $this->assertFalse($this->owner->fresh()->isInstanceAdmin());
    }

    public function test_users_who_own_workspaces_cannot_be_deleted(): void
    {
        $this->actingAs($this->admin)
            ->delete(route('admin.users.destroy', $this->owner), ['confirm_email' => $this->owner->email])
            ->assertSessionHas('error');

        $this->assertModelExists($this->owner);
        $this->assertModelExists($this->workspace);
    }

    public function test_deleting_a_user_keeps_what_they_built_in_other_peoples_workspaces(): void
    {
        $member = $this->member();
        $board = Board::factory()->create(['project_id' => $this->project->id, 'created_by' => $member->id]);
        $item = Item::factory()->create(['board_id' => $board->id, 'created_by' => $member->id]);
        $comment = $item->comments()->create(['user_id' => $member->id, 'body' => 'Mine']);
        $reply = $item->comments()->create(['user_id' => $this->owner->id, 'body' => 'Reply', 'parent_id' => $comment->id]);

        $this->actingAs($this->admin)
            ->delete(route('admin.users.destroy', $member), ['confirm_email' => 'wrong@example.com'])
            ->assertSessionHas('error');
        $this->assertModelExists($member);

        $this->actingAs($this->admin)
            ->delete(route('admin.users.destroy', $member), ['confirm_email' => $member->email])
            ->assertRedirect(route('admin.users.index'));

        $this->assertModelMissing($member);
        $this->assertSame($this->owner->id, $board->fresh()->created_by);
        $this->assertSame($this->owner->id, $item->fresh()->created_by);
        $this->assertDatabaseMissing('comments', ['id' => $comment->id]);
        $this->assertNull($reply->fresh()->parent_id);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'user.deleted', 'target_label' => "{$member->name} <{$member->email}>"]);
    }
}
