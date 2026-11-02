<?php

namespace Tests\Feature\AppApi;

use App\Models\AppDevice;
use App\Models\Board;
use App\Models\Item;
use App\Models\Plan;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AppApi\AppTokens;
use App\Support\Collab\CollabConfig;
use App\Support\Live\BoardLive;
use App\Support\Live\LiveConfig;
use App\Support\Live\LiveToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

use function Illuminate\Support\defer;

/**
 * App API v1 realtime (BRAI-426): connection info, channel tokens for app tokens through the web's rules
 * (LiveChannels: never a channel the user couldn't open on the web), Passport → 401, live off → 404, and the
 * echo suppression of app writes (X-Live-Client + the app user as actor).
 */
class RealtimeTest extends TestCase
{
    use ActsAsAppClient;
    use ForgetsGuardsBetweenRequests;
    use InteractsWithBoards;
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        Notification::fake();
        BoardLive::reset();
        Cache::forget('live:publish:paused');
        Http::fake(['127.0.0.1:6299/*' => Http::response(['delivered' => 1], 202)]);
        config(['collab.url' => 'wss://brainitive.test/collab', 'collab.secret' => 'test-collab-secret', 'collab.e2e_opt_in' => false, 'live.enabled' => true, 'live.url' => null, 'live.publish_url' => 'http://127.0.0.1:6299']);
        $this->token = $this->appToken($this->viewer);
    }

    /** @param list<string> $channels */
    private function grant(array $channels, ?string $token = null): TestResponse
    {
        return $this->postJson('/api/v1/realtime/token', ['channels' => $channels], $this->appHeaders($token ?? $this->token));
    }

    public function test_connection_info(): void
    {
        $this->getJson('/api/v1/realtime', $this->appHeaders($this->token))->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.url', 'wss://brainitive.test/collab?live=1')
            ->assertJsonPath('data.token_path', '/api/v1/realtime/token')
            ->assertJsonPath('data.max_channels', 50)
            ->assertJsonPath('data.channel_types.0.type', 'user')
            ->assertJsonStructure(['data' => ['token_ttl', 'protocol', 'channel_types' => [['type', 'id', 'access']]]]);

        // a path LIVE_URL is made absolute for native clients (https → wss)
        config(['live.url' => '/collab?live=1', 'app.url' => 'https://brainitive.test']);
        $this->getJson('/api/v1/realtime', $this->appHeaders($this->token))->assertJsonPath('data.url', 'wss://brainitive.test/collab?live=1');
    }

    public function test_live_off_says_so_and_has_no_tokens(): void
    {
        config(['collab.url' => null]);

        $this->getJson('/api/v1/realtime', $this->appHeaders($this->token))->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.url', null);
        // 404 before validation
        $this->postJson('/api/v1/realtime/token', [], $this->appHeaders($this->token))->assertNotFound()->assertJsonPath('code', 'not_found');
    }

    public function test_only_app_tokens_get_channel_tokens(): void
    {
        $this->getJson('/api/v1/realtime')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
        $this->postJson('/api/v1/realtime/token', ['channels' => ["user.{$this->viewer->id}"]])->assertUnauthorized();

        $passport = $this->passportToken($this->viewer);
        $this->getJson('/api/v1/realtime', $this->appHeaders($passport))->assertUnauthorized();
        $this->grant(["user.{$this->viewer->id}"], $passport)->assertUnauthorized();
    }

    public function test_the_token_holds_the_channels_the_user_can_open_on_the_web(): void
    {
        $item = $this->item();
        $plan = Plan::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id]);
        $channels = ["user.{$this->viewer->id}", "board.{$this->board->id}", "item.{$item->id}", "plan.{$plan->id}"];

        $response = $this->grant($channels)->assertOk()
            ->assertJsonPath('data.channels', $channels)
            ->assertJsonPath('data.denied', [])
            ->assertJsonPath('data.url', 'wss://brainitive.test/collab?live=1')
            ->assertJsonPath('data.user.id', $this->viewer->id)
            ->assertJsonStructure(['data' => ['token', 'expires_at', 'expires_in']]);

        $payload = LiveToken::verify($response->json('data.token'));
        $this->assertNotNull($payload, 'a token the realtime server accepts (same key as the web)');
        $this->assertSame($this->viewer->id, $payload['sub']);
        $this->assertSame($channels, $payload['ch']);
        $this->assertSame($this->deviceOf($this->token)->id, $payload['dev'], 'bound to the device: closed when it is signed out');
    }

    public function test_never_a_channel_the_user_could_not_see_on_the_web(): void
    {
        $item = $this->item();
        $otherOwner = User::factory()->create();
        $otherWorkspace = Workspace::factory()->create(['owner_id' => $otherOwner->id]);
        $otherProject = Project::factory()->create(['workspace_id' => $otherWorkspace->id, 'created_by' => $otherOwner->id]);
        $otherBoard = Board::factory()->create(['project_id' => $otherProject->id, 'created_by' => $otherOwner->id]);
        $otherItem = Item::factory()->create(['board_id' => $otherBoard->id, 'created_by' => $otherOwner->id]);
        $foreign = ["user.{$this->editor->id}", "board.{$otherBoard->id}", "item.{$otherItem->id}", 'board.999999', 'secret.1', 'item.abc'];

        $response = $this->grant([...$foreign, "item.{$item->id}"])->assertOk()
            ->assertJsonPath('data.channels', ["item.{$item->id}"])
            ->assertJsonPath('data.denied', $foreign);
        $this->assertSame(["item.{$item->id}"], LiveToken::verify($response->json('data.token'))['ch']);

        // an outsider of the project gets nothing of it
        $this->grant(["board.{$this->board->id}", "item.{$item->id}"], $this->appToken($this->outsider))->assertOk()
            ->assertJsonPath('data.channels', []);

        // a trashed board's channels are gone
        $this->board->delete();
        $this->grant(["board.{$this->board->id}", "item.{$item->id}"])->assertJsonPath('data.channels', []);
    }

    public function test_bad_requests_and_suspended_users(): void
    {
        $this->grant([])->assertUnprocessable()->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors('channels');
        $this->grant(array_map(fn (int $i) => "item.{$i}", range(1, 51)))->assertUnprocessable()->assertJsonValidationErrors('channels');

        $this->viewer->forceFill(['suspended_at' => now()])->save();
        $this->grant(["user.{$this->viewer->id}"])->assertForbidden();
    }

    public function test_app_writes_carry_the_echo_suppression_of_the_web(): void
    {
        $item = $this->item();
        $token = $this->appToken($this->editor);
        defer()->invoke();
        $seen = count(Http::recorded());

        $this->postJson("/api/v1/items/{$item->id}/move", ['column_id' => $this->column('done')], $this->appHeaders($token) + ['X-Live-Client' => 'desktop-window-01'])
            ->assertOk();

        defer()->invoke();
        /** @var Collection<int, array{channel: string, event: string, payload: array<string, mixed>}> $messages */
        $messages = collect(Http::recorded())->slice($seen)
            ->flatMap(fn (array $pair) => json_decode($pair[0]->body(), true)['messages'] ?? [])
            ->where('channel', "board.{$this->board->id}")->values();
        $moved = $messages->firstWhere('event', 'item.moved');
        $this->assertNotNull($moved);
        $this->assertSame(['client_id' => 'desktop-window-01', 'actor_id' => $this->editor->id], array_intersect_key($moved['payload'], ['client_id' => 1, 'actor_id' => 1]));
        $this->assertSame($item->id, $moved['payload']['id']);
    }

    private function deviceOf(string $token): AppDevice
    {
        $device = AppTokens::deviceForAccessToken($token);
        $this->assertNotNull($device);

        return $device;
    }

    /** @return list<list<int>> the device lists pushed to the realtime server */
    private function pushedRevocations(): array
    {
        return collect(Http::recorded())
            ->filter(fn (array $pair) => $pair[0]->url() === 'http://127.0.0.1:6299/internal/live/devices/revoked')
            ->map(fn (array $pair) => json_decode($pair[0]->body(), true)['devices'])
            ->values()->all();
    }

    public function test_a_signed_out_device_is_pushed_to_the_realtime_server(): void
    {
        $device = $this->deviceOf($this->token);
        $other = $this->appDevice($this->viewer, ['name' => 'Phone']);
        $otherToken = $this->appToken($this->viewer, $other);
        defer()->invoke();
        $this->assertSame([], $this->pushedRevocations(), 'signing in pushes nothing');

        // remote sign-out from the other device
        $this->deleteJson("/api/v1/devices/{$device->id}", [], $this->appHeaders($otherToken))->assertSuccessful();
        defer()->invoke();

        $this->assertSame([[$device->id]], $this->pushedRevocations());
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/internal/live/devices/revoked')
            && $request->header('Authorization')[0] === 'Bearer '.LiveConfig::publishKey());
        // the revoked device gets no new live token; the other one still does
        $this->grant(["user.{$this->viewer->id}"])->assertUnauthorized();
        $this->grant(["user.{$this->viewer->id}"], $otherToken)->assertOk();

        // "sign out everywhere" (password change, suspension): every remaining device in one push
        $third = $this->appDevice($this->viewer, ['name' => 'TV', 'client' => 'tv', 'platform' => 'android_tv']);
        $this->appToken($this->viewer, $third);
        AppTokens::revokeAllFor($this->viewer, 'password_changed');
        defer()->invoke();
        $this->assertEqualsCanonicalizing([$other->id, $third->id], $this->pushedRevocations()[1]);
    }

    public function test_the_minute_recheck_names_revoked_devices(): void
    {
        $device = $this->deviceOf($this->token);
        $phone = $this->appDevice($this->viewer, ['name' => 'Phone']);
        $this->appToken($this->viewer, $phone);
        $foreign = $this->appDevice($this->editor);
        $this->appToken($this->editor, $foreign);
        $ask = fn () => $this->postJson(route('internal.collab.live.access'), ['users' => [
            ['id' => $this->viewer->id, 'channels' => ["user.{$this->viewer->id}"], 'devices' => [$device->id, $phone->id, $foreign->id, 999999]],
            ['id' => $this->editor->id, 'channels' => []],
        ]], ['Authorization' => 'Bearer '.CollabConfig::internalKey()])->assertOk();

        // another user's device and a missing one never count as the viewer's
        $ask()->assertJsonPath("users.{$this->viewer->id}", ["user.{$this->viewer->id}"])
            ->assertJsonPath('revoked_devices', [$foreign->id, 999999]);

        AppTokens::revoke($device, 'sign_out');
        $ask()->assertJsonPath('revoked_devices', [$device->id, $foreign->id, 999999]);
        // the channels themselves are still the user's: the web and the phone keep them
        $ask()->assertJsonPath("users.{$this->viewer->id}", ["user.{$this->viewer->id}"]);
    }
}
