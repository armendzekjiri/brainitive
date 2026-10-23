<?php

namespace Tests\Feature\Github;

use App\Models\Board;
use App\Models\GithubConnection;
use App\Models\GithubDelivery;
use App\Models\Item;
use App\Models\ItemDevLink;
use App\Models\Project;
use App\Support\Github\IssueKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

class GithubDevelopmentTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    private GithubConnection $connection;

    private Item $issue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        $this->connection = $this->project->githubConnection()->create(['uuid' => (string) Str::uuid(), 'secret' => 'shh-secret']);
        $this->issue = $this->item(['title' => 'Fix column create']);
    }

    private function deliver(string $event, array $payload, ?string $secret = null, ?string $id = null): TestResponse
    {
        $body = json_encode($payload + ['repository' => ['full_name' => 'acme/app']]);

        return $this->call('POST', route('webhooks.github', $this->connection->uuid), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => $event,
            'HTTP_X_GITHUB_DELIVERY' => $id ?? (string) Str::uuid(),
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret ?? 'shh-secret'),
        ], $body);
    }

    private function push(string $branch, array $messages, string $id = 'p1'): TestResponse
    {
        $commits = [];
        foreach ($messages as $i => $message) {
            $commits[] = ['id' => str_repeat(dechex($i + 10), 40 / strlen(dechex($i + 10))), 'message' => $message, 'url' => 'https://github.com/acme/app/commit/x'.$i, 'author' => ['username' => 'dev'], 'timestamp' => now()->toIso8601String()];
        }

        return $this->deliver('push', ['ref' => 'refs/heads/'.$branch, 'commits' => $commits, 'deleted' => false], id: $id);
    }

    public function test_keys_are_found_case_insensitively_but_never_inside_other_words(): void
    {
        $this->assertSame(['BRAI-123'], IssueKeys::find('fix(BRAI-123): column bug', 'BRAI'));
        $this->assertSame(['BRAI-2423'], IssueKeys::find('feat/brai-2423_fix_column', 'BRAI'));
        $this->assertSame(['BRAI-7', 'BRAI-8'], IssueKeys::find('Merge pull request #4 from x/BRAI-7 (Brai-8, brai-07)', 'BRAI'));
        $this->assertSame([], IssueKeys::find('XBRAI-1, BRAI-, BRAIN-2, OTHER-5', 'BRAI'));
    }

    public function test_signature_unknown_hooks_and_duplicate_deliveries(): void
    {
        $this->deliver('push', ['ref' => 'refs/heads/main', 'commits' => []], secret: 'wrong')->assertStatus(401);
        $this->postJson('/api/webhooks/github/'.Str::uuid(), [])->assertNotFound();
        $this->deliver('issues', [])->assertStatus(202)->assertJson(['message' => 'Ignored.']);

        $this->push('main', ["fix({$this->issue->key}): first"], 'same-id')->assertStatus(202);
        $this->push('main', ["fix({$this->issue->key}): first"], 'same-id')->assertOk()->assertJson(['message' => 'Already received.']);
        $this->assertSame(1, GithubDelivery::count());
        $this->assertSame(1, $this->issue->devLinks()->where('kind', 'commit')->count());
        $this->assertSame(['acme/app'], $this->connection->fresh()->repositories);
    }

    public function test_push_links_commits_by_message_and_branches_by_name(): void
    {
        $key = $this->issue->key; // e.g. BRAI-1
        $foreign = Item::factory()->create(['board_id' => Board::factory()->create(['project_id' => Project::factory()->create(['key' => 'OTHR'])->id])->id]);

        $this->push('feat/'.strtolower($key).'_fix_column', ["fix({$key}): show new columns\n\nlonger body", 'chore: no key', "{$foreign->key} not ours"])->assertStatus(202);

        $links = $this->issue->devLinks()->get()->groupBy('kind');
        $this->assertSame('feat/'.strtolower($key).'_fix_column', $links['branch'][0]->identifier);
        $this->assertSame('active', $links['branch'][0]->state);
        $this->assertCount(1, $links['commit']);
        $this->assertSame("fix({$key}): show new columns", $links['commit'][0]->title);
        $this->assertSame(0, $foreign->devLinks()->count());

        // Branch deleted after merge.
        $this->deliver('delete', ['ref_type' => 'branch', 'ref' => 'feat/'.strtolower($key).'_fix_column'])->assertStatus(202);
        $this->assertSame('deleted', $this->issue->devLinks()->where('kind', 'branch')->value('state'));
    }

    public function test_pull_request_states_follow_github(): void
    {
        $pr = fn (array $extra) => ['action' => 'x', 'pull_request' => $extra + [
            'number' => 12, 'title' => 'Fix columns', 'html_url' => 'https://github.com/acme/app/pull/12',
            'head' => ['ref' => 'feat/'.$this->issue->key.'-columns'], 'base' => ['ref' => 'main'],
            'user' => ['login' => 'dev'], 'state' => 'open', 'draft' => false, 'merged' => false, 'updated_at' => now()->toIso8601String(),
        ]];

        $this->deliver('pull_request', $pr(['draft' => true]));
        $this->assertSame('draft', $this->issue->devLinks()->where('kind', 'pull_request')->value('state'));
        $this->deliver('pull_request', $pr([]));
        $this->assertSame('open', $this->issue->devLinks()->where('kind', 'pull_request')->value('state'));
        $this->deliver('pull_request', $pr(['state' => 'closed', 'merged' => true, 'merged_at' => now()->toIso8601String()]));

        $link = $this->issue->devLinks()->where('kind', 'pull_request')->sole();
        $this->assertSame(['merged', 'main'], [$link->state, $link->meta['base']]);
    }

    public function test_deployments_mark_only_what_shipped_since_the_last_deploy(): void
    {
        $older = $this->item(['title' => 'Old work']);
        $later = $this->item(['title' => 'Not deployed yet']);
        $deploy = fn (string $state, string $sha = 'abc1234') => $this->deliver('deployment_status', [
            'deployment' => ['environment' => 'production', 'ref' => 'main', 'sha' => $sha],
            'deployment_status' => ['state' => $state, 'created_at' => now()->toIso8601String(), 'environment_url' => 'https://www.example.com', 'log_url' => 'https://github.com/acme/app/actions/runs/1'],
        ]);

        $this->push('main', ["fix({$older->key}): old"], 'a');
        $this->travel(1)->minutes();
        $deploy('success');
        $this->assertSame('success', $older->devLinks()->where('kind', 'deployment')->value('state'));

        $this->travel(1)->minutes();
        $this->push('main', ["fix({$this->issue->key}): new"], 'b');
        $this->push('feat/x', ["wip({$later->key}): branch only"], 'c');
        $this->travel(1)->minutes();
        $deploy('in_progress', 'def5678');
        $this->assertSame('in_progress', $this->issue->devLinks()->where('kind', 'deployment')->value('state'));
        $deploy('failure', 'def5678');
        $this->assertSame('failure', $this->issue->devLinks()->where('kind', 'deployment')->value('state'));

        $this->travel(1)->minutes();
        $deploy('success', 'def5678');
        $deployment = $this->issue->devLinks()->where('kind', 'deployment')->sole();
        $this->assertSame(['success', 'production', 'https://www.example.com'], [$deployment->state, $deployment->identifier, $deployment->url]);

        // Older work isn't re-marked by later deploys, branch-only work isn't deployed.
        $this->assertSame(1, $older->devLinks()->where('kind', 'deployment')->count());
        $this->assertSame(0, $later->devLinks()->where('kind', 'deployment')->count());
    }

    public function test_settings_are_for_project_admins_and_disconnect_removes_everything(): void
    {
        $this->push('main', ["fix({$this->issue->key}): x"]);
        $routes = fn (string $name) => route($name, [$this->workspace->slug, $this->project->slug]);

        $this->actingAs($this->editor)->post($routes('projects.github.rotate'))->assertForbidden();
        $this->actingAs($this->editor)->delete($routes('projects.github.destroy'))->assertForbidden();

        $admin = $this->projectMember('admin');
        $old = $this->connection->secret;
        $this->actingAs($admin)->post($routes('projects.github.rotate'))->assertRedirect();
        $this->assertNotSame($old, $this->connection->fresh()->secret);

        $this->actingAs($admin)->delete($routes('projects.github.destroy'))->assertRedirect();
        $this->assertSame(0, ItemDevLink::count());
        $this->assertSame(0, GithubDelivery::count());

        $this->actingAs($admin)->post($routes('projects.github.store'))->assertRedirect();
        $this->assertNotNull($this->project->fresh()->githubConnection);
    }

    public function test_issue_details_include_development_for_viewers_only(): void
    {
        $this->push('main', ["fix({$this->issue->key}): x"]);
        $url = route('boards.items.details', [$this->workspace->slug, $this->project->slug, $this->board->slug, $this->issue->id]);

        $this->actingAs($this->viewer)->getJson($url)->assertOk()
            ->assertJsonPath('development.connected', true)
            ->assertJsonPath('development.commit_count', 1)
            ->assertJsonPath('development.commits.0.title', "fix({$this->issue->key}): x");
        $this->actingAs($this->outsider)->getJson($url)->assertForbidden();
    }
}
