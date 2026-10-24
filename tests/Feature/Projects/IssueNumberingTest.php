<?php

namespace Tests\Feature\Projects;

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The first issue of a blank project is KEY-1, not KEY-2 (BRAI-97): next_item_number holds the
 * last number used and starts at 0.
 */
class IssueNumberingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_blank_project_starts_at_key_1(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Acme', 'owner_id' => $owner->id]);
        $workspace->addUser($owner, 'admin');

        $this->actingAs($owner)->post(route('projects.store', $workspace), ['name' => 'Website', 'key' => 'WEB'])->assertRedirect();
        $project = Project::where('key', 'WEB')->firstOrFail();

        $this->assertSame(['WEB-1', 'WEB-2'], [$project->getNextItemKey(), $project->getNextItemKey()]);
    }
}
