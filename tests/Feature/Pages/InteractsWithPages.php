<?php

namespace Tests\Feature\Pages;

use App\Models\Page;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;

/**
 * A workspace + project with one user per role, shared by the page feature tests.
 */
trait InteractsWithPages
{
    protected Workspace $workspace;

    protected Project $project;

    protected User $author;

    protected User $editor;

    protected User $viewer;

    protected User $admin;

    protected User $outsider;

    protected function setUpPages(): void
    {
        $this->withoutVite();

        $owner = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $this->workspace->addUser($owner, 'admin');
        $this->project = Project::factory()->create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $owner->id,
        ]);

        $this->author = $this->projectMember('editor');
        $this->editor = $this->projectMember('editor');
        $this->viewer = $this->projectMember('viewer');
        $this->admin = $this->projectMember('admin');
        $this->outsider = User::factory()->create();
    }

    protected function projectMember(string $projectRole): User
    {
        $user = User::factory()->create();
        $this->workspace->addUser($user, 'member');
        $this->project->users()->attach($user->id, ['role' => $projectRole]);

        return $user;
    }

    protected function draftPage(array $attributes = []): Page
    {
        return Page::factory()->create($attributes + [
            'project_id' => $this->project->id,
            'created_by' => $this->author->id,
            'content' => $this->html('<p>First draft</p>'),
        ]);
    }

    protected function publishedPage(array $attributes = []): Page
    {
        return Page::factory()->published()->create($attributes + [
            'project_id' => $this->project->id,
            'created_by' => $this->author->id,
            'name' => 'Runbook',
            'content' => $this->html('<p>Live text</p>'),
        ]);
    }

    /**
     * @return array{type: string, html: string}
     */
    protected function html(string $html): array
    {
        return ['type' => 'lexical', 'html' => $html];
    }

    /**
     * @param  array<int, mixed>  $extra
     */
    protected function pageRoute(string $name, Page $page, array $extra = []): string
    {
        return route($name, [$this->workspace->slug, $this->project->slug, $page->uuid, ...$extra]);
    }
}
