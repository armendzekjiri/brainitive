<?php

namespace Tests\Feature;

use App\Models\Board;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * e2e:prune clears the e2e suite's data on dev.brainitive.app before each run (BRAI-96): only
 *
 * @example.test users and what they own — never a real person's data.
 */
class E2ePruneTest extends TestCase
{
    use RefreshDatabase;

    private function workspaceFor(User $owner, string $name): Workspace
    {
        $workspace = Workspace::create(['name' => $name, 'owner_id' => $owner->id]);
        $workspace->addUser($owner, 'admin');
        $project = Project::factory()->create(['workspace_id' => $workspace->id, 'created_by' => $owner->id]);
        Board::factory()->create(['project_id' => $project->id, 'created_by' => $owner->id]);

        return $workspace;
    }

    public function test_it_removes_the_test_users_and_their_data_and_keeps_everyone_else(): void
    {
        $real = User::factory()->create(['email' => 'armend@example.com']);
        $realWorkspace = $this->workspaceFor($real, 'Real work');
        $tester = User::factory()->create(['email' => 'e2e-owner@example.test']);
        $invitee = User::factory()->create(['email' => 'invitee-x1@example.test']);
        $testWorkspace = $this->workspaceFor($tester, 'E2E Workspace');
        $testWorkspace->addUser($invitee, 'member');

        $this->artisan('e2e:prune', ['--force' => true])->assertSuccessful();

        $this->assertSame(['armend@example.com'], User::pluck('email')->all());
        $this->assertNull(Workspace::withTrashed()->find($testWorkspace->id));
        $this->assertSame(0, Project::where('workspace_id', $testWorkspace->id)->count());
        $this->assertNotNull($realWorkspace->fresh());
        $this->assertSame(1, Project::where('workspace_id', $realWorkspace->id)->count());
    }

    public function test_it_refuses_when_a_test_user_is_in_a_real_workspace(): void
    {
        $real = User::factory()->create(['email' => 'armend@example.com']);
        $realWorkspace = $this->workspaceFor($real, 'Real work');
        $tester = User::factory()->create(['email' => 'e2e-member@example.test']);
        $realWorkspace->addUser($tester, 'member');

        $this->artisan('e2e:prune', ['--force' => true])->assertFailed();
        $this->assertSame(2, User::count());
    }

    public function test_it_needs_force_and_never_runs_in_production(): void
    {
        User::factory()->create(['email' => 'e2e-owner@example.test']);

        $this->artisan('e2e:prune')->assertFailed();

        $this->app['env'] = 'production';
        $this->artisan('e2e:prune', ['--force' => true])->assertFailed();
        $this->assertSame(1, User::count());
    }
}
