<?php

namespace Tests\Feature\Boards;

use App\Models\Board;
use App\Models\Item;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;

/**
 * A project with one board (To Do / In Progress / Done) and one user per role.
 */
trait InteractsWithBoards
{
    protected Workspace $workspace;

    protected Project $project;

    protected Board $board;

    protected User $editor;

    protected User $viewer;

    protected User $outsider;

    protected function setUpBoard(): void
    {
        $this->withoutVite();

        $owner = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $this->workspace->addUser($owner, 'admin');
        $this->project = Project::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $owner->id,
            'key' => 'BRAI',
        ]);
        $this->board = Board::factory()->create(['project_id' => $this->project->id, 'created_by' => $owner->id]);

        $this->editor = $this->projectMember('editor');
        $this->viewer = $this->projectMember('viewer');
        $this->outsider = User::factory()->create();
    }

    protected function projectMember(string $projectRole): User
    {
        $user = User::factory()->create();
        $this->workspace->addUser($user, 'member');
        $this->project->users()->attach($user->id, ['role' => $projectRole]);

        return $user;
    }

    protected function item(array $attributes = []): Item
    {
        return Item::factory()->create($attributes + [
            'board_id' => $this->board->id,
            'created_by' => $this->editor->id,
        ]);
    }

    protected function column(string $slug): int
    {
        return $this->board->columns()->where('slug', $slug)->value('id');
    }

    /**
     * @param  array<int, mixed>  $extra
     */
    protected function itemRoute(string $name, Item $item, array $extra = []): string
    {
        return route($name, [$this->workspace->slug, $this->project->slug, $this->board->slug, $item->id, ...$extra]);
    }
}
