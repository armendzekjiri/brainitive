<?php

namespace Tests\Feature\Security;

use App\Mcp\Servers\BrainitiveServer;
use App\Mcp\Tools\GetBoardItemTool;
use App\Mcp\Tools\UpdateBoardItemTool;
use App\Models\Item;
use App\Models\TaskList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Passport\Passport;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * Codex review, finding 6: someone removed from a workspace keeps no access to its projects — not through a
 * leftover project membership (web, App API, MCP, CLI, browser extension). There is no project-only role by design:
 * project members are always picked from the workspace's members (ProjectController::inviteMember, MCP
 * add_project_member, imports), and a project transfer drops members who aren't in the target workspace.
 */
class RemovedWorkspaceMemberTest extends TestCase
{
    use ActsAsAppClient;
    use InteractsWithBoards;
    use RefreshDatabase;

    private Item $issue;

    private TaskList $list;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        $this->usePassportKeys();
        Notification::fake();
        $this->issue = $this->item(['title' => 'Board secret']);
        $this->list = TaskList::create(['project_id' => $this->project->id, 'name' => 'Team list', 'slug' => 'team-list', 'created_by' => $this->editor->id]);
    }

    private function owner(): User
    {
        return User::findOrFail($this->workspace->owner_id);
    }

    /** Every surface refuses the user (reads and writes). */
    private function assertLockedOut(User $user): void
    {
        // web
        Auth::forgetGuards();
        $this->actingAs($user)->get($this->itemRoute('boards.items.show', $this->issue))->assertStatus(403);
        $this->actingAs($user)->post(route('boards.items.store', [$this->workspace->slug, $this->project->slug, $this->board->slug]), [
            'title' => 'web write', 'column_id' => $this->board->columns()->value('id'),
        ])->assertStatus(403);

        // App API
        Auth::forgetGuards();
        $headers = $this->appHeaders($this->appToken($user));
        $this->getJson("/api/v1/items/{$this->issue->id}", $headers)->assertNotFound();
        $this->patchJson("/api/v1/items/{$this->issue->id}", ['title' => 'api write'], $headers)->assertNotFound();

        // MCP
        BrainitiveServer::actingAs($user)->tool(GetBoardItemTool::class, $this->mcpArgs())->assertHasErrors();
        BrainitiveServer::actingAs($user)->tool(UpdateBoardItemTool::class, $this->mcpArgs(['title' => 'mcp write']))->assertHasErrors();

        // CLI (Passport, no scopes) and the browser extension (Passport `capture`)
        Auth::forgetGuards();
        Passport::actingAs($user);
        $this->assertContains($this->getJson("/api/cli/lists/{$this->list->slug}")->status(), [403, 404]);
        $this->assertContains($this->postJson("/api/cli/lists/{$this->list->slug}/items", ['title' => 'cli write'])->status(), [403, 404]);
        Auth::forgetGuards();
        Passport::actingAs($user, ['capture']);
        $this->postJson("/api/ext/v1/task-lists/{$this->list->id}/items", ['title' => 'ext write'])->assertForbidden();

        $this->assertSame('Board secret', $this->issue->fresh()->title);
        $this->assertSame(0, $this->list->items()->count());
        $this->assertSame(1, Item::count());
    }

    private function mcpArgs(array $extra = []): array
    {
        return ['workspace' => $this->workspace->slug, 'project' => $this->project->slug, 'board' => $this->board->slug, 'item_id' => $this->issue->id] + $extra;
    }

    public function test_removing_a_member_ends_their_project_memberships_and_all_access(): void
    {
        $this->actingAs($this->owner())->delete(route('workspaces.members.remove', [$this->workspace->slug, $this->editor->id]))->assertRedirect();

        $this->assertFalse($this->workspace->hasUser($this->editor));
        $this->assertSame(0, DB::table('project_user')->where('user_id', $this->editor->id)->count(), 'project memberships go with the workspace membership');
        $this->assertLockedOut($this->editor);
    }

    public function test_a_leftover_project_membership_without_the_workspace_grants_nothing(): void
    {
        // data from before the fix: only the workspace membership was removed
        DB::table('workspace_user')->where('workspace_id', $this->workspace->id)->where('user_id', $this->editor->id)->delete();
        $this->assertSame(1, DB::table('project_user')->where('user_id', $this->editor->id)->count());

        $this->assertLockedOut($this->editor);
        $this->assertNull($this->editor->projectRole($this->project));
        $this->assertFalse($this->editor->hasProjectAccess($this->project));
        $this->assertNotContains($this->project->id, $this->editor->adminProjectIds());
    }

    public function test_current_members_keep_their_access(): void
    {
        $this->actingAs($this->editor)->get($this->itemRoute('boards.items.show', $this->issue))->assertOk();
        $this->getJson("/api/v1/items/{$this->issue->id}", $this->appHeaders($this->appToken($this->editor)))->assertOk();
        BrainitiveServer::actingAs($this->editor)->tool(GetBoardItemTool::class, $this->mcpArgs())->assertOk();
        Auth::forgetGuards();
        Passport::actingAs($this->editor);
        $this->postJson("/api/cli/lists/{$this->list->slug}/items", ['title' => 'cli write'])->assertSuccessful();
        Auth::forgetGuards();
        Passport::actingAs($this->editor, ['capture']);
        $this->postJson("/api/ext/v1/task-lists/{$this->list->id}/items", ['title' => 'ext write'])->assertSuccessful();
        $this->assertSame('editor', $this->editor->projectRole($this->project));
    }

    public function test_the_prune_command_reports_by_default_and_removes_orphans_with_force(): void
    {
        DB::table('workspace_user')->where('workspace_id', $this->workspace->id)->where('user_id', $this->editor->id)->delete();
        $kept = DB::table('project_user')->where('user_id', $this->viewer->id)->count();

        $this->artisan('memberships:prune-orphans')->expectsOutputToContain('Dry run')->assertSuccessful();
        $this->assertSame(1, DB::table('project_user')->where('user_id', $this->editor->id)->count(), 'a dry run changes nothing');

        $this->artisan('memberships:prune-orphans', ['--force' => true])->expectsOutputToContain('Removed 1')->assertSuccessful();
        $this->assertSame(0, DB::table('project_user')->where('user_id', $this->editor->id)->count());
        $this->assertSame($kept, DB::table('project_user')->where('user_id', $this->viewer->id)->count(), 'current members stay');

        $this->artisan('memberships:prune-orphans')->expectsOutputToContain('No orphaned')->assertSuccessful();
    }
}
