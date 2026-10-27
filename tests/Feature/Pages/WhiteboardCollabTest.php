<?php

namespace Tests\Feature\Pages;

use App\Models\Board;
use App\Models\ContentReference;
use App\Models\Item;
use App\Models\Page;
use App\Models\PageCollabState;
use App\Support\Collab\CollabConfig;
use App\Support\Collab\CollabDocument;
use App\Support\Collab\CollabToken;
use App\Support\Collab\WhiteboardCollab;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Live whiteboards (BRAI-153): session tokens for `whiteboard.<uuid>.<epoch>`, the internal API's kind
 * check, the one-time bootstrap, storing the live scene as the whiteboard content (checkpoints, derived
 * issue-card references, images) and the reset when the scene changes elsewhere.
 * Plan: docs/ai/tasks/feat-live-whiteboards.md.
 */
class WhiteboardCollabTest extends TestCase
{
    use InteractsWithPages;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPages();
        Notification::fake();
        config(['collab.url' => 'wss://collab.example.test/collab', 'collab.secret' => 'test-collab-secret', 'collab.e2e_opt_in' => false]);
    }

    // ── session / access ───────────────────────────────────────────────────────────────────────

    public function test_editors_get_an_edit_token_for_the_whiteboard_document(): void
    {
        $page = $this->whiteboard();

        $response = $this->liveSession($page, $this->editor)->assertOk()
            ->assertJsonPath('document', "whiteboard.{$page->uuid}.0")
            ->assertJsonPath('epoch', 0)
            ->assertJsonPath('edit', true)
            ->assertJsonPath('vector', null)
            ->assertJsonPath('user.name', $this->editor->name);

        $payload = CollabToken::verify($response->json('token'));
        $this->assertSame("whiteboard.{$page->uuid}.0", $payload['doc']);
        $this->assertTrue($payload['edit']);

        // the page preview connects read-only, even for an editor
        $preview = $this->liveSession($page, $this->editor, ['view' => true])->assertOk()->assertJsonPath('edit', false);
        $this->assertFalse(CollabToken::verify($preview->json('token'))['edit']);
    }

    public function test_readers_watch_live_outsiders_documents_and_archived_pages_get_nothing(): void
    {
        $page = $this->whiteboard();
        // a whiteboard has no draft: its readers already see the live scene, so they may watch live
        $this->liveSession($page, $this->viewer)->assertOk()->assertJsonPath('edit', false);
        $this->assertSame('view', WhiteboardCollab::access($page, $this->viewer));

        $this->liveSession($page, $this->outsider)->assertForbidden();
        // someone else's unpublished whiteboard isn't visible to a project editor
        $this->liveSession($this->whiteboard(['status' => 'draft']), $this->editor)->assertForbidden();
        $this->liveSession($this->whiteboard(['status' => 'archived']), $this->author)->assertForbidden();
        // documents never open as whiteboards (and the docs session refuses whiteboards)
        $this->liveSession($this->publishedPage(), $this->editor)->assertForbidden();
        $this->actingAs($this->editor)->postJson($this->pageRoute('pages.collab.session', $page))->assertForbidden();

        $this->editor->forceFill(['suspended_at' => now()])->save();
        $this->assertNull(WhiteboardCollab::access($page, $this->editor->fresh()));
    }

    public function test_viewers_can_neither_save_nor_bootstrap(): void
    {
        $page = $this->whiteboard();

        $this->save($page, ['a'], user: $this->viewer)->assertForbidden();
        $this->bootstrap($page, str_repeat('v', 20), $this->viewer)->assertForbidden();
        $this->save($this->publishedPage(), ['a'])->assertForbidden();
    }

    public function test_off_without_url_and_secret_and_the_editor_config_says_so(): void
    {
        $page = $this->whiteboard();

        $editor = $this->actingAs($this->editor)->get($this->pageRoute('pages.excalidraw', $page))->assertOk();
        $live = $editor->viewData('excalidrawConfig')['live'];
        $this->assertSame(0, $live['epoch']);
        $this->assertSame('wss://collab.example.test/collab', $live['url']);
        $this->assertSame($this->pageRoute('pages.whiteboard.collab.save', $page), $live['saveUrl']);
        $this->assertSame(['id' => $this->editor->id, 'name' => $this->editor->name], $live['user']);
        $this->actingAs($this->viewer)->get($this->pageRoute('pages.show', $page))
            ->assertInertia(fn ($inertia) => $inertia->where('whiteboardCollab.epoch', 0)->where('collab', null));

        config(['collab.secret' => null]);
        $this->liveSession($page, $this->editor)->assertNotFound();
        $this->assertNull($this->actingAs($this->editor)->get($this->pageRoute('pages.excalidraw', $page))->viewData('excalidrawConfig')['live']);
        $this->actingAs($this->viewer)->get($this->pageRoute('pages.show', $page))
            ->assertInertia(fn ($inertia) => $inertia->where('whiteboardCollab', null));
    }

    // ── internal API (realtime server) ─────────────────────────────────────────────────────────

    public function test_the_internal_api_serves_whiteboard_documents_of_whiteboards_only(): void
    {
        $page = $this->whiteboard();
        $doc = $this->publishedPage();
        $board = CollabDocument::name($page->uuid, 0, CollabDocument::WHITEBOARD);
        $state = base64_encode('whiteboard-state');

        $this->putJson(route('internal.collab.documents.store', $board), ['state' => $state], $this->internal())->assertNoContent();
        $this->getJson(route('internal.collab.documents.show', $board), $this->internal())->assertOk()->assertJsonPath('state', $state);

        // the kind must match the page: a doc's name for a whiteboard (and the other way round) is unknown
        $this->getJson(route('internal.collab.documents.show', CollabDocument::name($page->uuid, 0)), $this->internal())->assertNotFound();
        $this->getJson(route('internal.collab.documents.show', CollabDocument::name($doc->uuid, 0, CollabDocument::WHITEBOARD)), $this->internal())->assertNotFound();

        $this->postJson(route('internal.collab.access'), ['document' => $board, 'user_ids' => [$this->editor->id, $this->viewer->id, $this->outsider->id]], $this->internal())
            ->assertOk()
            ->assertJsonPath('current', true)
            ->assertJsonPath("users.{$this->editor->id}", 'edit')
            ->assertJsonPath("users.{$this->viewer->id}", 'view')
            ->assertJsonPath("users.{$this->outsider->id}", null);
    }

    // ── bootstrap ──────────────────────────────────────────────────────────────────────────────

    public function test_one_editor_gets_the_stored_elements_for_an_empty_live_whiteboard(): void
    {
        $page = $this->whiteboard(['content' => $this->content(['a', 'b'])]);

        $this->bootstrap($page, str_repeat('a', 20))->assertOk()
            ->assertJsonPath('status', 'granted')
            ->assertJsonPath('elements.0.id', 'a')
            ->assertJsonPath('elements.1.id', 'b');
        $this->bootstrap($page, str_repeat('b', 20), $this->author)->assertOk()->assertJsonPath('status', 'wait');
        $this->bootstrap($page, str_repeat('a', 20), epoch: 3)->assertStatus(409)->assertJsonPath('reset', true);

        // the claimant reports the vector of what it loaded: nothing is written to the page
        $revision = $page->fresh()->edit_revision;
        $this->save($page, ['a', 'b'], ['5' => 2], baseline: true)->assertOk()->assertJsonPath('status', 'baseline');
        $this->assertSame($revision, $page->fresh()->edit_revision);
        $this->assertSame(['5' => 2], PageCollabState::where('page_id', $page->id)->value('html_vector'));
    }

    // ── saving the live scene ──────────────────────────────────────────────────────────────────

    public function test_the_live_scene_is_stored_like_a_whiteboard_save_with_derived_issue_cards(): void
    {
        $board = Board::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->author->id]);
        $item = Item::factory()->create(['board_id' => $board->id, 'project_id' => $this->project->id, 'column_id' => $board->columns()->value('id'), 'created_by' => $this->author->id]);
        $page = $this->whiteboard();
        $revision = $page->edit_revision;
        $card = ['id' => 'card', 'type' => 'rectangle', 'customData' => ['brainitive' => 'issue', 'itemId' => $item->id]];

        $this->save($page, ['a', $card], ['1' => 3])->assertOk()
            ->assertJsonPath('status', 'saved')
            ->assertJsonPath('edit_revision', $revision + 1);

        $page->refresh();
        $this->assertSame('excalidraw', $page->content['type']);
        $this->assertSame(['a', 'card'], array_column($page->content['elements'], 'id'));
        $this->assertTrue($page->is_whiteboard);
        $this->assertSame($this->editor->id, $page->last_edited_by);
        // Page::saved: the card is a derived reference, like with the single-user editor
        $this->assertSame(1, ContentReference::where(['source_id' => $page->id, 'target_id' => $item->id, 'origin' => 'derived'])->count());
        // a live save never resets the live document it came from
        $this->assertSame(0, PageCollabState::where('page_id', $page->id)->value('epoch'));

        // the same scene again (other key order, as read back from the database) writes nothing
        $this->save($page, [$card, 'a'], ['1' => 4])->assertOk()->assertJsonPath('status', 'saved');
        $this->save($page, [$card, 'a'], ['1' => 5])->assertOk()->assertJsonPath('status', 'unchanged');
        // the card is removed live: so is the derived reference
        $this->save($page, ['a'], ['1' => 6])->assertOk()->assertJsonPath('status', 'saved');
        $this->assertSame(0, ContentReference::where('origin', 'derived')->count());
    }

    public function test_older_or_concurrent_snapshots_are_covered(): void
    {
        $page = $this->whiteboard();
        $this->save($page, ['a', 'b'], ['1' => 5, '2' => 3])->assertJsonPath('status', 'saved');

        $this->save($page, ['a'], ['1' => 4, '2' => 3])->assertJsonPath('status', 'covered');
        // a browser whose live document is still empty never stores that
        $this->save($this->whiteboard(['content' => $this->content(['kept'])]), [], [])->assertJsonPath('status', 'covered');
        $this->save($page, ['x'], ['1' => 6])->assertJsonPath('status', 'covered');
        $this->assertSame(['a', 'b'], array_column($page->fresh()->content['elements'], 'id'));

        $this->save($page, ['a', 'b', 'c'], ['1' => 6, '2' => 3])->assertJsonPath('status', 'saved');
        $this->assertCount(3, $page->fresh()->content['elements']);
    }

    public function test_images_only_grow_so_a_browser_that_has_not_loaded_one_never_drops_it(): void
    {
        $page = $this->whiteboard();
        $image = ['id' => 'img', 'type' => 'image', 'fileId' => 'f1'];
        $file = ['dataURL' => 'data:image/png;base64,AAAA', 'mimeType' => 'image/png', 'created' => 1];

        $this->save($page, ['a', $image], ['1' => 1], files: ['f1' => $file])->assertJsonPath('status', 'saved');
        // the leader hasn't downloaded the image yet: it sends the scene without the file
        $this->save($page, ['a', $image, 'b'], ['1' => 2])->assertJsonPath('status', 'saved');

        $files = $page->fresh()->content['files'];
        $this->assertSame('data:image/png;base64,AAAA', $files['f1']['dataURL']);
        $this->assertSame('f1', $files['f1']['id']);

        // only image data URLs are files
        $this->save($page, ['a'], ['1' => 3], files: ['f2' => ['dataURL' => 'https://evil.test/x.png']])->assertStatus(422);
        $this->save($page, ['a'], ['1' => 3], files: ['f2' => ['dataURL' => 'data:text/html;base64,PHNjcmlwdD4=', 'mimeType' => 'image/png']])->assertStatus(422);
        $this->save($page, ['a'], ['1' => 3], raw: '{"elements": "nope"}')->assertStatus(422);
    }

    public function test_live_saves_record_checkpoints_by_interval_or_on_explicit_save_only(): void
    {
        $page = $this->whiteboard();
        $versions = $page->versions()->count();

        // two people drawing take turns saving: no version per save
        $this->save($page, ['a'], ['1' => 1], user: $this->editor)->assertJsonPath('status', 'saved');
        $this->save($page, ['a', 'b'], ['1' => 1, '2' => 1], user: $this->author)->assertJsonPath('status', 'saved');
        $this->save($page, ['a', 'b', 'c'], ['1' => 2, '2' => 1], user: $this->editor)->assertJsonPath('version', null);
        $this->assertSame($versions, $page->versions()->count());

        // "Save" records one, even when the scene is already stored
        $this->save($page, ['a', 'b', 'c'], ['1' => 2, '2' => 1], checkpoint: true)->assertJsonPath('status', 'covered')->assertJsonPath('version', $versions + 1);
        $this->save($page, ['a', 'b', 'c'], ['1' => 3, '2' => 1], checkpoint: true)->assertJsonPath('status', 'unchanged')->assertJsonPath('version', $versions + 2);

        // … and after the interval the next live save does
        $this->travel(Page::WHITEBOARD_VERSION_INTERVAL_MINUTES + 1)->minutes();
        $this->save($page, ['a', 'b', 'c', 'd'], ['1' => 4, '2' => 1])->assertJsonPath('version', $versions + 3);
        $this->assertCount(4, $page->versions()->latest('version')->first()->content['elements']);
    }

    public function test_save_and_exit_stores_the_preview_image(): void
    {
        Storage::fake('public');
        $page = $this->whiteboard();

        $this->actingAs($this->editor)->post($this->pageRoute('pages.whiteboard.collab.save', $page), [
            'epoch' => 0,
            'scene' => json_encode(['elements' => [['id' => 'a', 'type' => 'rectangle']], 'appState' => []]),
            'vector' => json_encode(['1' => 1]),
            'checkpoint' => '1',
            'image' => UploadedFile::fake()->image('preview.png'),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('status', 'saved');

        $this->assertStringContainsString('/storage/excalidraw-previews/', $page->fresh()->content['previewImageUrl']);
        $this->assertSame(0, PageCollabState::where('page_id', $page->id)->value('epoch'));
    }

    // ── reset ──────────────────────────────────────────────────────────────────────────────────

    public function test_a_scene_changed_outside_the_live_document_starts_a_new_one(): void
    {
        $page = $this->whiteboard();
        $empty = $page->recordVersion($this->editor);
        $this->liveSession($page, $this->editor)->assertOk();

        // the single-user editor (no live server / fallback) saves the scene
        $this->actingAs($this->editor)->postJson($this->pageRoute('pages.excalidraw.save', $page), [
            'diagram_data' => json_encode($this->content(['solo'])),
            'base_revision' => $page->fresh()->edit_revision,
        ])->assertOk();
        $this->assertSame(1, PageCollabState::where('page_id', $page->id)->value('epoch'));
        $this->liveSession($page, $this->editor)->assertJsonPath('document', "whiteboard.{$page->uuid}.1");

        // a live save on the old document is refused; the browser reopens
        $this->save($page, ['late'], ['1' => 1], epoch: 0)->assertStatus(409)->assertJsonPath('reset', true);
        $this->assertSame(['solo'], array_column($page->fresh()->content['elements'], 'id'));

        // restoring a version replaces the scene too
        $this->actingAs($this->editor)->post($this->pageRoute('pages.versions.restore', $page, [$empty->version]))->assertRedirect();
        $this->assertSame(2, PageCollabState::where('page_id', $page->id)->value('epoch'));

        // renaming doesn't touch the scene
        $page->fresh()->update(['name' => 'Renamed']);
        $this->assertSame(2, PageCollabState::where('page_id', $page->id)->value('epoch'));
    }

    public function test_review_required_documents_have_no_side_door(): void
    {
        $doc = $this->publishedPage(['review_required' => true]);

        // the whiteboard endpoints never write a scene over a document
        $this->save($doc, ['a'], ['1' => 1])->assertForbidden();
        $this->bootstrap($doc, str_repeat('d', 20))->assertForbidden();
        $this->assertSame('lexical', $doc->fresh()->content['type']);

        // a review-required whiteboard keeps today's rule (whiteboards are live, outside the gate)
        $page = $this->whiteboard(['review_required' => true]);
        $this->save($page, ['a'], ['1' => 1])->assertOk()->assertJsonPath('status', 'saved');
    }

    // ── helpers ────────────────────────────────────────────────────────────────────────────────

    /**
     * A published whiteboard, so every project member can open it.
     */
    private function whiteboard(array $attributes = []): Page
    {
        return $this->publishedPage($attributes + ['name' => 'Retro', 'content' => $this->content([])]);
    }

    /**
     * @param  list<string|array<string, mixed>>  $elements
     * @return array<string, mixed>
     */
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

    private function liveSession(Page $page, $user, array $data = []): TestResponse
    {
        return $this->actingAs($user)->postJson($this->pageRoute('pages.whiteboard.collab.session', $page), $data);
    }

    /**
     * @param  list<string|array<string, mixed>>  $elements
     * @param  array<string, int>  $vector
     * @param  array<string, array<string, mixed>>  $files
     */
    private function save(Page $page, array $elements, array $vector = ['1' => 1], $user = null, int $epoch = 0, bool $baseline = false, bool $checkpoint = false, array $files = [], ?string $raw = null): TestResponse
    {
        $scene = ['elements' => $this->content($elements)['elements'], 'appState' => ['viewBackgroundColor' => '#ffffff'], 'files' => (object) $files];

        return $this->actingAs($user ?? $this->editor)->postJson($this->pageRoute('pages.whiteboard.collab.save', $page), [
            'epoch' => $epoch,
            'scene' => $raw ?? json_encode($scene),
            'vector' => json_encode($vector),
            'baseline' => $baseline,
            'checkpoint' => $checkpoint,
        ]);
    }

    private function bootstrap(Page $page, string $claim, $user = null, int $epoch = 0): TestResponse
    {
        return $this->actingAs($user ?? $this->editor)->postJson($this->pageRoute('pages.whiteboard.collab.bootstrap', $page), ['epoch' => $epoch, 'claim' => $claim]);
    }

    /**
     * @return array<string, string>
     */
    private function internal(): array
    {
        return ['Authorization' => 'Bearer '.CollabConfig::internalKey()];
    }
}
