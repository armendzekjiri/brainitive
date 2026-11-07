<?php

namespace Tests\Feature\ApiKeys;

use App\Models\ApiKey;
use App\Models\Board;
use App\Models\Item;
use App\Models\Project;
use App\Models\SnippetCollection;
use App\Models\User;
use App\Models\Workspace;
use App\Support\ApiKeys\ApiKeyAreas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\ApiKeys\Concerns\UsesApiKeys;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * Developer API (v1.52.0): what a personal API key may do — areas × None / Read / Read + write and the delete
 * flag (403 `key_ability_denied`), its scope (all / workspaces / projects / boards: single resources 403
 * `key_scope_denied` naming only places the user can open, lists filtered), the workspace switch (403
 * `workspace_api_keys_disabled`), ids in bodies (ApiKeyGate), the per-key rate limits — and the policies still
 * decide as the user.
 */
class ApiKeyAuthorizationTest extends TestCase
{
    use ActsAsAppClient;
    use ForgetsGuardsBetweenRequests;
    use InteractsWithBoards;
    use RefreshDatabase;
    use UsesApiKeys;

    private Board $otherBoard;

    private Project $otherProject;

    private Workspace $otherWorkspace;

    private Project $foreignProject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        $this->workspace->update(['name' => 'Acme']);
        $this->project->update(['name' => 'ToolExpert']);
        $this->board->update(['name' => 'Main Board']);
        $owner = User::findOrFail($this->workspace->owner_id);

        $this->otherBoard = Board::factory()->create(['project_id' => $this->project->id, 'name' => 'Bugs', 'created_by' => $owner->id]);
        $this->otherProject = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $owner->id, 'name' => 'Website', 'key' => 'WEB']);
        $this->otherProject->users()->attach($this->editor->id, ['role' => 'editor']);

        $this->otherWorkspace = Workspace::factory()->create(['owner_id' => $owner->id, 'name' => 'Side Gig']);
        $this->otherWorkspace->addUser($owner, 'admin');
        $this->otherWorkspace->addUser($this->editor, 'admin');

        // a project of the same workspace the editor is NOT a member of
        $this->foreignProject = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $owner->id, 'name' => 'Secret Plans', 'key' => 'SEC']);
    }

    private function api(string $secret, string $uri)
    {
        return $this->getJson('/api/v1'.$uri, $this->keyHeaders($secret));
    }

    // ── What: areas × levels ────────────────────────────────────────────────────────────────

    public function test_read_keys_read_and_cannot_write(): void
    {
        $item = $this->item(['title' => 'Fix login']);
        $secret = $this->apiKey($this->editor, ['issues' => 'read']);

        $this->api($secret, "/items/{$item->id}")->assertOk()->assertJsonPath('data.title', 'Fix login');
        $this->patchJson("/api/v1/items/{$item->id}", ['title' => 'x'], $this->keyHeaders($secret))
            ->assertForbidden()
            ->assertJsonPath('code', 'key_ability_denied')
            ->assertJsonPath('message', 'This key can’t write issues & boards.');
        $this->postJson("/api/v1/items/{$item->id}/comments", ['body' => 'hi'], $this->keyHeaders($secret))
            ->assertForbidden()->assertJsonPath('code', 'key_ability_denied');
        $this->assertSame('Fix login', $item->fresh()->title);
    }

    public function test_write_keys_write_but_delete_needs_the_flag(): void
    {
        $item = $this->item(['title' => 'Fix login']);
        $secret = $this->apiKey($this->editor, ['issues' => 'write']);

        $this->patchJson("/api/v1/items/{$item->id}", ['title' => 'Fix sign-in'], $this->keyHeaders($secret))->assertOk();
        $this->assertSame('Fix sign-in', $item->fresh()->title);

        $this->deleteJson("/api/v1/items/{$item->id}", [], $this->keyHeaders($secret))
            ->assertForbidden()->assertJsonPath('code', 'key_ability_denied')
            ->assertJsonPath('message', 'This key can’t delete. Turn on “Delete” for the key to allow it.');
        $this->assertNotSoftDeleted($item);

        // unwatching is a write, not a delete
        $this->postJson("/api/v1/items/{$item->id}/watch", [], $this->keyHeaders($secret))->assertSuccessful();
        $this->deleteJson("/api/v1/items/{$item->id}/watch", [], $this->keyHeaders($secret))->assertSuccessful();

        $deleter = $this->apiKey($this->editor, ['issues' => 'write'], canDelete: true);
        $this->deleteJson("/api/v1/items/{$item->id}", [], $this->keyHeaders($deleter))->assertNoContent();
    }

    public function test_the_delete_flag_alone_doesnt_allow_writing(): void
    {
        $item = $this->item();
        $secret = $this->apiKey($this->editor, ['issues' => 'read'], canDelete: true);

        $this->deleteJson("/api/v1/items/{$item->id}", [], $this->keyHeaders($secret))->assertForbidden()->assertJsonPath('code', 'key_ability_denied');
    }

    public function test_every_area_is_closed_without_it_and_open_with_read(): void
    {
        $collection = SnippetCollection::create(['workspace_id' => $this->workspace->id, 'name' => 'Shell', 'created_by' => $this->editor->id]);
        $probes = [
            'issues' => "/projects/{$this->project->id}/boards",
            'docs' => "/projects/{$this->project->id}/pages",
            'plans' => "/projects/{$this->project->id}/plans",
            'work' => '/me/work',
            'time' => '/me/timer',
            'calendar' => '/calendar/connections',
            'helpdesk' => "/projects/{$this->project->id}/helpdesks",
            'library' => "/snippet-collections/{$collection->id}",
            'members' => "/projects/{$this->project->id}/members",
        ];
        $this->assertSame(ApiKeyAreas::keys(), array_keys($probes));

        foreach ($probes as $area => $uri) {
            $others = array_fill_keys(array_diff(ApiKeyAreas::keys(), [$area]), 'write');
            $this->api($this->apiKey($this->editor, $others), $uri)
                ->assertForbidden()->assertJsonPath('code', 'key_ability_denied');
            $this->api($this->apiKey($this->editor, [$area => 'read']), $uri)->assertOk();
        }
    }

    public function test_members_is_read_only_whatever_is_stored(): void
    {
        $this->assertSame('read', ApiKeyAreas::normalize(['members' => 'write'])['members']);
        $secret = $this->apiKey($this->editor, ['members' => 'write']);
        $this->api($secret, "/projects/{$this->project->id}/members")->assertOk();
        $this->postJson("/api/v1/workspaces/{$this->workspace->slug}/invitations", ['email' => 'a@example.com'], $this->keyHeaders($secret))
            ->assertForbidden()->assertJsonPath('code', 'key_endpoint_not_allowed');
    }

    public function test_the_policies_still_decide_as_the_user(): void
    {
        $item = $this->item(['title' => 'Fix login']);
        // a project viewer with a write key still can't edit
        $secret = $this->apiKey($this->viewer, ['issues' => 'write']);

        $this->api($secret, "/items/{$item->id}")->assertOk();
        $this->patchJson("/api/v1/items/{$item->id}", ['title' => 'x'], $this->keyHeaders($secret))
            ->assertForbidden()->assertJsonPath('code', 'forbidden');
    }

    public function test_no_area_routes_are_read_only(): void
    {
        $secret = $this->apiKey($this->editor, ['issues' => 'read']);
        $this->api($secret, '/me')->assertOk();
        $this->api($secret, '/workspaces')->assertOk();
        $this->api($secret, "/projects/{$this->project->id}")->assertOk();
    }

    // ── Where: scopes ───────────────────────────────────────────────────────────────────────

    public function test_all_scope_reaches_every_workspace_of_the_user(): void
    {
        $secret = $this->apiKey($this->editor, ['issues' => 'read']);

        $slugs = array_column($this->api($secret, '/workspaces')->assertOk()->json('data'), 'slug');
        sort($slugs);
        $expected = [$this->workspace->slug, $this->otherWorkspace->slug];
        sort($expected);
        $this->assertSame($expected, $slugs);

        // never beyond the user: a project they aren't a member of is the usual 404
        $this->api($secret, "/projects/{$this->foreignProject->id}")->assertNotFound();
        $this->api($secret, "/projects/{$this->foreignProject->id}/boards")->assertNotFound();
    }

    public function test_workspaces_scope(): void
    {
        $secret = $this->apiKey($this->editor, ['issues' => 'read', 'library' => 'read'], ApiKey::SCOPE_WORKSPACES, [$this->otherWorkspace->id]);
        $sideProject = Project::factory()->create(['workspace_id' => $this->otherWorkspace->id, 'name' => 'Side']);

        $this->assertSame([$this->otherWorkspace->slug], array_column($this->api($secret, '/workspaces')->json('data'), 'slug'));
        $this->assertSame([$this->otherWorkspace->slug], array_column($this->api($secret, '/me')->json('data.workspaces'), 'slug'));
        $this->api($secret, "/projects/{$sideProject->id}/boards")->assertOk();
        $this->api($secret, "/workspaces/{$this->otherWorkspace->slug}/snippet-collections")->assertOk();

        $this->api($secret, "/projects/{$this->project->id}")
            ->assertForbidden()
            ->assertJsonPath('code', 'key_scope_denied')
            ->assertJsonPath('message', 'This key has no access to workspace Acme.');
        $this->api($secret, "/workspaces/{$this->workspace->slug}/projects")
            ->assertForbidden()->assertJsonPath('code', 'key_scope_denied');
        // the user can't open it: the usual 404, no name
        $this->api($secret, "/projects/{$this->foreignProject->id}")->assertNotFound()->assertJsonMissing(['message' => 'This key has no access to project Secret Plans.']);
    }

    public function test_projects_scope_lists_and_single_resources(): void
    {
        $mine = $this->item(['title' => 'In scope', 'assigned_to' => $this->editor->id]);
        $otherBoard = Board::factory()->create(['project_id' => $this->otherProject->id]);
        $outside = Item::factory()->create(['board_id' => $otherBoard->id, 'title' => 'Out of scope', 'assigned_to' => $this->editor->id, 'created_by' => $this->editor->id]);
        $secret = $this->apiKey($this->editor, ['issues' => 'read', 'work' => 'read', 'library' => 'read'], ApiKey::SCOPE_PROJECTS, [$this->project->id]);

        $this->assertSame([$this->project->id], array_column($this->api($secret, "/workspaces/{$this->workspace->slug}/projects")->assertOk()->json('data'), 'id'));
        $this->api($secret, "/workspaces/{$this->workspace->slug}")->assertOk();
        $this->api($secret, "/items/{$mine->id}")->assertOk();
        $this->api($secret, "/items/{$outside->id}")->assertForbidden()
            ->assertJsonPath('message', 'This key has no access to project Website.');
        $this->api($secret, "/items/by-key/{$outside->key}")->assertForbidden()->assertJsonPath('code', 'key_scope_denied');

        // My Work: only the scope's issues
        $work = $this->api($secret, '/me/work?status=all')->assertOk()->json('data');
        $titles = array_column($work, 'title');
        $this->assertContains('In scope', $titles);
        $this->assertNotContains('Out of scope', $titles);

        // workspace-wide content needs whole workspaces
        $this->api($secret, "/workspaces/{$this->workspace->slug}/snippet-collections")
            ->assertForbidden()->assertJsonPath('code', 'key_scope_denied');
        $this->api($secret, "/workspaces/{$this->workspace->slug}/members")->assertForbidden();
    }

    public function test_boards_scope_limits_issues_to_the_boards_and_other_areas_to_their_projects(): void
    {
        $in = $this->item(['title' => 'On main', 'due_date' => now()->toDateString()]);
        $out = Item::factory()->create(['board_id' => $this->otherBoard->id, 'title' => 'On bugs', 'created_by' => $this->editor->id, 'due_date' => now()->toDateString()]);
        $secret = $this->apiKey($this->editor, ['issues' => 'write', 'docs' => 'read'], ApiKey::SCOPE_BOARDS, [$this->board->id]);

        $this->assertSame([$this->board->id], array_column($this->api($secret, "/projects/{$this->project->id}/boards")->assertOk()->json('data'), 'id'));
        $this->assertSame([$this->board->id], array_column($this->api($secret, "/projects/{$this->project->id}")->assertOk()->json('data.boards'), 'id'));
        $this->api($secret, "/boards/{$this->board->id}/items")->assertOk();
        $this->api($secret, "/boards/{$this->otherBoard->id}/items")->assertForbidden()
            ->assertJsonPath('message', 'This key has no access to board Bugs.');
        $this->api($secret, "/items/{$out->id}")->assertForbidden()->assertJsonPath('code', 'key_scope_denied');

        $from = now()->subDay()->toDateString();
        $to = now()->addDay()->toDateString();
        $dated = array_column($this->api($secret, "/projects/{$this->project->id}/items?from={$from}&to={$to}")->assertOk()->json('data'), 'title');
        $this->assertSame(['On main'], $dated);

        // other areas see the whole project
        $this->api($secret, "/projects/{$this->project->id}/pages")->assertOk();
        // project-wide aggregates and search: not for board keys
        $this->api($secret, "/projects/{$this->project->id}/health")->assertForbidden()->assertJsonPath('code', 'key_ability_denied');
        $this->api($this->apiKey($this->editor, ['issues' => 'read', 'plans' => 'read'], ApiKey::SCOPE_BOARDS, [$this->board->id]), "/projects/{$this->project->id}/health")
            ->assertForbidden()->assertJsonPath('code', 'key_scope_denied');
        $this->api($secret, '/search?q=main')->assertForbidden();

        // an id in the body outside the scope (ApiKeyGate): linking to an issue on another board
        $this->postJson("/api/v1/items/{$in->id}/links", ['target_id' => $out->id, 'type' => 'relates_to'], $this->keyHeaders($secret))
            ->assertUnprocessable()->assertJsonValidationErrors('target_id');
        $this->assertDatabaseCount('item_links', 0);
    }

    public function test_workspace_admins_can_turn_api_keys_off(): void
    {
        $secret = $this->apiKey($this->editor, ['issues' => 'read']);
        $this->workspace->forceFill(['api_keys_enabled' => false])->save();

        $this->api($secret, "/projects/{$this->project->id}")
            ->assertForbidden()->assertJsonPath('code', 'workspace_api_keys_disabled');
        $this->assertSame([$this->otherWorkspace->slug], array_column($this->api($secret, '/workspaces')->json('data'), 'slug'));
        // the apps are not affected
        $this->getJson("/api/v1/projects/{$this->project->id}", $this->appHeaders($this->appToken($this->editor)))->assertOk();
    }

    public function test_search_needs_every_content_area(): void
    {
        $this->api($this->apiKey($this->editor, ['issues' => 'read']), '/search?q=x')
            ->assertForbidden()->assertJsonPath('code', 'key_ability_denied');
        $all = array_fill_keys(ApiKeyAreas::contentAreas(), 'read');
        $this->api($this->apiKey($this->editor, $all), '/search?q=x')->assertOk()->assertJsonPath('places', []);
    }

    // ── Rate limits ─────────────────────────────────────────────────────────────────────────

    public function test_reads_and_writes_are_limited_per_key(): void
    {
        config(['api-keys.rate_limits.read' => 3, 'api-keys.rate_limits.write' => 2]);
        $secret = $this->apiKey($this->editor, ['issues' => 'write']);
        $item = $this->item();

        $first = $this->api($secret, '/me')->assertOk();
        $this->assertSame('3', (string) $first->headers->get('X-RateLimit-Limit'));
        $this->assertSame('2', (string) $first->headers->get('X-RateLimit-Remaining'));
        $this->api($secret, '/me')->assertOk();
        $this->api($secret, '/me')->assertOk();
        $limited = $this->api($secret, '/me')->assertStatus(429)->assertJsonPath('code', 'rate_limited');
        $this->assertNotNull($limited->headers->get('Retry-After'));

        // writes have their own budget; another key its own
        $this->patchJson("/api/v1/items/{$item->id}", ['title' => 'a'], $this->keyHeaders($secret))->assertOk();
        $this->api($this->apiKey($this->editor, ['issues' => 'read']), '/me')->assertOk();
    }
}
