<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminWorkspaceTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdmin();
    }

    public function test_the_list_does_not_clobber_the_shared_sidebar_workspaces_prop(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.workspaces.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Workspaces')
                ->where('managedWorkspaces.data.0.name', 'Acme')
                ->where('managedWorkspaces.data.0.projects_count', 1)
                ->where('workspaces', []));
    }

    public function test_ownership_can_be_transferred_to_an_existing_member(): void
    {
        $member = $this->member('editor');

        $this->actingAs($this->admin)
            ->getJson(route('admin.workspaces.members', $this->workspace->id))
            ->assertOk()
            ->assertJsonCount(2, 'members');

        $this->actingAs($this->admin)
            ->post(route('admin.workspaces.transfer', $this->workspace->id), ['user_id' => $member->id])
            ->assertSessionHas('success');

        $this->assertSame($member->id, $this->workspace->fresh()->owner_id);
        $this->assertSame('admin', $member->workspaceRole($this->workspace));
        $this->assertSame('admin', $this->owner->workspaceRole($this->workspace));
    }

    public function test_ownership_cannot_go_to_an_outsider(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.workspaces.transfer', $this->workspace->id), ['user_id' => $outsider->id])
            ->assertSessionHas('error');

        $this->assertSame($this->owner->id, $this->workspace->fresh()->owner_id);
    }

    public function test_deleting_a_workspace_needs_its_exact_name(): void
    {
        $this->actingAs($this->admin)
            ->delete(route('admin.workspaces.destroy', $this->workspace->id), ['confirm_name' => 'acme'])
            ->assertSessionHas('error');
        $this->assertModelExists($this->workspace);

        $this->actingAs($this->admin)
            ->delete(route('admin.workspaces.destroy', $this->workspace->id), ['confirm_name' => 'Acme'])
            ->assertRedirect(route('admin.workspaces.index'));

        $this->assertSoftDeleted($this->workspace);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'workspace.deleted', 'target_label' => 'Acme']);
    }
}
