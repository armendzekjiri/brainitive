<?php

namespace Tests\Feature\Sidebar;

use App\Models\Board;
use App\Models\Helpdesk;
use App\Models\Item;
use App\Models\Page;
use App\Models\Project;
use App\Models\TaskList;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Customisable sidebar (BRAI-106): users.sidebar_preferences via PATCH sidebar.preferences,
 * the shared prop, and the project page's "Recently updated" / members payload.
 */
class SidebarPreferencesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $editor;

    private Workspace $workspace;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->owner->id]);
        $this->workspace->addUser($this->owner, 'admin');
        $this->project = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->owner->id, 'key' => 'WEB']);

        $this->editor = User::factory()->create();
        $this->workspace->addUser($this->editor, 'member');
        $this->project->users()->attach($this->editor->id, ['role' => 'editor']);
    }

    private function save(User $user, array $data)
    {
        return $this->actingAs($user)->patchJson(route('sidebar.preferences'), $data);
    }

    public function test_defaults_are_shared_when_nothing_is_saved(): void
    {
        $this->actingAs($this->editor)->get(route('projects.show', [$this->workspace, $this->project]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('sidebarPreferences.hidden_nav', [])
                ->where('sidebarPreferences.projects', []));

        $this->assertNull($this->editor->fresh()->sidebar_preferences);
    }

    public function test_hiding_top_level_entries_is_saved_and_shared(): void
    {
        $this->save($this->editor, ['hidden_nav' => ['calendar', 'snippets', 'calendar']])
            ->assertOk()
            ->assertJsonPath('sidebarPreferences.hidden_nav', ['calendar', 'snippets']);

        $this->assertSame(['calendar', 'snippets'], $this->editor->fresh()->sidebar_preferences['hidden_nav']);

        $this->actingAs($this->editor)->get(route('projects.show', [$this->workspace, $this->project]))
            ->assertInertia(fn (Assert $page) => $page->where('sidebarPreferences.hidden_nav', ['calendar', 'snippets']));
    }

    public function test_home_inbox_and_unknown_entries_cannot_be_hidden(): void
    {
        foreach (['home', 'inbox', 'nope'] as $key) {
            $this->save($this->editor, ['hidden_nav' => [$key]])->assertJsonValidationErrors('hidden_nav.0');
        }
        $this->save($this->editor, ['hidden_nav' => 'calendar'])->assertJsonValidationErrors('hidden_nav');
        $this->assertNull($this->editor->fresh()->sidebar_preferences);
    }

    public function test_project_groups_and_pinned_only_merge_with_the_defaults(): void
    {
        $this->save($this->editor, ['project' => ['id' => $this->project->id, 'groups' => ['lists' => false]]])->assertOk();
        $this->save($this->editor, ['project' => ['id' => $this->project->id, 'pinned_only' => true]])
            ->assertOk()
            ->assertJsonPath("sidebarPreferences.projects.{$this->project->id}.groups.lists", false)
            ->assertJsonPath("sidebarPreferences.projects.{$this->project->id}.groups.boards", true)
            ->assertJsonPath("sidebarPreferences.projects.{$this->project->id}.pinned_only", true)
            ->assertJsonPath("sidebarPreferences.projects.{$this->project->id}.pins", []);

        // going back to the defaults stores nothing
        $this->save($this->editor, ['project' => ['id' => $this->project->id, 'groups' => ['lists' => true], 'pinned_only' => false]])->assertOk();
        $this->assertNull($this->editor->fresh()->sidebar_preferences);
    }

    public function test_health_workload_and_releases_can_be_hidden_one_by_one(): void
    {
        $base = "sidebarPreferences.projects.{$this->project->id}.groups";

        $this->save($this->editor, ['project' => ['id' => $this->project->id, 'groups' => ['health' => false, 'releases' => false]]])
            ->assertOk()
            ->assertJsonPath("{$base}.health", false)
            ->assertJsonPath("{$base}.releases", false)
            ->assertJsonPath("{$base}.workload", true)
            ->assertJsonPath("{$base}.boards", true);

        $this->save($this->editor, ['project' => ['id' => $this->project->id, 'groups' => ['health' => true]]])
            ->assertOk()
            ->assertJsonPath("{$base}.health", true)
            ->assertJsonPath("{$base}.releases", false);

        $this->actingAs($this->editor)->get(route('projects.show', [$this->workspace, $this->project]))
            ->assertInertia(fn (Assert $page) => $page
                ->where("{$base}.releases", false)
                ->where("{$base}.workload", true));

        $this->save($this->editor, ['project' => ['id' => $this->project->id, 'groups' => ['releases' => true]]])->assertOk();
        $this->assertNull($this->editor->fresh()->sidebar_preferences);
    }

    public function test_preferences_saved_before_the_project_tools_existed_show_them(): void
    {
        // a value stored before BRAI-286 has no health / workload / releases keys
        $this->editor->forceFill(['sidebar_preferences' => [
            'hidden_nav' => [],
            'projects' => [$this->project->id => ['groups' => ['boards' => false, 'lists' => true], 'pins' => [], 'pinned_only' => false]],
        ]])->save();
        $base = "sidebarPreferences.projects.{$this->project->id}.groups";

        $this->actingAs($this->editor)->get(route('projects.show', [$this->workspace, $this->project]))
            ->assertInertia(fn (Assert $page) => $page
                ->where("{$base}.boards", false)
                ->where("{$base}.health", true)
                ->where("{$base}.workload", true)
                ->where("{$base}.releases", true));

        // hiding a tool keeps the old choice
        $this->save($this->editor, ['project' => ['id' => $this->project->id, 'groups' => ['workload' => false]]])
            ->assertOk()
            ->assertJsonPath("{$base}.boards", false)
            ->assertJsonPath("{$base}.workload", false);
    }

    public function test_project_updates_are_validated(): void
    {
        $id = $this->project->id;
        $this->save($this->editor, ['project' => ['id' => $id, 'groups' => ['calendar' => false]]])->assertJsonValidationErrors('project.groups');
        $this->save($this->editor, ['project' => ['id' => $id, 'groups' => ['lists' => 'maybe']]])->assertJsonValidationErrors('project.groups.lists');
        $this->save($this->editor, ['project' => ['id' => $id, 'groups' => ['health' => 'maybe']]])->assertJsonValidationErrors('project.groups.health');
        $this->save($this->editor, ['project' => ['id' => $id, 'pins' => [['type' => 'issue', 'id' => 1]]]])->assertJsonValidationErrors('project.pins.0.type');
        $this->save($this->editor, ['project' => ['id' => $id, 'pins' => [['type' => 'board', 'id' => 'x']]]])->assertJsonValidationErrors('project.pins.0.id');
        $this->save($this->editor, ['project' => ['groups' => ['lists' => false]]])->assertJsonValidationErrors('project.id');
        $this->save($this->editor, ['project' => ['id' => $id, 'evil' => true]])->assertJsonValidationErrors('project');
    }

    public function test_a_project_the_user_cannot_open_is_rejected(): void
    {
        $other = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->owner->id]);

        $this->save($this->editor, ['project' => ['id' => $other->id, 'pinned_only' => true]])->assertJsonValidationErrors('project.id');
        $this->save($this->editor, ['project' => ['id' => 999999, 'pinned_only' => true]])->assertJsonValidationErrors('project.id');
        $this->assertNull($this->editor->fresh()->sidebar_preferences);
    }

    public function test_pins_keep_their_order_and_drop_items_the_user_cannot_see(): void
    {
        $board = Board::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->owner->id]);
        $published = Page::factory()->published()->create(['project_id' => $this->project->id, 'created_by' => $this->owner->id]);
        $ownersDraft = Page::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->owner->id]);
        $foreignProject = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->owner->id]);
        $foreignBoard = Board::factory()->create(['project_id' => $foreignProject->id, 'created_by' => $this->owner->id]);

        $this->save($this->editor, ['project' => ['id' => $this->project->id, 'pins' => [
            ['type' => 'page', 'id' => $published->id],
            ['type' => 'board', 'id' => $board->id],
            ['type' => 'page', 'id' => $ownersDraft->id],       // someone else's draft
            ['type' => 'board', 'id' => $foreignBoard->id],     // another project
            ['type' => 'list', 'id' => 424242],                 // does not exist
            ['type' => 'board', 'id' => $board->id],            // duplicate
        ]]])
            ->assertOk()
            ->assertJsonPath("sidebarPreferences.projects.{$this->project->id}.pins", [
                ['type' => 'page', 'id' => $published->id],
                ['type' => 'board', 'id' => $board->id],
            ]);

        // the owner (project admin) may pin the draft
        $this->save($this->owner, ['project' => ['id' => $this->project->id, 'pins' => [['type' => 'page', 'id' => $ownersDraft->id]]]])
            ->assertJsonPath("sidebarPreferences.projects.{$this->project->id}.pins", [['type' => 'page', 'id' => $ownersDraft->id]]);
    }

    public function test_projects_the_user_lost_access_to_are_pruned_on_save(): void
    {
        $second = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->owner->id]);
        $second->users()->attach($this->editor->id, ['role' => 'viewer']);

        $this->save($this->editor, ['project' => ['id' => $second->id, 'pinned_only' => true]])->assertOk();
        $second->users()->detach($this->editor->id);

        $this->save($this->editor, ['project' => ['id' => $this->project->id, 'groups' => ['pages' => false]]])
            ->assertOk()
            ->assertJsonMissingPath("sidebarPreferences.projects.{$second->id}")
            ->assertJsonPath("sidebarPreferences.projects.{$this->project->id}.groups.pages", false);
    }

    public function test_reset_restores_the_defaults(): void
    {
        $this->save($this->editor, ['hidden_nav' => ['calendar'], 'project' => ['id' => $this->project->id, 'pinned_only' => true]])->assertOk();
        $this->assertNotNull($this->editor->fresh()->sidebar_preferences);

        $this->save($this->editor, ['reset' => true])
            ->assertOk()
            ->assertJsonPath('sidebarPreferences.hidden_nav', []);
        $this->assertNull($this->editor->fresh()->sidebar_preferences);
    }

    public function test_guests_cannot_save_and_stored_garbage_is_normalised(): void
    {
        $this->patchJson(route('sidebar.preferences'), ['hidden_nav' => ['calendar']])->assertUnauthorized();

        $this->editor->forceFill(['sidebar_preferences' => [
            'hidden_nav' => ['calendar', 'home', 7],
            'projects' => [$this->project->id => ['groups' => ['lists' => false, 'bogus' => false], 'pins' => [['type' => 'x', 'id' => 1], ['type' => 'board', 'id' => 3]]], 'abc' => []],
        ]])->save();

        $this->actingAs($this->editor)->get(route('projects.show', [$this->workspace, $this->project]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('sidebarPreferences.hidden_nav', ['calendar'])
                ->where("sidebarPreferences.projects.{$this->project->id}.groups.lists", false)
                ->missing("sidebarPreferences.projects.{$this->project->id}.groups.bogus")
                ->where("sidebarPreferences.projects.{$this->project->id}.pins", [['type' => 'board', 'id' => 3]])
                ->where("sidebarPreferences.projects.{$this->project->id}.pinned_only", false)
                ->missing('sidebarPreferences.projects.abc'));
    }

    public function test_the_project_page_lists_recently_updated_content_and_members(): void
    {
        $quiet = Board::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->owner->id, 'name' => 'Quiet board']);
        $busy = Board::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->owner->id, 'name' => 'Busy board']);
        $list = TaskList::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->owner->id, 'name' => 'Errands']);
        $helpdesk = Helpdesk::create(['project_id' => $this->project->id, 'name' => 'Support', 'slug' => 'support', 'created_by' => $this->owner->id]);
        $doc = Page::factory()->published()->create(['project_id' => $this->project->id, 'created_by' => $this->owner->id, 'name' => 'Spec']);
        $draft = Page::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->owner->id, 'name' => 'Secret draft']);

        $old = now()->subDays(10);
        foreach ([$quiet, $busy, $list, $helpdesk] as $model) {
            $model->forceFill(['updated_at' => $old])->saveQuietly();
        }
        Page::whereKey($doc->id)->update(['updated_at' => now()->subDays(2)]);
        Page::whereKey($draft->id)->update(['updated_at' => now()]);
        // an issue changed today makes its board the most recent
        Item::factory()->create(['board_id' => $busy->id, 'project_id' => $this->project->id]);

        $this->actingAs($this->editor)->get(route('projects.show', [$this->workspace, $this->project]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Projects/Show')
                ->has('recentlyUpdated', 5)
                ->where('recentlyUpdated.0.type', 'board')
                ->where('recentlyUpdated.0.name', 'Busy board')
                ->where('recentlyUpdated.1.type', 'page')
                ->where('recentlyUpdated.1.name', 'Spec')
                ->where('recentlyUpdated.1.url', route('pages.show', [$this->workspace->slug, $this->project->slug, $doc->uuid]))
                ->where('recentlyUpdated.1.is_whiteboard', false)
                ->has('members', 1)
                ->where('members.0.name', $this->editor->name)
                ->missing('members.0.email'));
    }
}
