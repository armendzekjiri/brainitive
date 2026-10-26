<?php

namespace Tests\Feature\Pages;

use App\Models\Page;
use App\Models\PageCollabState;
use App\Support\Collab\CollabConfig;
use App\Support\Collab\CollabDocument;
use App\Support\Collab\CollabToken;
use App\Support\Collab\PageCollab;
use App\Support\Collab\StateVector;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Live co-editing of documents (BRAI-54): connection tokens, the realtime server's internal API,
 * saving the live document's HTML as the draft, the one-time bootstrap and the reset of the live
 * document when the draft is replaced from elsewhere. Plan: docs/ai/tasks/feat-live-docs.md.
 */
class PageCollabTest extends TestCase
{
    use InteractsWithPageReviews;
    use InteractsWithPages;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPages();
        Notification::fake();
        config(['collab.url' => 'wss://collab.example.test/collab', 'collab.secret' => 'test-collab-secret', 'collab.e2e_opt_in' => false]);
    }

    // ── session / token ────────────────────────────────────────────────────────────────────────

    public function test_editors_get_an_edit_token_for_the_current_document(): void
    {
        $page = $this->publishedPage();

        $response = $this->collabSession($page, $this->editor)->assertOk()
            ->assertJsonPath('url', 'wss://collab.example.test/collab')
            ->assertJsonPath('document', "page.{$page->uuid}.0")
            ->assertJsonPath('epoch', 0)
            ->assertJsonPath('edit', true)
            ->assertJsonPath('user.id', $this->editor->id);

        $payload = CollabToken::verify($response->json('token'));
        $this->assertNotNull($payload);
        $this->assertSame($this->editor->id, $payload['sub']);
        $this->assertSame("page.{$page->uuid}.0", $payload['doc']);
        $this->assertTrue($payload['edit']);
        $this->assertSame($payload['exp'], $response->json('expires_at'));
        $this->assertDatabaseHas('page_collab_states', ['page_id' => $page->id, 'epoch' => 0]);
        $this->assertNull($response->json('html_vector'), 'nothing saved from the live document yet');
    }

    public function test_a_reader_of_an_unpublished_page_gets_a_view_only_token(): void
    {
        // the author lost the editor role: still sees the never-published page (= the draft), can't edit
        $page = $this->draftPage(['created_by' => $this->viewer->id]);

        $response = $this->collabSession($page, $this->viewer)->assertOk()->assertJsonPath('edit', false);
        $this->assertFalse(CollabToken::verify($response->json('token'))['edit']);
    }

    public function test_readers_of_published_pages_outsiders_and_whiteboards_get_no_token(): void
    {
        $published = $this->publishedPage();
        // readers keep seeing the published version, never the live draft
        $this->collabSession($published, $this->viewer)->assertForbidden();
        $this->collabSession($published, $this->outsider)->assertForbidden();
        // someone else's unpublished page isn't visible to a project editor
        $this->collabSession($this->draftPage(), $this->editor)->assertForbidden();

        $whiteboard = $this->draftPage(['content' => ['type' => 'excalidraw', 'elements' => [], 'appState' => []]]);
        $this->collabSession($whiteboard, $this->author)->assertForbidden();
        $this->collabSession($this->draftPage(['status' => 'archived']), $this->author)->assertForbidden();

        // suspended accounts get nothing (and the server's access check drops them)
        $this->editor->forceFill(['suspended_at' => now()])->save();
        $this->assertNull(PageCollab::access($published, $this->editor->fresh()));
    }

    public function test_live_editing_is_off_without_url_and_secret(): void
    {
        $page = $this->draftPage();
        config(['collab.secret' => null]);

        $this->collabSession($page, $this->author)->assertNotFound();
        $this->actingAs($this->author)->get($this->pageRoute('pages.show', $page))
            ->assertInertia(fn ($inertia) => $inertia->where('collab', null));
    }

    public function test_the_page_prop_tells_the_browser_whether_and_which_document_to_open(): void
    {
        $page = $this->publishedPage();

        $this->actingAs($this->editor)->get($this->pageRoute('pages.show', $page))
            ->assertInertia(fn ($inertia) => $inertia->where('collab.epoch', 0)->where('collab.url', 'wss://collab.example.test/collab'));
        $this->actingAs($this->viewer)->get($this->pageRoute('pages.show', $page))
            ->assertInertia(fn ($inertia) => $inertia->where('collab', null));
    }

    public function test_e2e_opt_in_needs_the_header(): void
    {
        config(['collab.e2e_opt_in' => true]);
        $this->app['env'] = 'e2e';
        $this->withoutMiddleware(ValidateCsrfToken::class); // CSRF is only skipped in the "testing" env
        $page = $this->draftPage();

        $this->collabSession($page, $this->author)->assertNotFound();
        $this->actingAs($this->author)->postJson($this->pageRoute('pages.collab.session', $page), [], ['X-E2E-Collab' => '1'])->assertOk();
    }

    public function test_tokens_expire_and_cannot_be_forged(): void
    {
        $page = $this->draftPage();
        $issued = CollabToken::issue($this->author, CollabDocument::name($page->uuid, 0), true, 1_000);

        $this->assertNotNull(CollabToken::verify($issued['token'], 1_000 + CollabConfig::tokenTtl() - 1));
        $this->assertNull(CollabToken::verify($issued['token'], 1_000 + CollabConfig::tokenTtl()));

        [$payload, $signature] = explode('.', $issued['token']);
        $forged = rtrim(strtr(base64_encode((string) json_encode(['v' => 1, 'sub' => $this->admin->id, 'name' => 'x', 'doc' => 'page.x.0', 'edit' => true, 'exp' => 9_999_999_999])), '+/', '-_'), '=');
        $this->assertNull(CollabToken::verify($forged.'.'.$signature, 1_000));
        $this->assertNull(CollabToken::verify($payload.'.'.$signature.'x', 1_000));

        // another secret (another instance) → invalid
        config(['collab.secret' => 'other-secret']);
        $this->assertNull(CollabToken::verify($issued['token'], 1_000));
    }

    // ── internal API (realtime server) ─────────────────────────────────────────────────────────

    public function test_the_internal_api_needs_the_derived_bearer_key(): void
    {
        $page = $this->draftPage();
        $url = route('internal.collab.documents.show', CollabDocument::name($page->uuid, 0));

        $this->getJson($url)->assertUnauthorized();
        $this->getJson($url, ['Authorization' => 'Bearer test-collab-secret'])->assertUnauthorized();
        // a browser token is never an internal key
        $token = CollabToken::issue($this->admin, CollabDocument::name($page->uuid, 0), true)['token'];
        $this->getJson($url, ['Authorization' => "Bearer {$token}"])->assertUnauthorized();
        $this->getJson($url, $this->internal())->assertOk()->assertJsonPath('state', null);

        config(['collab.url' => null]);
        $this->getJson($url, $this->internal())->assertNotFound();
    }

    public function test_the_server_stores_and_loads_the_yjs_state(): void
    {
        $page = $this->draftPage();
        $document = CollabDocument::name($page->uuid, 0);
        $state = base64_encode("\x01\x02yjs-update\x00\xff");

        $this->putJson(route('internal.collab.documents.store', $document), ['state' => $state], $this->internal())->assertNoContent();
        $this->getJson(route('internal.collab.documents.show', $document), $this->internal())->assertOk()->assertJsonPath('state', $state);
        $this->assertSame(14, PageCollabState::where('page_id', $page->id)->value('state_bytes'));

        $this->putJson(route('internal.collab.documents.store', $document), ['state' => 'not base64!'], $this->internal())->assertStatus(422);
        config(['collab.max_state_bytes' => 1024]);
        $this->putJson(route('internal.collab.documents.store', $document), ['state' => base64_encode(str_repeat('x', 2048))], $this->internal())->assertStatus(413);

        // unknown page / not a document name
        $this->getJson(route('internal.collab.documents.show', CollabDocument::name('00000000-0000-4000-8000-000000000000', 0)), $this->internal())->assertNotFound();
        $this->getJson(route('internal.collab.documents.show', 'board.1'), $this->internal())->assertNotFound();
    }

    public function test_a_replaced_draft_makes_the_old_document_stale(): void
    {
        $page = $this->publishedPage();
        $old = CollabDocument::name($page->uuid, 0);
        $this->putJson(route('internal.collab.documents.store', $old), ['state' => base64_encode('state')], $this->internal())->assertNoContent();
        $this->collabSave($page, '<p>Live change</p>')->assertOk();

        // discarding the draft replaces it → a new Y document (epoch 1), the old one can't be stored
        $this->actingAs($this->author)->delete($this->pageRoute('pages.draft.discard', $page))->assertRedirect();

        $this->assertSame(1, PageCollabState::where('page_id', $page->id)->value('epoch'));
        $this->assertNull(PageCollabState::where('page_id', $page->id)->value('state'));
        $this->putJson(route('internal.collab.documents.store', $old), ['state' => base64_encode('late')], $this->internal())->assertStatus(409);
        $this->getJson(route('internal.collab.documents.show', $old), $this->internal())->assertStatus(409)->assertJsonPath('epoch', 1);
        $this->getJson(route('internal.collab.documents.show', CollabDocument::name($page->uuid, 1)), $this->internal())->assertOk()->assertJsonPath('state', null);
        $this->collabSession($page, $this->editor)->assertJsonPath('document', "page.{$page->uuid}.1");
    }

    public function test_the_server_re_checks_who_may_stay_connected(): void
    {
        $page = $this->publishedPage();
        $document = CollabDocument::name($page->uuid, 0);
        $this->collabSession($page, $this->editor);

        $this->postJson(route('internal.collab.access'), ['document' => $document, 'user_ids' => [$this->editor->id, $this->viewer->id, $this->outsider->id, 999_999]], $this->internal())
            ->assertOk()
            ->assertJsonPath('current', true)
            ->assertJsonPath("users.{$this->editor->id}", 'edit')
            ->assertJsonPath("users.{$this->viewer->id}", null)
            ->assertJsonPath("users.{$this->outsider->id}", null)
            ->assertJsonPath('users.999999', null);

        // removed from the project → no longer allowed
        $this->project->users()->detach($this->editor->id);
        $this->postJson(route('internal.collab.access'), ['document' => $document, 'user_ids' => [$this->editor->id]], $this->internal())
            ->assertJsonPath("users.{$this->editor->id}", null);

        $this->postJson(route('internal.collab.access'), ['document' => $document, 'user_ids' => []], [])->assertUnauthorized();

        // the draft was replaced: the old document is no longer current
        $this->actingAs($this->admin)->putJson($this->pageRoute('pages.draft.save', $page), ['content' => json_encode($this->html('<p>Replaced</p>'))])->assertOk();
        $this->postJson(route('internal.collab.access'), ['document' => $document, 'user_ids' => [$this->admin->id]], $this->internal())
            ->assertOk()->assertJsonPath('current', false)->assertJsonPath("users.{$this->admin->id}", 'edit');
    }

    // ── saving the live document as the draft ──────────────────────────────────────────────────

    public function test_a_live_save_writes_the_draft_like_an_autosave(): void
    {
        $page = $this->publishedPage();

        $this->collabSave($page, '<p>Together</p>', ['1' => 5, '2' => 3])->assertOk()
            ->assertJsonPath('status', 'saved')
            ->assertJsonPath('has_unpublished_changes', true)
            ->assertJsonPath('edit_revision', 1)
            ->assertJsonPath('draft_updated_by.id', $this->editor->id);

        $page->refresh();
        $this->assertSame('<p>Together</p>', $page->draft_content['html']);
        $this->assertSame('<p>Live text</p>', $page->content['html'], 'readers keep the published version');
        $this->assertSame(0, PageCollabState::where('page_id', $page->id)->value('epoch'), 'a live save never resets the live document');
    }

    public function test_an_older_snapshot_never_overwrites_a_newer_one(): void
    {
        $page = $this->publishedPage();
        $this->collabSave($page, '<p>Newest</p>', ['1' => 9, '2' => 4])->assertJsonPath('status', 'saved');

        // arrives late, generated before the one above
        $this->collabSave($page, '<p>Older</p>', ['1' => 7, '2' => 4])->assertOk()->assertJsonPath('status', 'covered');
        $this->assertSame('<p>Newest</p>', $page->fresh()->draft_content['html']);

        // concurrent (each has something the other lacks): not stored either — it would drop the stored
        // one's newer text; the leader receives both and saves the merged snapshot
        $this->collabSave($page, '<p>Other branch</p>', ['1' => 8, '2' => 5])->assertJsonPath('status', 'covered');
        $this->assertSame('<p>Newest</p>', $page->fresh()->draft_content['html']);
        $this->assertSame([1 => 9, 2 => 4], PageCollabState::where('page_id', $page->id)->first()->html_vector, 'the stored vector never goes back');

        // the merged one (everything of both) is stored
        $this->collabSave($page, '<p>Newest + more</p>', ['1' => 9, '2' => 5, '3' => 1])->assertJsonPath('status', 'saved');
        $this->assertSame('<p>Newest + more</p>', $page->fresh()->draft_content['html']);
    }

    public function test_a_save_without_changes_creates_no_draft_and_keeps_the_review_hash(): void
    {
        $page = $this->publishedPage();
        $hash = $page->draftHash();

        // the live document holds exactly the published text: opening it must not create "unpublished changes"
        $this->collabSave($page, '<p>Live text</p>', ['1' => 2], 'Runbook')->assertOk()
            ->assertJsonPath('status', 'unchanged')
            ->assertJsonPath('has_unpublished_changes', false)
            ->assertJsonPath('edit_revision', 0);
        $this->assertSame($hash, $page->fresh()->draftHash());
    }

    public function test_review_snapshots_stay_valid_until_the_live_text_really_changes(): void
    {
        $page = $this->publishedPage(['review_required' => true]);
        $this->collabSave($page, '<p>Reviewed text</p>', ['1' => 3])->assertJsonPath('status', 'saved');

        $review = $this->requestReview($page, [$this->viewer]);
        $this->assertSame($review->content_hash, $page->fresh()->draftHash());

        // another browser saves the same text (e.g. after a reconnect): still the reviewed draft
        $this->collabSave($page, '<p>Reviewed text</p>', ['1' => 3, '2' => 1])->assertJsonPath('status', 'unchanged');
        $this->assertSame($review->content_hash, $page->fresh()->draftHash());

        $this->decide($review, $this->viewer, 'approved')->assertSessionHasNoErrors();
        // publishing from a live editor: the browser flushed its save before, sends no content
        $this->actingAs($this->editor)->put($this->pageRoute('pages.publish-changes', $page), ['notify' => false])->assertSessionHasNoErrors();

        $page->refresh();
        $this->assertSame('<p>Reviewed text</p>', $page->content['html']);
        $this->assertSame(2, $page->current_version);
        $this->assertSame('published', $review->fresh()->status);
        $this->assertSame(0, PageCollabState::where('page_id', $page->id)->value('epoch'), 'publishing keeps the live document');

        // a real change after the approval makes the review stale and publishing is refused again
        $this->collabSave($page, '<p>Reviewed text, then changed</p>', ['1' => 5, '2' => 1])->assertJsonPath('status', 'saved');
        $this->actingAs($this->editor)->put($this->pageRoute('pages.publish-changes', $page), ['notify' => false])->assertSessionHasErrors('review');
    }

    public function test_publishing_an_unpublished_live_page_works(): void
    {
        $page = $this->draftPage();
        $this->collabSave($page, '<p>Written together</p>', ['7' => 12], 'Plan', $this->author)->assertJsonPath('status', 'saved');

        $page->refresh();
        $this->assertSame('Plan', $page->name, 'unpublished pages are their own draft');
        $this->actingAs($this->author)->post($this->pageRoute('pages.publish', $page))->assertSessionHasNoErrors();

        $page->refresh();
        $this->assertTrue($page->isPublished());
        $this->assertSame('<p>Written together</p>', $page->versions()->first()->content['html']);
        $this->assertSame(0, PageCollabState::where('page_id', $page->id)->value('epoch'));
    }

    public function test_live_saves_are_refused_for_a_replaced_document_readers_and_bad_payloads(): void
    {
        $page = $this->publishedPage();
        $this->collabSession($page, $this->editor);
        $this->actingAs($this->author)->putJson($this->pageRoute('pages.draft.save', $page), ['content' => json_encode($this->html('<p>Single-user edit</p>'))])->assertOk();

        // the single-user autosave replaced the draft → the live document of epoch 0 is gone
        $this->collabSave($page, '<p>Live</p>')->assertStatus(409)->assertJsonPath('reset', true)->assertJsonPath('epoch', 1);
        $this->assertSame('<p>Single-user edit</p>', $page->fresh()->draft_content['html']);

        $this->collabSave($page, '<p>x</p>', [], null, $this->viewer, 1)->assertForbidden();
        $this->actingAs($this->editor)->putJson($this->pageRoute('pages.collab.save', $page), ['epoch' => 1, 'content' => '{"type":"doc"}', 'name' => 'x', 'vector' => []])->assertStatus(422);
        $this->actingAs($this->editor)->putJson($this->pageRoute('pages.collab.save', $page), ['epoch' => 1, 'content' => json_encode($this->html('<p>x</p>')), 'name' => 'x', 'vector' => ['abc' => 1]])->assertStatus(422);
    }

    public function test_drafts_replaced_from_elsewhere_reset_the_live_document_but_publishing_does_not(): void
    {
        $page = $this->publishedPage();
        $this->collabSession($page, $this->editor);
        $epoch = fn () => PageCollabState::where('page_id', $page->id)->value('epoch');

        $this->collabSave($page, '<p>v2</p>', ['1' => 1])->assertJsonPath('status', 'saved');
        $this->actingAs($this->editor)->put($this->pageRoute('pages.publish-changes', $page), ['notify' => false])->assertSessionHasNoErrors();
        $this->assertSame(0, $epoch(), 'publish = the same draft goes live');

        $this->actingAs($this->author)->post($this->pageRoute('pages.unpublish', $page))->assertSessionHasNoErrors();
        $this->assertSame(0, $epoch(), 'unpublish keeps the text');

        // restoring version 1 replaces the editable text
        $this->actingAs($this->author)->post($this->pageRoute('pages.versions.restore', $page, [1]))->assertSessionHasNoErrors();
        $this->assertSame(1, $epoch());

        // an Excalidraw embed save changes only the embedded scenes (not part of the draft hash)
        Page::whereKey($page->id)->first()->update(['content' => $page->fresh()->content + ['excalidrawEmbeds' => ['d1' => ['elements' => []]]]]);
        $this->assertSame(1, $epoch());
    }

    // ── bootstrap of an empty live document ────────────────────────────────────────────────────

    public function test_exactly_one_browser_loads_the_draft_into_an_empty_document(): void
    {
        $page = $this->publishedPage(['icon' => '📘']);
        $this->collabSave($page, '<p>Draft text</p>', ['1' => 1])->assertJsonPath('status', 'saved');

        $this->bootstrap($page, 'claim-aaaaaaaaaaaaaaaa')->assertOk()
            ->assertJsonPath('status', 'granted')
            ->assertJsonPath('html', '<p>Draft text</p>')
            ->assertJsonPath('name', 'Runbook')
            ->assertJsonPath('icon', '📘');
        $this->bootstrap($page, 'claim-bbbbbbbbbbbbbbbb', $this->author)->assertOk()->assertJsonPath('status', 'wait');
        // the same browser may ask again (retry after a lost response)
        $this->bootstrap($page, 'claim-aaaaaaaaaaaaaaaa')->assertJsonPath('status', 'granted');

        // the first one vanished without loading anything: after the lease someone else may
        $this->travel(21)->seconds();
        $this->bootstrap($page, 'claim-bbbbbbbbbbbbbbbb', $this->author)->assertJsonPath('status', 'granted');

        // the loading browser reports the vector of what it loaded: no draft is written
        $before = $page->fresh()->edit_revision;
        $this->actingAs($this->editor)->putJson($this->pageRoute('pages.collab.save', $page), [
            'epoch' => 0, 'content' => json_encode($this->html('<p>Draft   text</p>')), 'name' => 'Runbook', 'vector' => ['9' => 4], 'baseline' => true,
        ])->assertOk()->assertJsonPath('status', 'baseline');
        $this->assertSame($before, $page->fresh()->edit_revision);
        $this->assertSame('<p>Draft text</p>', $page->fresh()->draft_content['html']);

        // once the server stored a state, nobody bootstraps any more
        $this->putJson(route('internal.collab.documents.store', CollabDocument::name($page->uuid, 0)), ['state' => base64_encode('s')], $this->internal())->assertNoContent();
        $this->travel(21)->seconds();
        $this->bootstrap($page, 'claim-cccccccccccccccc')->assertJsonPath('status', 'wait');

        $this->bootstrap($page, 'claim-cccccccccccccccc', $this->editor, 3)->assertStatus(409)->assertJsonPath('reset', true);
        $this->bootstrap($page, 'short', $this->editor)->assertStatus(422);
        $this->bootstrap($page, 'claim-dddddddddddddddd', $this->viewer)->assertForbidden();
    }

    public function test_the_presence_heartbeat_reports_the_live_epoch_to_editors_only(): void
    {
        $page = $this->publishedPage();
        $this->collabSession($page, $this->editor);

        $this->actingAs($this->editor)->postJson($this->pageRoute('pages.presence', $page))->assertOk()->assertJsonPath('collab_epoch', 0);
        $this->actingAs($this->viewer)->postJson($this->pageRoute('pages.presence', $page))->assertOk()->assertJsonMissingPath('collab_epoch');
    }

    public function test_state_vectors(): void
    {
        $this->assertSame([1 => 2, 4294967295 => 0], StateVector::normalize(['1' => 2, '4294967295' => 0]));
        $this->assertNull(StateVector::normalize(['1' => -1]));
        $this->assertNull(StateVector::normalize(['x' => 1]));
        $this->assertNull(StateVector::normalize(['1' => '2']));
        $this->assertNull(StateVector::normalize(['4294967296' => 1]));
        $this->assertTrue(StateVector::covers([1 => 5, 2 => 3], [1 => 5]));
        $this->assertTrue(StateVector::covers([1 => 5], []));
        $this->assertFalse(StateVector::covers([1 => 5], [1 => 6]));
        $this->assertFalse(StateVector::covers([1 => 5], [2 => 1]));
    }

    // ── helpers ────────────────────────────────────────────────────────────────────────────────

    private function collabSession(Page $page, $user): TestResponse
    {
        return $this->actingAs($user)->postJson($this->pageRoute('pages.collab.session', $page));
    }

    /**
     * @param  array<string, int>  $vector
     */
    private function collabSave(Page $page, string $html, array $vector = ['1' => 1], ?string $name = null, $user = null, int $epoch = 0): TestResponse
    {
        return $this->actingAs($user ?? $this->editor)->putJson($this->pageRoute('pages.collab.save', $page), [
            'epoch' => $epoch,
            'content' => json_encode($this->html($html)),
            'name' => $name ?? $page->name,
            'icon' => $page->icon,
            'vector' => $vector,
        ]);
    }

    private function bootstrap(Page $page, string $claim, $user = null, int $epoch = 0): TestResponse
    {
        return $this->actingAs($user ?? $this->editor)->postJson($this->pageRoute('pages.collab.bootstrap', $page), ['epoch' => $epoch, 'claim' => $claim]);
    }

    /**
     * @return array<string, string>
     */
    private function internal(): array
    {
        return ['Authorization' => 'Bearer '.CollabConfig::internalKey()];
    }
}
