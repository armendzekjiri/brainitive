<?php

namespace Tests\Feature\Live;

use App\Models\User;
use App\Support\AppApi\AppSignInGeneration;
use App\Support\Collab\CollabConfig;
use App\Support\Collab\CollabDocument;
use App\Support\Collab\CollabToken;
use App\Support\Live\LiveConfig;
use App\Support\Live\LiveToken;
use App\Support\Live\RealtimeSession;
use App\Support\SignOutEverywhere;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Pages\InteractsWithPages;
use Tests\TestCase;

/**
 * Codex realtime review, finding 1: the web's realtime tokens are bound to their browser session (`sid`) and to the
 * user's sign-out-everywhere generation (`gen`). Signing a session out pushes its end to the realtime server and the
 * internal access checks report it; SignOutEverywhere pushes the new generation. See RealtimeSession.
 */
class RealtimeSessionTest extends TestCase
{
    use InteractsWithPages;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPages();
        config(['collab.url' => 'wss://brainitive.test/collab', 'collab.secret' => 'test-collab-secret', 'collab.e2e_opt_in' => false, 'live.enabled' => true, 'live.url' => null, 'live.publish_url' => 'http://127.0.0.1:6299']);
        Cache::forget('live:publish:paused');
        $this->withoutDefer();
        Http::fake(['127.0.0.1:6299/*' => Http::response(['closed' => 1], 202)]);
    }

    /** @return array<string, string> */
    private function internal(): array
    {
        return ['Authorization' => 'Bearer '.CollabConfig::internalKey()];
    }

    /** A browser: the same session cookie on every request. */
    private function browser(string $sessionId): static
    {
        return $this->withCredentials()->withCookie(config('session.cookie'), $sessionId);
    }

    /** @return array<string, mixed> */
    private function liveToken(User $user, string $sessionId): array
    {
        $token = $this->browser($sessionId)->actingAs($user)->postJson(route('live.token'), ['channels' => ["user.{$user->id}"]])->assertOk()->json('token');

        return LiveToken::verify($token);
    }

    private function pushedSessions(): array
    {
        return collect(Http::recorded())
            ->filter(fn (array $pair) => $pair[0]->url() === 'http://127.0.0.1:6299/internal/live/sessions/ended')
            ->map(fn (array $pair) => json_decode($pair[0]->body(), true))
            ->values()
            ->all();
    }

    public function test_web_tokens_carry_the_session_and_generation_app_tokens_their_device(): void
    {
        $session = Str::random(40);
        $live = $this->liveToken($this->editor, $session);
        $this->assertMatchesRegularExpression(RealtimeSession::ID, $live['sid']);
        $this->assertSame(0, $live['gen']);
        $this->assertArrayNotHasKey('dev', $live);
        $this->assertStringNotContainsString($session, json_encode($live), 'never the session id itself');

        // the same browser session: the same sid for document tokens; another browser: another sid
        $page = $this->publishedPage();
        $collab = $this->browser($session)->actingAs($this->editor)->postJson($this->pageRoute('pages.collab.session', $page))->assertOk()->json('token');
        $this->assertSame($live['sid'], CollabToken::verify($collab)['sid']);
        $this->assertNotSame($live['sid'], $this->liveToken($this->editor, Str::random(40))['sid']);

        // App API tokens are bound to their device instead
        $device = LiveToken::verify(LiveToken::issue($this->editor, [], device: 7)['token']);
        $this->assertSame(7, $device['dev']);
        $this->assertArrayNotHasKey('sid', $device);
        $this->assertArrayNotHasKey('gen', $device);
    }

    public function test_logging_out_ends_that_session_only_pushed_and_answered_by_the_access_checks(): void
    {
        $session = Str::random(40);
        $sid = $this->liveToken($this->editor, $session)['sid'];
        $other = $this->liveToken($this->editor, Str::random(40))['sid'];
        Http::fake(['127.0.0.1:6299/*' => Http::response(['closed' => 1], 202)]);

        $this->browser($session)->actingAs($this->editor)->post(route('logout'));

        $this->assertSame([['sessions' => [$sid]]], $this->pushedSessions(), 'only this session, at once');
        Http::assertSent(fn (Request $request) => $request->header('Authorization')[0] === 'Bearer '.LiveConfig::publishKey());

        // a missed push changes nothing: the realtime server asks on connect and every minute
        $this->postJson(route('internal.collab.live.access'), ['users' => [
            ['id' => $this->editor->id, 'channels' => ["user.{$this->editor->id}"], 'sessions' => [$sid, $other]],
        ]], $this->internal())->assertOk()
            ->assertJsonPath('ended_sessions', [$sid])
            ->assertJsonPath("generations.{$this->editor->id}", 0);

        $document = CollabDocument::name($this->publishedPage()->uuid, 0);
        $this->postJson(route('internal.collab.access'), ['document' => $document, 'user_ids' => [$this->editor->id], 'sessions' => [$sid, $other, 'not-an-id']], $this->internal())
            ->assertOk()
            ->assertJsonPath('ended_sessions', [$sid])
            ->assertJsonPath("generations.{$this->editor->id}", 0);
    }

    public function test_sign_out_everywhere_pushes_the_new_generation(): void
    {
        $this->assertSame(0, $this->liveToken($this->editor, Str::random(40))['gen']);
        Http::fake(['127.0.0.1:6299/*' => Http::response(['closed' => 1], 202)]);

        SignOutEverywhere::run($this->editor);

        $this->assertContains(['users' => [['id' => $this->editor->id, 'generation' => 1]]], $this->pushedSessions());
        $this->assertSame(1, AppSignInGeneration::current($this->editor));
        $this->postJson(route('internal.collab.live.access'), ['users' => [['id' => $this->editor->id, 'channels' => []]]], $this->internal())
            ->assertOk()->assertJsonPath("generations.{$this->editor->id}", 1);
        // tokens issued from now on carry the new generation
        $this->assertSame(1, $this->liveToken($this->editor->fresh(), Str::random(40))['gen']);
    }

    public function test_the_access_endpoints_validate_sessions(): void
    {
        $this->postJson(route('internal.collab.live.access'), ['users' => [['id' => 1, 'channels' => [], 'sessions' => [str_repeat('a', 65)]]]], $this->internal())->assertUnprocessable();
        $this->postJson(route('internal.collab.live.access'), ['users' => [['id' => 1, 'channels' => [], 'sessions' => array_fill(0, 101, 'a')]]], $this->internal())->assertUnprocessable();
        $document = CollabDocument::name($this->publishedPage()->uuid, 0);
        $this->postJson(route('internal.collab.access'), ['document' => $document, 'user_ids' => [], 'sessions' => [['x']]], $this->internal())->assertUnprocessable();
        $this->postJson(route('internal.collab.access'), ['document' => $document, 'user_ids' => []], $this->internal())
            ->assertOk()->assertJsonPath('ended_sessions', []);
    }

    public function test_nothing_is_pushed_without_a_session_or_live_editing(): void
    {
        RealtimeSession::end(null);
        config(['collab.url' => null]);
        $this->browser(Str::random(40))->actingAs($this->editor)->post(route('logout'));
        $this->assertSame([], $this->pushedSessions());
    }
}
