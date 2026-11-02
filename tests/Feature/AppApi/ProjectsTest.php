<?php

namespace Tests\Feature\AppApi;

use App\Models\Board;
use App\Models\Page;
use App\Models\Plan;
use App\Models\Project;
use App\Models\TaskList;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Entitlements\ConfigEntitlements;
use App\Support\Entitlements\Entitlements;
use Database\Seeders\ItemTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\TestCase;

/** App API v1 projects (BRAI-389): directory, project home, templates, create. */
class ProjectsTest extends TestCase
{
    use ActsAsAppClient;
    use ForgetsGuardsBetweenRequests;
    use RefreshDatabase;

    private Workspace $workspace;

    private User $admin;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->admin = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->admin->id]);
        $this->workspace->addUser($this->admin, 'admin');
        $this->editor = User::factory()->create();
        $this->workspace->addUser($this->editor, 'editor');
    }

    private function project(string $name, ?User $member = null, string $role = 'editor'): Project
    {
        $project = Project::factory()->create(['workspace_id' => $this->workspace->id, 'name' => $name, 'slug' => Str::slug($name), 'key' => strtoupper(substr(preg_replace('/\W/', '', $name), 0, 4))]);
        if ($member) {
            $project->addUser($member, $role);
        }

        return $project;
    }

    public function test_the_directory_lists_what_the_web_shows_each_role(): void
    {
        $mine = $this->project('Website', $this->editor);
        $other = $this->project('Accounting');

        $editorList = $this->getJson("/api/v1/workspaces/{$this->workspace->slug}/projects", $this->appHeaders($this->appToken($this->editor)))->assertOk();
        $editorList->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id)
            ->assertJsonPath('data.0.slug', 'website')
            ->assertJsonPath('data.0.workspace.slug', $this->workspace->slug)
            ->assertJsonPath('data.0.url', route('projects.show', [$this->workspace->slug, 'website']))
            ->assertJsonPath('next_cursor', null);
        $this->assertSame(
            ['id', 'uuid', 'slug', 'key', 'name', 'description', 'icon', 'color', 'workspace', 'url', 'created_at', 'updated_at'],
            array_keys($editorList->json('data.0')),
        );

        // workspace admins see every project, by name, a page at a time
        $first = $this->getJson("/api/v1/workspaces/{$this->workspace->slug}/projects?limit=1", $this->appHeaders($this->appToken($this->admin)))->assertOk();
        $first->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $other->id);
        $this->getJson("/api/v1/workspaces/{$this->workspace->slug}/projects?limit=1&cursor=".urlencode($first->json('next_cursor')), $this->appHeaders($this->appToken($this->admin)))
            ->assertOk()->assertJsonPath('data.0.id', $mine->id)->assertJsonPath('next_cursor', null);
    }

    public function test_the_project_home_lists_its_content_and_hides_drafts_of_others(): void
    {
        $project = $this->project('Website', $this->editor);
        $board = Board::factory()->create(['project_id' => $project->id, 'name' => 'Roadmap', 'slug' => 'roadmap']);
        TaskList::factory()->create(['project_id' => $project->id, 'name' => 'Launch checklist']);
        Plan::factory()->create(['project_id' => $project->id, 'name' => 'Q1 plan']);
        $folder = Page::factory()->create(['project_id' => $project->id, 'type' => 'folder', 'name' => 'Specs', 'status' => 'published']);
        $doc = Page::factory()->published()->create(['project_id' => $project->id, 'parent_id' => $folder->id, 'name' => 'API spec']);
        $board2 = Page::factory()->published()->create(['project_id' => $project->id, 'name' => 'Sketch', 'content' => ['type' => 'excalidraw', 'elements' => [], 'appState' => []]]);
        $ownDraft = Page::factory()->create(['project_id' => $project->id, 'name' => 'My draft', 'created_by' => $this->editor->id]);
        $foreignDraft = Page::factory()->create(['project_id' => $project->id, 'name' => 'Their draft', 'created_by' => $this->admin->id]);
        // published, but under somebody else's draft: the web tree never reaches it
        Page::factory()->published()->create(['project_id' => $project->id, 'parent_id' => $foreignDraft->id, 'name' => 'Hidden child']);

        $response = $this->getJson("/api/v1/projects/{$project->id}", $this->appHeaders($this->appToken($this->editor)))->assertOk();

        $response->assertJsonPath('data.id', $project->id)
            ->assertJsonPath('data.role', 'editor')
            ->assertJsonPath('data.member_count', 1)
            ->assertJsonPath('data.can.update', false)
            ->assertJsonPath('data.can.create_pages', true)
            ->assertJsonPath('data.can.create_issues', true)
            ->assertJsonPath('data.boards.0.id', $board->id)
            ->assertJsonPath('data.boards.0.url', route('boards.show', [$this->workspace->slug, 'website', 'roadmap']))
            ->assertJsonPath('data.task_lists.0.name', 'Launch checklist')
            ->assertJsonPath('data.plans.0.name', 'Q1 plan')
            ->assertJsonPath('data.helpdesks', [])
            ->assertJsonPath('data.lead_forms', []);

        $docs = collect($response->json('data.docs'))->keyBy('name');
        $this->assertEqualsCanonicalizing(['Specs', 'API spec', 'Sketch', 'My draft'], $docs->keys()->all());
        $this->assertSame('folder', $docs['Specs']['type']);
        $this->assertSame($folder->id, $docs['API spec']['parent_id']);
        $this->assertSame($doc->uuid, $docs['API spec']['uuid']);
        $this->assertTrue($docs['Sketch']['is_whiteboard']);
        $this->assertTrue($docs['My draft']['is_draft']);
        $this->assertSame($ownDraft->id, $docs['My draft']['id']);
        $this->assertSame('Specs', $response->json('data.docs.0.name'), 'folders come first');
        $this->assertStringNotContainsString('Their draft', $response->getContent());
        $this->assertNotContains('Hidden child', $docs->keys()->all());
        $this->assertStringNotContainsString('<p>', $response->getContent(), 'no page content');
        $this->assertNotEmpty($response->json('data.recently_updated'));
        $this->assertMatchesRegularExpression('/Z$/', $response->json('data.recently_updated.0.updated_at'));
        $this->assertNotNull($board2);
    }

    public function test_projects_the_user_cannot_open_are_404(): void
    {
        $notMine = $this->project('Accounting');
        $elsewhere = Project::factory()->create();
        $token = $this->appToken($this->editor);

        foreach ([$notMine->id, $elsewhere->id, 999999] as $id) {
            $this->getJson("/api/v1/projects/{$id}", $this->appHeaders($token))->assertNotFound()->assertJsonPath('code', 'not_found');
        }
        $this->getJson("/api/v1/workspaces/{$elsewhere->workspace->slug}/projects", $this->appHeaders($token))->assertNotFound();
        $this->getJson("/api/v1/workspaces/{$elsewhere->workspace->slug}/project-templates", $this->appHeaders($token))->assertNotFound();
        $this->getJson("/api/v1/projects/{$notMine->id}", $this->appHeaders($this->appToken($this->admin)))->assertOk();
    }

    public function test_creates_a_blank_project_like_the_web_form(): void
    {
        $response = $this->postJson("/api/v1/workspaces/{$this->workspace->slug}/projects", [
            'name' => 'Mobile app', 'key' => 'mob', 'description' => 'Native apps',
        ], $this->appHeaders($this->appToken($this->editor)))->assertCreated();

        $project = Project::where('workspace_id', $this->workspace->id)->where('key', 'MOB')->sole();
        $response->assertJsonPath('data.id', $project->id)->assertJsonPath('data.name', 'Mobile app')->assertJsonPath('data.key', 'MOB');
        $this->assertSame('admin', $this->editor->projectRole($project));
        $this->assertSame(['Main Board'], $project->boards()->pluck('name')->all());
    }

    public function test_creates_from_a_template_and_a_retry_returns_the_same_project(): void
    {
        $this->seed(ItemTypeSeeder::class);
        $token = $this->appToken($this->editor);

        $templates = $this->getJson("/api/v1/workspaces/{$this->workspace->slug}/project-templates", $this->appHeaders($token))->assertOk();
        $ids = array_column($templates->json('data'), 'id');
        $this->assertContains('builtin:software-development', $ids);
        $this->assertSame(['id', 'source', 'category', 'tags', 'name', 'description', 'icon', 'summary', 'version'], array_keys($templates->json('data.0')));

        $body = ['name' => 'Relaunch', 'key' => 'REL', 'template' => 'builtin:software-development', 'start_date' => '2026-11-02', 'request_id' => (string) Str::uuid()];
        $created = $this->postJson("/api/v1/workspaces/{$this->workspace->slug}/projects", $body, $this->appHeaders($token))->assertCreated();
        $project = Project::findOrFail($created->json('data.id'));
        $this->assertGreaterThan(0, $project->boards()->count());

        $this->postJson("/api/v1/workspaces/{$this->workspace->slug}/projects", $body, $this->appHeaders($token))
            ->assertOk()->assertJsonPath('data.id', $project->id);
        $this->assertSame(1, Project::where('workspace_id', $this->workspace->id)->where('key', 'REL')->count());

        $this->postJson("/api/v1/workspaces/{$this->workspace->slug}/projects", ['name' => 'X', 'key' => 'XX', 'template' => 'builtin:nope', 'start_date' => '2026-11-02'], $this->appHeaders($token))
            ->assertStatus(422)->assertJsonValidationErrors('template');
    }

    public function test_create_validates_authorizes_and_respects_the_plan(): void
    {
        $this->project('Taken', $this->editor);
        $editorToken = $this->appToken($this->editor);
        $url = "/api/v1/workspaces/{$this->workspace->slug}/projects";

        $this->postJson($url, ['name' => 'Taken', 'key' => 'k1'], $this->appHeaders($editorToken))
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors(['name', 'key']);
        $this->postJson($url, ['name' => 'X', 'key' => 'WAYTOOLONGKEY', 'template' => 'builtin:software-development'], $this->appHeaders($editorToken))
            ->assertStatus(422)->assertJsonValidationErrors(['key', 'start_date']);

        // a viewer may not create projects (403), an outsider doesn't learn the workspace exists (404),
        // and neither gets validation errors that would reveal taken names
        $viewer = User::factory()->create();
        $this->workspace->addUser($viewer, 'viewer');
        $this->postJson($url, ['name' => 'Taken'], $this->appHeaders($this->appToken($viewer)))->assertForbidden();
        $this->getJson("/api/v1/workspaces/{$this->workspace->slug}/project-templates", $this->appHeaders($this->appToken($viewer)))->assertForbidden();
        $this->postJson($url, ['name' => 'Taken'], $this->appHeaders($this->appToken(User::factory()->create())))->assertNotFound();

        $this->app->instance(Entitlements::class, new ConfigEntitlements(['enforced' => true, 'projects' => 1]));
        $this->postJson($url, ['name' => 'Second', 'key' => 'SEC'], $this->appHeaders($editorToken))
            ->assertStatus(402)->assertJsonPath('code', 'limit_reached')->assertJsonPath('limit.kind', 'projects');
        $this->assertFalse(Project::where('key', 'SEC')->exists());
    }

    public function test_tokens_and_verification_are_checked(): void
    {
        $project = $this->project('Website', $this->editor);
        $routes = [
            ['GET', '/api/v1/workspaces'],
            ['GET', "/api/v1/workspaces/{$this->workspace->slug}"],
            ['GET', "/api/v1/workspaces/{$this->workspace->slug}/projects"],
            ['GET', "/api/v1/workspaces/{$this->workspace->slug}/project-templates"],
            ['POST', "/api/v1/workspaces/{$this->workspace->slug}/projects"],
            ['GET', "/api/v1/projects/{$project->id}"],
        ];
        $passport = $this->passportToken($this->editor);

        $unverified = User::factory()->unverified()->create();
        $this->workspace->addUser($unverified, 'admin');
        $unverifiedToken = $this->appToken($unverified);

        foreach ($routes as [$method, $uri]) {
            $this->json($method, $uri)->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
            $this->json($method, $uri, [], $this->appHeaders($passport))->assertUnauthorized();
            // the web app needs a verified address for all workspace content, so does the API
            $this->json($method, $uri, [], $this->appHeaders($unverifiedToken))->assertForbidden()->assertJsonPath('code', 'forbidden');
        }
    }
}
