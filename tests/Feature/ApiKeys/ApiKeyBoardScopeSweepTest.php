<?php

namespace Tests\Feature\ApiKeys;

use App\Models\ApiKey;
use App\Models\Board;
use App\Models\Item;
use App\Models\MyDayEntry;
use App\Models\Release;
use App\Models\Sprint;
use App\Models\TimeEntry;
use App\Models\WorkTimer;
use App\Support\ApiKeys\ApiKeyAreas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Feature\ApiKeys\Concerns\UsesApiKeys;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * Developer API (v1.52.0, Codex re-review): a key limited to one board never reaches an issue on another board
 * of the same project — through any route that takes an issue (id, key, poll), through ids in bodies, the
 * timer, work logs, My Work / My Day / Today / the agenda, releases, sprints, dated issues, smart links. Every
 * answer is a refusal (403 / 404 / 422) or leaves the issue out; its title never appears. Central mechanism:
 * the Item / TimeEntry global scopes of ApiKeyAccess, plus AuthorizeApiKey and ApiKeyGate.
 */
class ApiKeyBoardScopeSweepTest extends TestCase
{
    use ForgetsGuardsBetweenRequests;
    use InteractsWithBoards;
    use RefreshDatabase;
    use UsesApiKeys;

    private const SECRET = 'Zebra-secret-title-91';

    private Board $hidden;

    private Item $out;

    private Item $in;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        $this->hidden = Board::factory()->create(['project_id' => $this->project->id, 'name' => 'Hidden board']);
        $today = now()->toDateString();
        $this->in = $this->item(['title' => 'Visible issue', 'assigned_to' => $this->editor->id, 'due_date' => $today]);
        $this->out = Item::factory()->create(['board_id' => $this->hidden->id, 'title' => self::SECRET, 'assigned_to' => $this->editor->id, 'due_date' => $today, 'created_by' => $this->editor->id]);
        // every area with write + delete: only the scope may stop it
        $this->key = $this->apiKey($this->editor, array_fill_keys(ApiKeyAreas::keys(), 'write'), ApiKey::SCOPE_BOARDS, [$this->board->id], canDelete: true);
    }

    private function req(string $method, string $uri, array $data = [])
    {
        return $this->json($method, '/api/v1'.$uri, $data, $this->keyHeaders($this->key));
    }

    private function assertNoLeak($response, string $what, bool $mayPass = false): void
    {
        $this->assertStringNotContainsString(self::SECRET, (string) $response->getContent(), "{$what} leaked the out-of-scope issue");
        if (! $mayPass) {
            $this->assertContains($response->status(), [403, 404, 409, 422], "{$what} answered {$response->status()}");
        }
    }

    public function test_every_route_that_takes_an_issue_refuses_one_on_another_board(): void
    {
        $checked = 0;
        foreach (Route::getRoutes()->getRoutes() as $route) {
            /** @var RoutingRoute $route */
            $uri = $route->uri();
            // routes whose {item} / {key} / poll scope is an issue (task lists and bookmarks call their own rows {item})
            if (! Str::startsWith($uri, ['api/v1/items/', 'api/v1/polls/'])) {
                continue;
            }
            $path = '/'.Str::after(strtr($uri, [
                '{item}' => (string) $this->out->id,
                '{key}' => (string) $this->out->key,
                '{scopeType}' => 'item',
                '{scopeId}' => (string) $this->out->id,
                '{pollId}' => 'poll-1',
                '{link}' => '1',
                '{attachment}' => '1',
            ]), 'api/v1/');
            $method = collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first();

            $response = $this->req($method, $path, ['column_id' => 1, 'title' => 'x', 'body' => 'x', 'target_id' => $this->in->id, 'type' => 'relates_to']);
            $this->assertNoLeak($response, "{$method} {$path}");
            $checked++;
        }
        $this->assertGreaterThanOrEqual(18, $checked);
        $this->assertNotSoftDeleted($this->out);
        $this->assertSame(self::SECRET, $this->out->fresh()->title);
    }

    public function test_ids_in_bodies_are_refused(): void
    {
        $this->assertNoLeak($this->req('POST', "/items/{$this->in->id}/links", ['target_id' => $this->out->id, 'type' => 'relates_to']), 'link target');
        $this->assertNoLeak($this->req('POST', '/me/my-day/items', ['id' => "issue:{$this->out->id}"]), 'add to My Day');
        $this->assertNoLeak($this->req('POST', '/me/timer/start', ['id' => "issue:{$this->out->id}"]), 'timer start');
        $this->assertNoLeak($this->req('POST', '/work-logs', ['project_id' => $this->project->id, 'issue_id' => $this->out->id, 'time' => '1h', 'spent_on' => now()->toDateString()]), 'work log on it');
        $this->assertNoLeak($this->req('PATCH', "/me/work/issue:{$this->out->id}", ['done' => true]), 'mark done in My Work');
        $this->assertNull($this->out->fresh()->completed_at);
        $this->assertDatabaseCount('item_links', 0);
        $this->assertDatabaseCount('work_timers', 0);
    }

    public function test_the_timer_on_another_board_is_neither_shown_nor_stopped_nor_discarded(): void
    {
        $timer = WorkTimer::create(['user_id' => $this->editor->id, 'item_id' => $this->out->id, 'started_at' => now()->subMinutes(30), 'description' => 'deep work']);

        $this->assertNoLeak($this->req('GET', '/me/timer')->assertForbidden()->assertJsonPath('code', 'key_scope_denied'), 'timer show');
        $this->assertNoLeak($this->req('POST', '/me/timer/stop', ['timer_id' => $timer->id]), 'timer stop');
        $this->assertNoLeak($this->req('POST', '/me/timer/discard', ['timer_id' => $timer->id]), 'timer discard');
        $this->assertModelExists($timer);
        $this->assertDatabaseCount('time_entries', 0);
    }

    public function test_lists_and_aggregates_leave_the_issue_out(): void
    {
        $release = Release::factory()->create(['project_id' => $this->project->id]);
        $this->out->forceFill(['release_id' => $release->id])->save();
        $this->in->forceFill(['release_id' => $release->id])->save();
        MyDayEntry::create(['user_id' => $this->editor->id, 'work_type' => 'board', 'work_id' => $this->out->id, 'plan_date' => now()->toDateString(), 'position' => 1]);
        TimeEntry::query()->insert(['workspace_id' => $this->workspace->id, 'project_id' => $this->project->id, 'item_id' => $this->out->id, 'user_id' => $this->editor->id,
            'spent_on' => now()->toDateString(), 'minutes' => 60, 'item_key' => $this->out->key, 'item_title' => self::SECRET, 'source' => 'manual', 'billable' => false, 'created_at' => now(), 'updated_at' => now()]);
        $sprint = Sprint::factory()->create(['board_id' => $this->hidden->id]);
        $day = now()->toDateString();

        foreach ([
            '/me/work?status=all', '/me/my-day', '/me/today', "/calendar/events?from={$day}&to={$day}", '/work-logs', '/me/timesheet',
            "/releases/{$release->id}", "/projects/{$this->project->id}/releases", "/projects/{$this->project->id}/items?from={$day}&to={$day}",
            "/projects/{$this->project->id}", "/projects/{$this->project->id}/boards", "/boards/{$this->board->id}/items",
            "/links/resolve?workspace={$this->workspace->slug}&refs[]=item:{$this->out->id}", '/search?q=Zebra',
            "/boards/{$this->hidden->id}/items", "/sprints/{$sprint->id}/items", "/boards/{$this->hidden->id}/backlog",
        ] as $uri) {
            $this->assertNoLeak($this->req('GET', $uri), "GET {$uri}", mayPass: true);
        }

        // and the in-scope issue is still there where it belongs
        $this->assertContains('Visible issue', array_column($this->req('GET', '/me/work?status=all')->assertOk()->json('data'), 'title'));
    }
}
