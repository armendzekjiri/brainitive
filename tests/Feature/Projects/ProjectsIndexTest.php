<?php

namespace Tests\Feature\Projects;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * /workspaces/{slug}/projects used to render a page that doesn't exist (Projects/Index): the workspace
 * home is where the projects are listed, so the old URL sends people there.
 */
class ProjectsIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_projects_url_redirects_members_to_the_workspace_home(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->addUser($owner, 'admin');
        $member = User::factory()->create();
        $workspace->addUser($member, 'member');

        foreach ([$owner, $member] as $user) {
            $this->actingAs($user)->get(route('projects.index', $workspace))
                ->assertRedirect(route('workspaces.show', $workspace));
        }
    }

    public function test_outsiders_and_guests_are_not_redirected_into_the_workspace(): void
    {
        $workspace = Workspace::factory()->create();

        $this->actingAs(User::factory()->create())->get(route('projects.index', $workspace))->assertForbidden();
        auth()->logout();
        $this->get(route('projects.index', $workspace))->assertRedirect(route('login'));
    }
}
