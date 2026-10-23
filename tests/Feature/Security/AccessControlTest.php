<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\NotificationController;
use App\Models\Bookmark;
use App\Models\Page;
use App\Models\Project;
use App\Models\TaskList;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\MentionNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * Access-control hardening (security audit 2026-10-23, batch 2): every attack below must now be
 * rejected, and the legitimate path next to it must keep working.
 */
class AccessControlTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    private User $workspaceEditor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        Notification::fake();

        // editor of the workspace, but not a member of the project
        $this->workspaceEditor = User::factory()->create();
        $this->workspace->addUser($this->workspaceEditor, 'editor');
    }

    public function test_pages_only_bind_inside_their_own_project(): void
    {
        $this->item(['title' => 'Secret acquisition plan']);

        // attacker owns a page in their own workspace
        $attacker = User::factory()->create();
        $ownWorkspace = Workspace::factory()->create(['owner_id' => $attacker->id]);
        $ownWorkspace->addUser($attacker, 'admin');
        $ownProject = Project::factory()->create(['workspace_id' => $ownWorkspace->id, 'created_by' => $attacker->id]);
        $page = Page::factory()->create(['project_id' => $ownProject->id, 'created_by' => $attacker->id]);

        $foreign = [$this->workspace->slug, $this->project->slug, $page->uuid];
        $this->actingAs($attacker)->getJson(route('pages.whiteboard.issues', $foreign).'?q=Secret')->assertNotFound();
        $this->actingAs($attacker)->get(route('pages.show', $foreign))->assertNotFound();
        $this->actingAs($attacker)->post(route('pages.duplicate', $foreign))->assertNotFound();

        $this->actingAs($attacker)->get(route('pages.show', [$ownWorkspace->slug, $ownProject->slug, $page->uuid]))->assertOk();
    }

    public function test_workspace_editors_cannot_write_into_projects_they_are_not_members_of(): void
    {
        $taskList = TaskList::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id]);
        $column = $this->board->columns()->orderBy('position')->value('id');
        $p = [$this->workspace->slug, $this->project->slug];

        $this->actingAs($this->workspaceEditor)->post(route('boards.items.store', [...$p, $this->board->slug]), ['title' => 'Spam', 'column_id' => $column])->assertForbidden();
        $this->actingAs($this->workspaceEditor)->post(route('releases.store', $p), ['name' => 'v9'])->assertForbidden();
        $this->actingAs($this->workspaceEditor)->post(route('task-lists.items.store', [...$p, $taskList->slug]), ['title' => 'Spam'])->assertForbidden();
        $this->assertDatabaseMissing('items', ['title' => 'Spam']);

        // once they belong to the project, the workspace role applies again
        $this->project->users()->attach($this->workspaceEditor->id, ['role' => 'viewer']);
        $this->actingAs($this->workspaceEditor)->post(route('boards.items.store', [...$p, $this->board->slug]), ['title' => 'Allowed', 'column_id' => $column])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('items', ['title' => 'Allowed']);
    }

    public function test_mentions_only_reach_shared_members_and_only_link_into_the_app(): void
    {
        $url = route('notifications.notify-mention');
        $base = ['context_type' => 'page', 'context_title' => 'Roadmap'];

        $this->actingAs($this->editor)->postJson($url, $base + ['user_id' => $this->outsider->id, 'context_url' => '/workspaces'])->assertJsonValidationErrors('user_id');
        $this->actingAs($this->editor)->postJson($url, $base + ['user_id' => $this->viewer->id, 'context_url' => 'javascript:alert(1)'])->assertJsonValidationErrors('context_url');
        $this->actingAs($this->editor)->postJson($url, $base + ['user_id' => $this->viewer->id, 'context_url' => 'https://evil.example/login'])->assertJsonValidationErrors('context_url');
        $this->actingAs($this->editor)->postJson($url, $base + ['user_id' => $this->viewer->id, 'context_url' => '//evil.example'])->assertJsonValidationErrors('context_url');
        Notification::assertNothingSent();

        $this->actingAs($this->editor)->postJson($url, $base + ['user_id' => $this->viewer->id, 'context_url' => url('/workspaces/x/projects/y/pages/z?tab=1')])->assertOk();
        Notification::assertSentTo($this->viewer, MentionNotification::class, fn (MentionNotification $n) => $n->contextUrl === '/workspaces/x/projects/y/pages/z?tab=1');

        $this->assertNull(NotificationController::appPath('/\\evil.example'));
    }

    public function test_watchers_and_assignees_must_be_project_members(): void
    {
        $item = $this->item();

        // a viewer may watch themselves, but not add others
        $this->actingAs($this->viewer)->post($this->itemRoute('boards.items.watch', $item), ['user_id' => $this->viewer->id, 'watch' => true])->assertSessionHasNoErrors();
        $this->actingAs($this->viewer)->post($this->itemRoute('boards.items.watch', $item), ['user_id' => $this->editor->id, 'watch' => true])->assertForbidden();
        // nobody can add (and so look up) an account outside the project
        $this->actingAs($this->editor)->post($this->itemRoute('boards.items.watch', $item), ['user_id' => $this->outsider->id, 'watch' => true])->assertSessionHasErrors('user_id');
        $this->actingAs($this->editor)->post($this->itemRoute('boards.items.watch', $item), ['user_id' => $this->viewer->id, 'watch' => false])->assertSessionHasNoErrors();

        $column = $this->board->columns()->orderBy('position')->value('id');
        $this->actingAs($this->editor)->post(route('boards.items.store', [$this->workspace->slug, $this->project->slug, $this->board->slug]), ['title' => 'T', 'column_id' => $column, 'assigned_to' => $this->outsider->id])->assertSessionHasErrors('assigned_to');
        $this->actingAs($this->editor)->post(route('boards.items.store', [$this->workspace->slug, $this->project->slug, $this->board->slug]), ['title' => 'T2', 'column_id' => $column, 'assigned_to' => $this->viewer->id])->assertSessionHasNoErrors();
    }

    public function test_viewers_cannot_reschedule_items(): void
    {
        $item = $this->item(['due_date' => '2026-11-01']);

        $this->actingAs($this->viewer)->patch(route('calendar.reschedule'), ['type' => 'board_item', 'id' => $item->id, 'due_date' => '2030-05-05'])->assertForbidden();
        $this->assertStringStartsWith('2026-11-01', (string) $item->fresh()->due_date);

        $this->actingAs($this->editor)->patch(route('calendar.reschedule'), ['type' => 'board_item', 'id' => $item->id, 'due_date' => '2030-05-05'])->assertSessionHasNoErrors();
        $this->assertStringStartsWith('2030-05-05', (string) $item->fresh()->due_date);
    }

    public function test_member_profiles_only_show_work_from_projects_the_viewer_can_open(): void
    {
        $this->item(['title' => 'Layoff list Q4', 'assigned_to' => $this->editor->id]);

        $this->actingAs($this->workspaceEditor)->get(route('workspaces.members.profile', [$this->workspace->slug, $this->editor->id]))
            ->assertOk()->assertDontSee('Layoff list Q4');
        $this->actingAs($this->viewer)->get(route('workspaces.members.profile', [$this->workspace->slug, $this->editor->id]))
            ->assertOk()->assertSee('Layoff list Q4');
    }

    public function test_workspace_settings_never_reach_the_browser(): void
    {
        $this->workspace->update(['settings' => ['ai_endpoint' => 'http://10.0.0.5:11434', 'ai_api_key' => Crypt::encryptString('sk-secret')]]);

        $this->actingAs($this->viewer)->get(route('workspaces.show', $this->workspace->slug))
            ->assertOk()->assertDontSee('10.0.0.5', false)->assertDontSee('ai_api_key', false);
    }

    public function test_private_bookmark_collections_are_not_listed_to_other_members(): void
    {
        Bookmark::create(['workspace_id' => $this->workspace->id, 'name' => 'Private salaries', 'slug' => 'private-salaries', 'visibility' => 'private', 'created_by' => $this->editor->id]);
        Bookmark::create(['workspace_id' => $this->workspace->id, 'name' => 'Team links', 'slug' => 'team-links', 'visibility' => 'public', 'created_by' => $this->editor->id]);

        $this->actingAs($this->viewer)->get(route('bookmarks.index', $this->workspace->slug))->assertOk()->assertDontSee('Private salaries')->assertSee('Team links');
        $this->actingAs($this->editor)->get(route('bookmarks.index', $this->workspace->slug))->assertOk()->assertSee('Private salaries');
    }
}
