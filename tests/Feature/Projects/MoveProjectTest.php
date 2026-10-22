<?php

namespace Tests\Feature\Projects;

use App\Models\Board;
use App\Models\Helpdesk;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\Page;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

class MoveProjectTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    protected User $admin;

    protected Workspace $target;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        $this->admin = User::find($this->workspace->owner_id);
        $this->target = Workspace::factory()->create(['owner_id' => $this->admin->id, 'name' => 'Agency']);
        $this->target->addUser($this->admin, 'admin');
    }

    private function move(array $data = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->post(route('projects.move', [$this->workspace->slug, $this->project->slug]), $data + [
            'target_workspace_id' => $this->target->id,
            'confirm_name' => $this->project->name,
        ]);
    }

    public function test_project_moves_with_its_content_and_members_in_the_target_keep_access(): void
    {
        $this->target->addUser($this->editor, 'member');
        $item = $this->item();
        $sibling = $this->item();
        $page = Page::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id]);
        $helpdesk = Helpdesk::create(['project_id' => $this->project->id, 'name' => 'Support', 'slug' => 'support', 'created_by' => $this->admin->id]);
        $label = $this->project->labels()->create(['name' => 'Bug', 'color' => '#f00']);

        $otherProject = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->admin->id]);
        $outside = Item::factory()->create(['board_id' => Board::factory()->create(['project_id' => $otherProject->id, 'created_by' => $this->admin->id])->id, 'created_by' => $this->admin->id]);
        $internal = ItemLink::create(['source_item_id' => $item->id, 'target_item_id' => $sibling->id, 'link_type' => 'relates_to', 'created_by' => $this->admin->id]);
        $external = ItemLink::create(['source_item_id' => $outside->id, 'target_item_id' => $item->id, 'link_type' => 'blocks', 'created_by' => $this->admin->id]);

        $preview = $this->actingAs($this->admin)
            ->getJson(route('projects.move.preview', [$this->workspace->slug, $this->project->slug]).'?target_workspace_id='.$this->target->id)
            ->assertOk()->json();
        $this->assertSame([$this->editor->id], array_column($preview['keep'], 'id'));
        $this->assertSame([$this->viewer->id], array_column($preview['remove'], 'id'));
        $this->assertSame(1, $preview['external_links']);

        $this->move()->assertRedirect(route('projects.show', [$this->target->slug, $this->project->slug]));

        $project = $this->project->fresh();
        $this->assertSame($this->target->id, $project->workspace_id);
        $this->assertSame([$this->editor->id], $project->users()->pluck('users.id')->all());
        $this->assertTrue($this->editor->hasProjectAccess($project));
        $this->assertFalse($this->viewer->hasProjectAccess($project));

        // Content still hangs off the project.
        $this->assertSame($project->id, $this->board->fresh()->project_id);
        $this->assertSame($project->id, $page->fresh()->project_id);
        $this->assertSame($project->id, $helpdesk->fresh()->project_id);
        $this->assertSame($project->id, $label->fresh()->project_id);
        $this->assertModelExists($internal);
        $this->assertModelMissing($external);

        $this->actingAs($this->editor)->get(route('boards.show', [$this->target->slug, $project->slug, $this->board->slug]))->assertOk();
    }

    public function test_only_admins_of_both_workspaces_can_move(): void
    {
        $outsiderWorkspace = Workspace::factory()->create(['owner_id' => $this->editor->id]);
        $outsiderWorkspace->addUser($this->editor, 'admin');

        // Editor is no admin of the source workspace.
        $this->move(['target_workspace_id' => $outsiderWorkspace->id], $this->editor)->assertForbidden();

        // Admin isn't an admin (or member) of the editor's workspace.
        $this->move(['target_workspace_id' => $outsiderWorkspace->id])->assertForbidden();

        $this->target->users()->updateExistingPivot($this->admin->id, ['role' => 'member']);
        $this->target->update(['owner_id' => $this->editor->id]);
        $this->move()->assertForbidden();

        $this->assertSame($this->workspace->id, $this->project->fresh()->workspace_id);
    }

    public function test_key_must_be_free_and_slug_gets_a_suffix(): void
    {
        $this->move(['confirm_name' => 'wrong'])->assertSessionHasErrors('confirm_name');

        $clash = Project::factory()->create(['workspace_id' => $this->target->id, 'created_by' => $this->admin->id, 'key' => 'BRAI', 'slug' => 'other']);
        $this->move()->assertSessionHasErrors('target_workspace_id');
        $this->assertSame($this->workspace->id, $this->project->fresh()->workspace_id);

        $clash->update(['key' => 'OTHR', 'slug' => $this->project->slug]);
        $this->move()->assertRedirect();
        $this->assertSame($this->project->slug.'-2', $this->project->fresh()->slug);
    }
}
