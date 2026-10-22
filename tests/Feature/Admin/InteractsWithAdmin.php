<?php

namespace Tests\Feature\Admin;

use App\Models\InstanceAdmin;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;

/**
 * An instance admin plus another user's workspace the admin is *not* a member of.
 */
trait InteractsWithAdmin
{
    protected User $admin;

    protected User $owner;

    protected Workspace $workspace;

    protected Project $project;

    protected function setUpAdmin(): void
    {
        $this->withoutVite();

        $this->admin = User::factory()->create();
        InstanceAdmin::create(['user_id' => $this->admin->id]);

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->owner->id, 'name' => 'Acme']);
        $this->workspace->addUser($this->owner, 'admin');
        $this->project = Project::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->owner->id,
        ]);
    }

    protected function member(string $workspaceRole = 'member'): User
    {
        $user = User::factory()->create();
        $this->workspace->addUser($user, $workspaceRole);
        $this->project->users()->attach($user->id, ['role' => 'editor']);

        return $user;
    }
}
