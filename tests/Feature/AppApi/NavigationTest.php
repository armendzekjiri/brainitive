<?php

namespace Tests\Feature\AppApi;

use App\Models\Board;
use App\Models\Item;
use App\Models\Page;
use App\Models\Plan;
use App\Models\Project;
use App\Models\TaskList;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Sidebar\SidebarPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\TestCase;

/** App API v1 navigation (BRAI-391): the web sidebar of a workspace, pins, recent destinations. */
class NavigationTest extends TestCase
{
    use ActsAsAppClient;
    use ForgetsGuardsBetweenRequests;
    use RefreshDatabase;

    private Workspace $workspace;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['name' => 'Acme']);
        $this->workspace->addUser($this->user, 'editor');
        $this->project = Project::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Website', 'slug' => 'website']);
        $this->project->addUser($this->user, 'editor');
    }

    private function url(array $query = []): string
    {
        return '/api/v1/me/navigation?'.http_build_query(['workspace' => $this->workspace->slug, ...$query]);
    }

    public function test_returns_the_sidebar_of_the_workspace_with_the_users_layout(): void
    {
        $board = Board::factory()->create(['project_id' => $this->project->id, 'name' => 'Roadmap', 'slug' => 'roadmap']);
        $list = TaskList::factory()->create(['project_id' => $this->project->id, 'name' => 'Todo']);
        $plan = Plan::factory()->create(['project_id' => $this->project->id, 'name' => 'Q1']);
        $doc = Page::factory()->published()->create(['project_id' => $this->project->id, 'name' => 'Spec']);
        Project::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Secret']); // not a member

        SidebarPreferences::apply($this->user, [
            'hidden_nav' => ['timesheet', 'billing'],
            'project' => ['id' => $this->project->id, 'groups' => ['plans' => false], 'pins' => [['type' => 'page', 'id' => $doc->id], ['type' => 'board', 'id' => $board->id]]],
        ], $this->project);

        $response = $this->getJson($this->url(), $this->appHeaders($this->appToken($this->user)))->assertOk();

        $response->assertJsonPath('data.workspace.slug', $this->workspace->slug)
            ->assertJsonPath('data.workspace.role', 'editor')
            ->assertJsonPath('data.hidden_nav', ['timesheet', 'billing'])
            ->assertJsonCount(1, 'data.projects')
            ->assertJsonPath('data.projects.0.id', $this->project->id)
            ->assertJsonPath('data.projects.0.groups.plans', false)
            ->assertJsonPath('data.projects.0.groups.boards', true)
            ->assertJsonPath('data.projects.0.pinned_only', false)
            ->assertJsonPath('data.projects.0.pins.0.type', 'page')
            ->assertJsonPath('data.projects.0.pins.0.name', 'Spec')
            ->assertJsonPath('data.projects.0.pins.1.id', $board->id)
            ->assertJsonPath('data.projects.0.pins.1.url', route('boards.show', [$this->workspace->slug, 'website', 'roadmap']))
            ->assertJsonPath('data.projects.0.boards.0.name', 'Roadmap')
            ->assertJsonPath('data.projects.0.task_lists.0.id', $list->id)
            ->assertJsonPath('data.projects.0.plans.0.id', $plan->id)
            ->assertJsonPath('data.recent', []);

        $nav = $response->json('data.nav');
        $this->assertSame(['home', 'inbox'], array_slice($nav, 0, 2));
        $this->assertNotContains('timesheet', $nav);
        $this->assertContains('my_work', $nav);
        $this->assertStringNotContainsString('Secret', $response->getContent());
    }

    public function test_workspace_admins_see_every_project_and_outsiders_get_404(): void
    {
        $admin = User::factory()->create();
        $this->workspace->addUser($admin, 'admin');
        Project::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Another']);

        $this->getJson($this->url(), $this->appHeaders($this->appToken($admin)))->assertOk()->assertJsonCount(2, 'data.projects');

        $outsider = $this->appToken(User::factory()->create());
        $this->getJson($this->url(), $this->appHeaders($outsider))->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->getJson('/api/v1/me/navigation?workspace=nope', $this->appHeaders($outsider))->assertNotFound();
        $this->getJson('/api/v1/me/navigation', $this->appHeaders($outsider))->assertStatus(422)->assertJsonValidationErrors('workspace');
    }

    public function test_recent_destinations_are_resolved_in_order_without_what_the_user_cannot_open(): void
    {
        $board = Board::factory()->create(['project_id' => $this->project->id, 'name' => 'Roadmap']);
        $item = Item::factory()->create(['board_id' => $board->id, 'project_id' => $this->project->id, 'title' => 'Fix login']);
        $hidden = Project::factory()->create(['workspace_id' => $this->workspace->id]);
        $foreignDraft = Page::factory()->create(['project_id' => $this->project->id, 'created_by' => User::factory()->create()->id]);

        $response = $this->getJson($this->url(['recent' => [
            "item:{$item->id}", "project:{$hidden->id}", "page:{$foreignDraft->id}", "board:{$board->id}", 'board:999999', "item:{$item->id}",
        ]]), $this->appHeaders($this->appToken($this->user)))->assertOk();

        $response->assertJsonCount(2, 'data.recent')
            ->assertJsonPath('data.recent.0.type', 'item')
            ->assertJsonPath('data.recent.0.title', 'Fix login')
            ->assertJsonPath('data.recent.1.type', 'board')
            ->assertJsonPath('data.recent.1.id', $board->id);
        $this->assertSame(['type', 'id', 'title', 'subtitle', 'url'], array_keys($response->json('data.recent.0')));

        $this->getJson($this->url(['recent' => ['folder:1']]), $this->appHeaders($this->appToken($this->user)))
            ->assertStatus(422)->assertJsonValidationErrors('recent.0');
        $this->getJson($this->url(['recent' => array_fill(0, 13, "board:{$board->id}")]), $this->appHeaders($this->appToken($this->user)))
            ->assertStatus(422)->assertJsonValidationErrors('recent');
    }

    public function test_put_pins_replaces_the_projects_pins_and_drops_what_the_user_cannot_open(): void
    {
        $board = Board::factory()->create(['project_id' => $this->project->id, 'name' => 'Roadmap']);
        $plan = Plan::factory()->create(['project_id' => $this->project->id, 'name' => 'Q1']);
        $foreignDraft = Page::factory()->create(['project_id' => $this->project->id, 'created_by' => User::factory()->create()->id]);
        $elsewhere = Board::factory()->create(); // another project
        $token = $this->appToken($this->user);

        $this->putJson('/api/v1/me/pins', [
            'project_id' => $this->project->id,
            'pins' => [['type' => 'plan', 'id' => $plan->id], ['type' => 'page', 'id' => $foreignDraft->id], ['type' => 'board', 'id' => $elsewhere->id], ['type' => 'board', 'id' => $board->id]],
            'pinned_only' => true,
        ], $this->appHeaders($token))->assertOk()
            ->assertJsonPath('data.project_id', $this->project->id)
            ->assertJsonPath('data.pinned_only', true)
            ->assertJsonCount(2, 'data.pins')
            ->assertJsonPath('data.pins.0.type', 'plan')
            ->assertJsonPath('data.pins.1.id', $board->id);

        // the web sidebar reads the same preferences
        $stored = SidebarPreferences::normalize($this->user->fresh()->sidebar_preferences)['projects'][(string) $this->project->id];
        $this->assertSame([['type' => 'plan', 'id' => $plan->id], ['type' => 'board', 'id' => $board->id]], $stored['pins']);
        $this->assertTrue($stored['pinned_only']);

        // [] unpins everything; pinned_only stays as it was when not sent
        $this->putJson('/api/v1/me/pins', ['project_id' => $this->project->id, 'pins' => []], $this->appHeaders($token))->assertOk()
            ->assertJsonPath('data.pins', [])->assertJsonPath('data.pinned_only', true);
    }

    public function test_put_pins_validates_and_hides_other_projects(): void
    {
        $token = $this->appToken($this->user);
        $hidden = Project::factory()->create(['workspace_id' => $this->workspace->id]);

        $this->putJson('/api/v1/me/pins', ['project_id' => $hidden->id, 'pins' => []], $this->appHeaders($token))->assertNotFound();
        $this->putJson('/api/v1/me/pins', ['project_id' => $this->project->id, 'pins' => [['type' => 'issue', 'id' => 1]]], $this->appHeaders($token))
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors('pins.0.type');
        $this->putJson('/api/v1/me/pins', ['project_id' => $this->project->id], $this->appHeaders($token))
            ->assertStatus(422)->assertJsonValidationErrors('pins');
        $this->putJson('/api/v1/me/pins', ['project_id' => $this->project->id, 'pins' => array_fill(0, 51, ['type' => 'board', 'id' => 1])], $this->appHeaders($token))
            ->assertStatus(422)->assertJsonValidationErrors('pins');
    }

    public function test_tokens_are_checked(): void
    {
        $passport = $this->passportToken($this->user);
        foreach ([['GET', $this->url()], ['PUT', '/api/v1/me/pins']] as [$method, $uri]) {
            $this->json($method, $uri)->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
            $this->json($method, $uri, [], $this->appHeaders($passport))->assertUnauthorized();
        }
    }
}
