<?php

namespace Tests\Feature\Live;

use App\Models\Board;
use App\Models\Item;
use App\Models\Plan;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Collab\CollabConfig;
use App\Support\Collab\CollabToken;
use App\Support\Live\LiveChannels;
use App\Support\Live\LiveConfig;
use App\Support\Live\LiveToken;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Pages\InteractsWithPages;
use Tests\TestCase;

/**
 * Live channels (BRAI-151): who gets a token for which channel, the token itself, and the realtime
 * server's minute-by-minute re-check. Plan: docs/ai/tasks/feat-live-foundation.md.
 */
class LiveChannelsTest extends TestCase
{
    use InteractsWithPages;
    use RefreshDatabase;

    private Board $board;

    private Item $item;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPages();
        config(['collab.url' => 'wss://brainitive.test/collab', 'collab.secret' => 'test-collab-secret', 'collab.e2e_opt_in' => false, 'live.url' => null, 'live.publish_url' => null, 'live.enabled' => true]);

        $this->board = Board::factory()->create(['project_id' => $this->project->id]);
        $this->item = Item::factory()->create(['board_id' => $this->board->id, 'created_by' => $this->author->id]);
        $this->plan = Plan::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->author->id]);
    }

    /**
     * @param  list<string>  $channels
     */
    private function token(User $user, array $channels): TestResponse
    {
        return $this->actingAs($user)->postJson(route('live.token'), ['channels' => $channels]);
    }

    /**
     * @return list<string>
     */
    private function contentChannels(): array
    {
        return ["item.{$this->item->id}", "board.{$this->board->id}", "plan.{$this->plan->id}", 'page.'.$this->publishedPage()->uuid];
    }

    // ── token endpoint ─────────────────────────────────────────────────────────────────────────

    public function test_project_members_get_a_signed_token_for_their_channels(): void
    {
        $channels = ["user.{$this->viewer->id}", ...$this->contentChannels()];

        $response = $this->token($this->viewer, $channels)->assertOk()
            ->assertJsonPath('url', 'wss://brainitive.test/collab?live=1')
            ->assertJsonPath('channels', $channels)
            ->assertJsonPath('denied', [])
            ->assertJsonPath('user.id', $this->viewer->id);

        $payload = LiveToken::verify($response->json('token'));
        $this->assertNotNull($payload);
        $this->assertSame($this->viewer->id, $payload['sub']);
        $this->assertSame($channels, $payload['ch']);
        $this->assertSame($this->viewer->name, $payload['name']);
        $this->assertSame($payload['exp'], $response->json('expires_at'));
        $this->assertEqualsWithDelta(time() + 120, $payload['exp'], 5);
    }

    public function test_only_your_own_user_channel(): void
    {
        $this->token($this->editor, ["user.{$this->editor->id}", "user.{$this->viewer->id}"])->assertOk()
            ->assertJsonPath('channels', ["user.{$this->editor->id}"])
            ->assertJsonPath('denied', ["user.{$this->viewer->id}"]);
    }

    public function test_outsiders_and_other_workspaces_get_no_content_channels(): void
    {
        $channels = $this->contentChannels();
        $this->token($this->outsider, $channels)->assertOk()->assertJsonPath('channels', [])->assertJsonPath('denied', $channels);

        // a member of another project in the same workspace
        $other = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->author->id]);
        $neighbour = User::factory()->create();
        $this->workspace->addUser($neighbour, 'member');
        $other->users()->attach($neighbour->id, ['role' => 'editor']);
        $this->token($neighbour, $channels)->assertOk()->assertJsonPath('channels', []);

        // an admin of another workspace
        $elsewhere = User::factory()->create();
        Workspace::factory()->create(['owner_id' => $elsewhere->id])->addUser($elsewhere, 'admin');
        $this->token($elsewhere, $channels)->assertOk()->assertJsonPath('channels', []);
    }

    public function test_drafts_follow_the_page_policy(): void
    {
        $draft = $this->draftPage();
        $this->token($this->author, ["page.{$draft->uuid}"])->assertJsonPath('channels', ["page.{$draft->uuid}"]);
        $this->token($this->admin, ["page.{$draft->uuid}"])->assertJsonPath('channels', ["page.{$draft->uuid}"]);
        // someone else's never-published page isn't visible to a project editor
        $this->token($this->editor, ["page.{$draft->uuid}"])->assertJsonPath('channels', []);
    }

    public function test_trashed_records_and_records_under_trashed_parents_are_refused(): void
    {
        $item = Item::factory()->create(['board_id' => $this->board->id, 'created_by' => $this->author->id]);
        $item->delete();
        $plan = Plan::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->author->id]);
        $plan->delete();
        $this->token($this->admin, ["item.{$item->id}", "plan.{$plan->id}"])->assertJsonPath('channels', []);

        // the board goes to the trash: its issues go with it
        $this->board->delete();
        $this->token($this->admin, ["board.{$this->board->id}", "item.{$this->item->id}"])->assertJsonPath('channels', []);

        // the project goes to the trash: everything in it
        $page = $this->publishedPage();
        $this->project->delete();
        $this->token($this->admin, ["page.{$page->uuid}", "plan.{$this->plan->id}"])->assertJsonPath('channels', []);
    }

    public function test_a_trashed_workspace_takes_its_channels_along(): void
    {
        $channels = $this->contentChannels();
        $this->workspace->delete();

        $this->token($this->admin, $channels)->assertOk()->assertJsonPath('channels', [])->assertJsonPath('denied', $channels);
        // the minute re-check drops them without failing the rest of the batch
        $this->postJson(route('internal.collab.live.access'), ['users' => [
            ['id' => $this->admin->id, 'channels' => [...$channels, "user.{$this->admin->id}"]],
            ['id' => $this->viewer->id, 'channels' => ["user.{$this->viewer->id}"]],
        ]], $this->internal())->assertOk()
            ->assertJsonPath("users.{$this->admin->id}", ["user.{$this->admin->id}"])
            ->assertJsonPath("users.{$this->viewer->id}", ["user.{$this->viewer->id}"]);
    }

    public function test_unknown_or_malformed_channels_are_refused(): void
    {
        $this->token($this->admin, ['item.0', 'item.abc', 'items.1', 'page.not-a-uuid', 'user', 'workspace.1', "user.{$this->admin->id}.x", 'item.999999', 'item.9999999999999999999', 'board.999999999999999999'])
            ->assertOk()->assertJsonPath('channels', []);
        $this->token($this->admin, [])->assertUnprocessable();
        $this->token($this->admin, array_map(fn ($i) => "item.{$i}", range(1, 51)))->assertUnprocessable();
        $this->token($this->admin, [['nested']])->assertUnprocessable();
    }

    public function test_suspended_users_get_nothing(): void
    {
        $this->assertSame([], (new LiveChannels)->authorize($this->admin->forceFill(['suspended_at' => now()]), ["user.{$this->admin->id}", "item.{$this->item->id}"]));
    }

    public function test_the_endpoint_is_off_without_live_editing_or_for_guests(): void
    {
        $this->postJson(route('live.token'), ['channels' => ['user.1']])->assertUnauthorized();

        config(['live.enabled' => false]);
        $this->token($this->admin, ["user.{$this->admin->id}"])->assertNotFound();

        config(['live.enabled' => true, 'collab.url' => null]);
        $this->token($this->admin, ["user.{$this->admin->id}"])->assertNotFound();
    }

    public function test_e2e_opt_in_needs_the_header(): void
    {
        $this->app['env'] = 'e2e';
        $this->withoutMiddleware(ValidateCsrfToken::class);
        config(['collab.e2e_opt_in' => true]);
        $this->token($this->admin, ["user.{$this->admin->id}"])->assertNotFound();
        $this->withHeader('X-E2E-Live', '1');
        $this->token($this->admin, ["user.{$this->admin->id}"])->assertOk();
    }

    public function test_live_url_and_publish_url(): void
    {
        $this->assertSame('wss://brainitive.test/collab?live=1', LiveConfig::url());
        $this->assertNull(LiveConfig::publishUrl(), 'a public URL is never a publish target');

        config(['collab.url' => 'ws://127.0.0.1:1234']);
        $this->assertSame('ws://127.0.0.1:1234?live=1', LiveConfig::url());
        $this->assertSame('http://127.0.0.1:1234', LiveConfig::publishUrl(), 'local dev: the same process');

        config(['collab.url' => '/collab', 'live.url' => '/live', 'live.publish_url' => 'http://realtime:1234/']);
        $this->assertSame('/live', LiveConfig::url());
        $this->assertSame('http://realtime:1234', LiveConfig::publishUrl());
    }

    public function test_tokens_are_signed_with_their_own_key(): void
    {
        $token = LiveToken::issue($this->admin, ['user.1'])['token'];
        $this->assertNotNull(LiveToken::verify($token));
        $this->assertNull(LiveToken::verify($token.'x'));
        $this->assertNull(LiveToken::verify($token, time() + 121), 'expired');
        // a document token is never a channel token, and the keys never equal the collab keys
        $this->assertNull(LiveToken::verify(CollabToken::issue($this->admin, 'page.x.0', true)['token']));
        $this->assertNotSame(CollabConfig::tokenKey(), LiveConfig::tokenKey());
        $this->assertNotSame(CollabConfig::internalKey(), LiveConfig::publishKey());
        // realtime/test/live.test.js checks the same values
        config(['collab.secret' => 'test-collab-secret']);
        $this->assertSame('ae80e72b9aaa87b37d9a66228ad301222ffbc8733459262eb3b3ad123d060d6b', LiveConfig::publishKey());
    }

    public function test_the_live_prop_is_shared_only_when_live_is_on(): void
    {
        $live = fn () => $this->actingAs($this->admin)->get(route('workspaces.index'))->assertOk()->viewData('page')['props']['live'];

        $this->assertSame(['debug' => false], $live());
        config(['live.enabled' => false]);
        $this->assertNull($live());
        config(['live.enabled' => true, 'collab.url' => null]);
        $this->assertNull($live());
    }

    // ── internal re-check (realtime server) ────────────────────────────────────────────────────

    private function internal(): array
    {
        return ['Authorization' => 'Bearer '.CollabConfig::internalKey()];
    }

    public function test_the_access_endpoint_needs_the_internal_key(): void
    {
        $url = route('internal.collab.live.access');
        $body = ['users' => [['id' => $this->admin->id, 'channels' => ["user.{$this->admin->id}"]]]];

        $this->postJson($url, $body)->assertUnauthorized();
        $this->postJson($url, $body, ['Authorization' => 'Bearer test-collab-secret'])->assertUnauthorized();
        $this->postJson($url, $body, ['Authorization' => 'Bearer '.LiveConfig::publishKey()])->assertUnauthorized();
        $token = LiveToken::issue($this->admin, ["user.{$this->admin->id}"])['token'];
        $this->postJson($url, $body, ['Authorization' => "Bearer {$token}"])->assertUnauthorized();
        $this->postJson($url, $body, $this->internal())->assertOk();

        config(['collab.url' => null]);
        $this->postJson($url, $body, $this->internal())->assertNotFound();
    }

    public function test_the_access_endpoint_drops_channels_users_lost(): void
    {
        $channels = ["user.{$this->viewer->id}", "item.{$this->item->id}", "board.{$this->board->id}"];
        $url = route('internal.collab.live.access');
        $ask = fn () => $this->postJson($url, ['users' => [
            ['id' => $this->viewer->id, 'channels' => $channels],
            ['id' => $this->outsider->id, 'channels' => ["item.{$this->item->id}", "user.{$this->outsider->id}"]],
            ['id' => 999999, 'channels' => ['user.999999']],
        ]], $this->internal())->assertOk();

        $ask()->assertJsonPath("users.{$this->viewer->id}", $channels)
            ->assertJsonPath("users.{$this->outsider->id}", ["user.{$this->outsider->id}"])
            ->assertJsonPath('users.999999', []);

        // the viewer leaves the project
        $this->project->users()->detach($this->viewer->id);
        $ask()->assertJsonPath("users.{$this->viewer->id}", ["user.{$this->viewer->id}"]);

        // suspended after connecting: everything goes, their own channel too
        $this->editor->forceFill(['suspended_at' => now()])->save();
        $this->postJson($url, ['users' => [['id' => $this->editor->id, 'channels' => ["user.{$this->editor->id}", "item.{$this->item->id}"]]]], $this->internal())
            ->assertOk()->assertJsonPath("users.{$this->editor->id}", []);

        // several entries of one user (many tabs) add up
        $this->postJson($url, ['users' => [
            ['id' => $this->admin->id, 'channels' => ["item.{$this->item->id}"]],
            ['id' => $this->admin->id, 'channels' => ["board.{$this->board->id}"]],
        ]], $this->internal())->assertOk()->assertJsonPath("users.{$this->admin->id}", ["item.{$this->item->id}", "board.{$this->board->id}"]);
    }

    public function test_the_access_endpoint_validates_its_input(): void
    {
        $url = route('internal.collab.live.access');
        $this->postJson($url, ['users' => [['id' => 'x', 'channels' => []]]], $this->internal())->assertUnprocessable();
        $this->postJson($url, ['users' => [['id' => 1, 'channels' => array_fill(0, 101, 'user.1')]]], $this->internal())->assertUnprocessable();
        $this->postJson($url, ['users' => []], $this->internal())->assertOk()->assertExactJson(['users' => []]);
    }
}
