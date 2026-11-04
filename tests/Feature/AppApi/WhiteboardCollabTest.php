<?php

namespace Tests\Feature\AppApi;

use App\Models\Page;
use App\Models\PageCollabState;
use App\Models\User;
use App\Support\AppApi\AppTokens;
use App\Support\Collab\CollabConfig;
use App\Support\Collab\CollabToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * App API v1 live whiteboards for native clients (tablets): the token (WhiteboardCollab::access, the rule the
 * realtime server checks, bound to the device), the bootstrap claim and the live scene save — the browser's three
 * calls with the web's field names.
 */
class WhiteboardCollabTest extends TestCase
{
    use ActsAsAppClient;
    use ForgetsGuardsBetweenRequests;
    use InteractsWithBoards;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        Notification::fake();
        config(['collab.url' => 'wss://collab.example.test/collab', 'collab.secret' => 'test-collab-secret', 'collab.e2e_opt_in' => false]);
    }

    private function headers(?User $user = null): array
    {
        return $this->appHeaders($this->appToken($user ?? $this->editor));
    }

    /** A published whiteboard, so every project member can open it. */
    private function whiteboard(array $elements = [], array $attributes = []): Page
    {
        return Page::factory()->published()->create($attributes + [
            'project_id' => $this->project->id, 'created_by' => $this->editor->id, 'name' => 'Retro', 'content' => $this->content($elements),
        ]);
    }

    /** @param  list<string|array<string, mixed>>  $elements */
    private function content(array $elements): array
    {
        return [
            'type' => 'excalidraw',
            'elements' => array_map(fn ($element) => is_array($element) ? $element : ['id' => $element, 'type' => 'rectangle'], $elements),
            'appState' => [],
            'files' => [],
            'libraryItems' => [],
        ];
    }

    /**
     * The scene save as a port of the browser's live.ts sends it (scene + vector as JSON strings).
     *
     * @param  list<string>  $elements
     */
    private function save(Page $page, array $elements, array $vector = ['1' => 1], ?User $user = null, int $epoch = 0, bool $baseline = false, bool $checkpoint = false): TestResponse
    {
        return $this->putJson("/api/v1/whiteboards/{$page->uuid}/collab/scene", [
            'epoch' => $epoch,
            'scene' => $baseline ? '{}' : json_encode(['elements' => $this->content($elements)['elements'], 'appState' => ['viewBackgroundColor' => '#ffffff'], 'files' => (object) []]),
            'vector' => json_encode($vector),
            'baseline' => $baseline,
            'checkpoint' => $checkpoint,
        ], $this->headers($user));
    }

    // ── token ──────────────────────────────────────────────────────────────────────────────────

    public function test_editors_get_an_edit_token_readers_a_view_token_with_the_web_session_fields(): void
    {
        $page = $this->whiteboard();
        $this->getJson("/api/v1/whiteboards/{$page->uuid}", $this->headers())->assertJsonPath('data.live', 'edit');
        $this->getJson("/api/v1/pages/{$page->uuid}", $this->headers())->assertJsonPath('data.live', 'edit');

        $response = $this->postJson("/api/v1/whiteboards/{$page->uuid}/collab-token", [], $this->headers())->assertOk()
            ->assertJsonPath('data.url', 'wss://collab.example.test/collab')
            ->assertJsonPath('data.document', "whiteboard.{$page->uuid}.0")
            ->assertJsonPath('data.epoch', 0)
            ->assertJsonPath('data.edit', true)
            ->assertJsonPath('data.vector', null)
            ->assertJsonPath('data.user.id', $this->editor->id)
            ->assertJsonPath('data.user.name', $this->editor->name);
        $this->assertSame(['url', 'document', 'epoch', 'token', 'expires_at', 'edit', 'vector', 'user'], array_keys($response->json('data')));
        $payload = CollabToken::verify($response->json('data.token'));
        $this->assertSame([$this->editor->id, "whiteboard.{$page->uuid}.0", true], [$payload['sub'], $payload['doc'], $payload['edit']]);
        $this->assertSame($payload['exp'], $response->json('data.expires_at'));

        // a read-only connection on request (the web's preview), even for an editor
        $view = $this->postJson("/api/v1/whiteboards/{$page->uuid}/collab-token", ['view' => true], $this->headers())->assertOk()->assertJsonPath('data.edit', false);
        $this->assertFalse(CollabToken::verify($view->json('data.token'))['edit']);

        // a whiteboard has no draft: its readers watch live
        $this->getJson("/api/v1/whiteboards/{$page->uuid}", $this->headers($this->viewer))->assertJsonPath('data.live', 'view');
        $this->getJson("/api/v1/pages/{$page->uuid}", $this->headers($this->viewer))->assertJsonPath('data.live', 'view');
        $this->postJson("/api/v1/whiteboards/{$page->uuid}/collab-token", [], $this->headers($this->viewer))->assertOk()->assertJsonPath('data.edit', false);

        // someone else's unpublished whiteboard / outsiders / unknown: 404; a Passport token is not an app token
        $draft = $this->whiteboard(attributes: ['status' => 'draft']);
        $this->postJson("/api/v1/whiteboards/{$draft->uuid}/collab-token", [], $this->headers($this->viewer))->assertNotFound();
        $this->postJson("/api/v1/whiteboards/{$page->uuid}/collab-token", [], $this->headers($this->outsider))->assertNotFound();
        $this->postJson('/api/v1/whiteboards/nope/collab-token', [], $this->headers())->assertNotFound();
        $this->postJson("/api/v1/whiteboards/{$page->uuid}/collab-token", [], $this->appHeaders($this->passportToken($this->editor)))->assertUnauthorized();
    }

    public function test_no_token_for_docs_folders_archived_whiteboards_or_without_a_realtime_server(): void
    {
        $doc = Page::factory()->published()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id, 'content' => ['type' => 'lexical', 'html' => '<p>x</p>']]);
        $folder = Page::factory()->folder()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id]);
        $archived = $this->whiteboard(attributes: ['status' => 'archived']);
        $this->postJson("/api/v1/whiteboards/{$doc->uuid}/collab-token", [], $this->headers())->assertForbidden()->assertJsonPath('code', 'forbidden');
        $this->postJson("/api/v1/whiteboards/{$folder->uuid}/collab-token", [], $this->headers())->assertForbidden();
        $this->postJson("/api/v1/whiteboards/{$archived->uuid}/collab-token", [], $this->headers())->assertForbidden();
        // a doc keeps its own `live` (PageCollab)
        $this->getJson("/api/v1/pages/{$doc->uuid}", $this->headers())->assertJsonPath('data.live', 'edit');

        config(['collab.secret' => null]);
        $page = $this->whiteboard();
        $this->postJson("/api/v1/whiteboards/{$page->uuid}/collab-token", [], $this->headers())->assertForbidden();
        $this->getJson("/api/v1/whiteboards/{$page->uuid}", $this->headers())->assertJsonPath('data.live', null);
        $this->getJson("/api/v1/pages/{$page->uuid}", $this->headers())->assertJsonPath('data.live', null);
    }

    // ── bootstrap + scene ──────────────────────────────────────────────────────────────────────

    public function test_bootstrap_then_baseline_then_saves_store_the_scene(): void
    {
        $page = $this->whiteboard(['a', 'b']);
        $revision = $page->edit_revision;

        $this->postJson("/api/v1/whiteboards/{$page->uuid}/collab/bootstrap", ['epoch' => 0, 'claim' => str_repeat('a', 20)], $this->headers())->assertOk()
            ->assertJsonPath('data.status', 'granted')
            ->assertJsonPath('data.elements.0.id', 'a')
            ->assertJsonPath('data.elements.1.id', 'b')
            ->assertJsonPath('data.reset', false);
        $this->postJson("/api/v1/whiteboards/{$page->uuid}/collab/bootstrap", ['epoch' => 0, 'claim' => str_repeat('b', 20)], $this->headers())->assertOk()
            ->assertJsonPath('data.status', 'wait')->assertJsonPath('data.elements', null);

        // the claimant reports the vector of what it loaded: nothing is written to the page
        $this->save($page, [], ['5' => 2], baseline: true)->assertOk()->assertJsonPath('data.status', 'baseline');
        $this->assertSame($revision, $page->fresh()->edit_revision);
        $this->assertSame(['5' => 2], PageCollabState::where('page_id', $page->id)->value('html_vector'));

        $response = $this->save($page, ['a', 'b', 'c'], ['5' => 3])->assertOk()
            ->assertJsonPath('data.status', 'saved')
            ->assertJsonPath('data.edit_revision', $revision + 1)
            ->assertJsonPath('data.version', null);
        $this->assertSame(['status', 'edit_revision', 'version'], array_keys($response->json('data')));
        // an older or concurrent snapshot never overwrites the newer one
        $this->save($page, ['a'], ['5' => 2, '6' => 1])->assertOk()->assertJsonPath('data.status', 'covered');
        // Save: a checkpoint even when the scene is stored
        $versions = $page->versions()->count();
        $this->save($page, ['a', 'b', 'c'], ['5' => 3], checkpoint: true)->assertOk()->assertJsonPath('data.status', 'covered')->assertJsonPath('data.version', $versions + 1);

        $page->refresh();
        $this->assertSame(['a', 'b', 'c'], array_column($page->content['elements'], 'id'));
        $this->assertSame($this->editor->id, $page->last_edited_by);
        $this->assertSame(0, PageCollabState::where('page_id', $page->id)->value('epoch'), 'a live save never resets the live document');

        // the next token carries the stored vector; the scene endpoint also takes objects instead of JSON strings
        $this->postJson("/api/v1/whiteboards/{$page->uuid}/collab-token", [], $this->headers())->assertJsonPath('data.vector', ['5' => 3]);
        $this->putJson("/api/v1/whiteboards/{$page->uuid}/collab/scene", [
            'epoch' => 0, 'scene' => ['elements' => [['id' => 'd', 'type' => 'ellipse']], 'appState' => []], 'vector' => ['5' => 4],
        ], $this->headers())->assertOk()->assertJsonPath('data.status', 'saved');
        $this->assertSame(['d'], array_column($page->fresh()->content['elements'], 'id'));
    }

    public function test_save_and_exit_sends_the_preview_as_multipart_post_with_method_put(): void
    {
        Storage::fake('public');
        $page = $this->whiteboard();

        $this->post("/api/v1/whiteboards/{$page->uuid}/collab/scene", [
            '_method' => 'PUT',
            'epoch' => '0',
            'scene' => json_encode(['elements' => [['id' => 'a', 'type' => 'rectangle']], 'appState' => []]),
            'vector' => json_encode(['1' => 1]),
            'checkpoint' => '1',
            'image' => UploadedFile::fake()->image('preview.png'),
        ], $this->headers() + ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.status', 'saved');

        $this->assertStringContainsString('/storage/excalidraw-previews/', $page->fresh()->content['previewImageUrl']);
    }

    public function test_a_replaced_document_readers_and_bad_input_are_refused(): void
    {
        $page = $this->whiteboard();
        $this->postJson("/api/v1/whiteboards/{$page->uuid}/collab-token", [], $this->headers())->assertOk();

        // the scene changed outside the live document (the web's single-user editor, a version restore): a new one
        $page->fresh()->saveWhiteboard($this->editor, $this->content(['solo']));
        $this->save($page, ['late'], ['1' => 1], epoch: 0)->assertStatus(409)
            ->assertJsonPath('code', 'conflict')->assertJsonPath('reset', true)->assertJsonPath('epoch', 1);
        $this->postJson("/api/v1/whiteboards/{$page->uuid}/collab/bootstrap", ['epoch' => 0, 'claim' => str_repeat('a', 20)], $this->headers())
            ->assertStatus(409)->assertJsonPath('reset', true)->assertJsonPath('epoch', 1);
        $this->postJson("/api/v1/whiteboards/{$page->uuid}/collab-token", [], $this->headers())->assertJsonPath('data.document', "whiteboard.{$page->uuid}.1");
        $this->assertSame(['solo'], array_column($page->fresh()->content['elements'], 'id'));

        // bad input
        $this->putJson("/api/v1/whiteboards/{$page->uuid}/collab/scene", ['epoch' => 1, 'scene' => '{"elements": "nope"}', 'vector' => '{"1":1}'], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('scene');
        $this->putJson("/api/v1/whiteboards/{$page->uuid}/collab/scene", ['epoch' => 1, 'scene' => '{"elements": []}', 'vector' => '{"a":"b"}'], $this->headers())
            ->assertUnprocessable();
        $this->putJson("/api/v1/whiteboards/{$page->uuid}/collab/scene", ['epoch' => 1], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors(['scene', 'vector']);
        $this->postJson("/api/v1/whiteboards/{$page->uuid}/collab/bootstrap", ['epoch' => 1, 'claim' => 'short'], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('claim');

        // readers watch only, outsiders don't see it, documents are never written as whiteboards
        $this->save($page, ['x'], ['1' => 1], $this->viewer, epoch: 1)->assertForbidden();
        $this->postJson("/api/v1/whiteboards/{$page->uuid}/collab/bootstrap", ['epoch' => 1, 'claim' => str_repeat('v', 20)], $this->headers($this->viewer))->assertForbidden();
        $this->save($page, ['x'], ['1' => 1], $this->outsider, epoch: 1)->assertNotFound();
        $doc = Page::factory()->published()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id, 'content' => ['type' => 'lexical', 'html' => '<p>x</p>']]);
        $this->save($doc, ['x'])->assertForbidden();
        $this->postJson("/api/v1/whiteboards/{$doc->uuid}/collab/bootstrap", ['epoch' => 0, 'claim' => str_repeat('d', 20)], $this->headers())->assertForbidden();
        $this->assertSame('lexical', $doc->fresh()->content['type']);

        // live editing switched off: no saves either
        config(['collab.secret' => null]);
        $this->save($page, ['x'], ['1' => 9], epoch: 1)->assertForbidden();
    }

    public function test_the_throttles_match_the_web(): void
    {
        $throttles = [
            'app.v1.whiteboards.collab.token' => 'throttle:60,1,app.whiteboards.collab.token',
            'app.v1.whiteboards.collab.bootstrap' => 'throttle:30,1,app.whiteboards.collab.bootstrap',
            'app.v1.whiteboards.collab.scene' => 'throttle:120,1,app.whiteboards.collab.scene',
        ];
        foreach ($throttles as $name => $throttle) {
            $this->assertContains($throttle, Route::getRoutes()->getByName($name)->gatherMiddleware(), $name);
        }
        // the web's limits
        $this->assertContains('throttle:60,1,pages.whiteboard.collab.session', Route::getRoutes()->getByName('pages.whiteboard.collab.session')->gatherMiddleware());
        $this->assertContains('throttle:30,1,pages.whiteboard.collab.bootstrap', Route::getRoutes()->getByName('pages.whiteboard.collab.bootstrap')->gatherMiddleware());
        $this->assertContains('throttle:120,1,pages.whiteboard.collab.save', Route::getRoutes()->getByName('pages.whiteboard.collab.save')->gatherMiddleware());
    }

    // ── revoked devices (BRAI-377) ────────────────────────────────────────────────────────────

    public function test_the_token_carries_the_device_and_the_server_learns_when_it_is_revoked(): void
    {
        $page = $this->whiteboard();
        $tablet = $this->appDevice($this->editor, ['client' => 'mobile', 'platform' => 'android']);

        $token = $this->postJson("/api/v1/whiteboards/{$page->uuid}/collab-token", [], $this->appHeaders($this->appToken($this->editor, $tablet)))
            ->assertOk()->json('data.token');
        $this->assertSame($tablet->id, CollabToken::verify($token)['dev'], 'App API tokens are bound to the device');

        $access = fn () => $this->postJson(route('internal.collab.access'), [
            'document' => "whiteboard.{$page->uuid}.0",
            'user_ids' => [$this->editor->id],
            'devices' => [['id' => $tablet->id, 'user_id' => $this->editor->id]],
        ], ['Authorization' => 'Bearer '.CollabConfig::internalKey()]);

        $access()->assertOk()->assertJsonPath('revoked_devices', [])->assertJsonPath("users.{$this->editor->id}", 'edit');
        AppTokens::revoke($tablet, 'user');
        // the realtime server closes its sockets with 4410
        $access()->assertOk()->assertJsonPath('revoked_devices', [$tablet->id]);
    }
}
