<?php

namespace Tests\Feature\Boards;

use App\Models\Item;
use App\Models\ItemCollabState;
use App\Notifications\MentionNotification;
use App\Support\Collab\CollabConfig;
use App\Support\Collab\CollabDocument;
use App\Support\Collab\CollabToken;
use App\Support\Collab\ItemCollab;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Live issue descriptions (BRAI-151): connection tokens (ItemPolicy update = edit, view = read-only),
 * the realtime server's internal API for `item.<id>.<epoch>`, saving through the normal item update
 * (history, mentions, smart links), the bootstrap and the reset when the description changes elsewhere.
 * Plan: docs/ai/tasks/feat-live-basics.md.
 */
class ItemCollabTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        Notification::fake();
        config(['collab.url' => 'wss://collab.example.test/collab', 'collab.secret' => 'test-collab-secret', 'collab.e2e_opt_in' => false]);
    }

    private function collabSession(Item $item, $user): TestResponse
    {
        return $this->actingAs($user)->postJson($this->itemRoute('items.collab.session', $item));
    }

    /**
     * @param  array<int|string, int>  $vector
     */
    private function save(Item $item, $user, string $html, array $vector, int $epoch = 0, bool $baseline = false): TestResponse
    {
        return $this->actingAs($user)->putJson($this->itemRoute('items.collab.save', $item), [
            'epoch' => $epoch,
            'content' => json_encode(['type' => 'lexical', 'html' => $html]),
            'vector' => $vector,
            'baseline' => $baseline,
        ]);
    }

    /** @return array<string, string> */
    private function internal(): array
    {
        return ['Authorization' => 'Bearer '.CollabConfig::internalKey()];
    }

    // ── session / token ────────────────────────────────────────────────────────────────────────

    public function test_editors_get_an_edit_token_viewers_a_read_only_one_outsiders_none(): void
    {
        $item = $this->item();

        $response = $this->collabSession($item, $this->editor)->assertOk()
            ->assertJsonPath('url', 'wss://collab.example.test/collab')
            ->assertJsonPath('document', "item.{$item->id}.0")
            ->assertJsonPath('edit', true)
            ->assertJsonPath('user.id', $this->editor->id);
        $payload = CollabToken::verify($response->json('token'));
        $this->assertSame("item.{$item->id}.0", $payload['doc']);
        $this->assertTrue($payload['edit']);
        $this->assertSame($this->editor->id, $payload['sub']);

        $viewer = $this->collabSession($item, $this->viewer)->assertOk()->assertJsonPath('edit', false);
        $this->assertFalse(CollabToken::verify($viewer->json('token'))['edit']);

        $this->collabSession($item, $this->outsider)->assertForbidden();

        // suspended: nothing (the server's access check drops them too)
        $this->editor->forceFill(['suspended_at' => now()])->save();
        $this->assertNull(ItemCollab::access($item, $this->editor->fresh()));
    }

    public function test_off_without_live_editing_and_e2e_needs_the_opt_in(): void
    {
        $item = $this->item();
        config(['collab.secret' => null]);
        $this->collabSession($item, $this->editor)->assertNotFound();

        config(['collab.secret' => 'test-collab-secret', 'collab.e2e_opt_in' => true]);
        $this->app['env'] = 'e2e';
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->collabSession($item, $this->editor)->assertNotFound();
        $this->actingAs($this->editor)->postJson($this->itemRoute('items.collab.session', $item), [], ['X-E2E-Collab' => '1'])->assertOk();
    }

    public function test_viewers_and_outsiders_cannot_save_or_bootstrap(): void
    {
        $item = $this->item();
        $this->save($item, $this->viewer, '<p>x</p>', [1 => 1])->assertForbidden();
        $this->save($item, $this->outsider, '<p>x</p>', [1 => 1])->assertForbidden();
        $this->actingAs($this->viewer)->postJson($this->itemRoute('items.collab.bootstrap', $item), ['epoch' => 0, 'claim' => str_repeat('a', 20)])->assertForbidden();
        $this->assertSame(null, $item->fresh()->description);
    }

    // ── internal API (realtime server) ─────────────────────────────────────────────────────────

    public function test_the_internal_api_loads_stores_and_checks_access_for_item_documents(): void
    {
        $item = $this->item();
        $document = CollabDocument::itemName($item->id, 0);

        $this->getJson(route('internal.collab.documents.show', $document))->assertUnauthorized();
        $this->getJson(route('internal.collab.documents.show', $document), $this->internal())->assertOk()->assertJsonPath('state', null);
        $this->putJson(route('internal.collab.documents.store', $document), ['state' => base64_encode('yjs-update')], $this->internal())->assertNoContent();
        $this->getJson(route('internal.collab.documents.show', $document), $this->internal())->assertOk()->assertJsonPath('state', base64_encode('yjs-update'));
        $this->assertSame(10, ItemCollabState::where('item_id', $item->id)->value('state_bytes'));

        $this->postJson(route('internal.collab.access'), ['document' => $document, 'user_ids' => [$this->editor->id, $this->viewer->id, $this->outsider->id]], $this->internal())
            ->assertOk()
            ->assertJsonPath('current', true)
            ->assertJsonPath("users.{$this->editor->id}", 'edit')
            ->assertJsonPath("users.{$this->viewer->id}", 'view')
            ->assertJsonPath("users.{$this->outsider->id}", null);

        // a stale epoch and a gone issue
        $this->getJson(route('internal.collab.documents.show', CollabDocument::itemName($item->id, 5)), $this->internal())->assertStatus(409);
        $this->putJson(route('internal.collab.documents.store', CollabDocument::itemName($item->id, 5)), ['state' => base64_encode('x')], $this->internal())->assertStatus(409);
        $item->delete();
        $this->getJson(route('internal.collab.documents.show', $document), $this->internal())->assertNotFound();
        $this->getJson(route('internal.collab.documents.show', 'item.0.1'), $this->internal())->assertNotFound();
    }

    // ── saving through the normal item update ─────────────────────────────────────────────────

    public function test_a_live_save_writes_the_description_like_a_normal_update(): void
    {
        $item = $this->item(['description' => '<p>Old</p>']);
        $mention = '<p>Ask <span data-mention-user-id="'.$this->viewer->id.'">@Viewer</span></p>';

        $this->save($item, $this->editor, $mention, [7 => 3])->assertOk()
            ->assertJsonPath('status', 'saved')
            ->assertJsonPath('description', $mention);

        $this->assertSame($mention, $item->fresh()->description);
        $entry = $item->activities()->first();
        $this->assertSame('updated', $entry->action);
        $this->assertSame(['description' => ['changed' => true]], $entry->changes);
        $this->assertSame($this->editor->id, $entry->user_id);
        Notification::assertSentTo($this->viewer, MentionNotification::class);
        // a live save doesn't reset its own live document
        $this->assertSame(0, ItemCollab::epoch($item));

        // the same snapshot again (or an older one): covered, nothing written
        $this->save($item, $this->editor, '<p>older</p>', [7 => 2])->assertOk()->assertJsonPath('status', 'covered');
        $this->assertSame($mention, $item->fresh()->description);

        // the mentioned person isn't notified again by the next save
        $this->save($item, $this->editor, $mention.'<p>more</p>', [7 => 5])->assertOk()->assertJsonPath('status', 'saved');
        Notification::assertSentToTimes($this->viewer, MentionNotification::class, 1);
    }

    public function test_saves_of_one_person_share_one_history_entry(): void
    {
        $item = $this->item();
        $this->save($item, $this->editor, '<p>One</p>', [1 => 1])->assertJsonPath('status', 'saved');
        $this->save($item, $this->editor, '<p>One two</p>', [1 => 2])->assertJsonPath('status', 'saved');
        $this->save($item, $this->editor, '<p>One two three</p>', [1 => 3])->assertJsonPath('status', 'saved');
        $this->assertSame(1, $item->activities()->where('action', 'updated')->count());

        // someone else's save is their own entry
        $this->save($item, $this->projectMember('editor'), '<p>One two three four</p>', [1 => 3, 2 => 1])->assertJsonPath('status', 'saved');
        $this->assertSame(2, $item->activities()->where('action', 'updated')->count());
    }

    public function test_unchanged_html_and_the_bootstrap_baseline_write_nothing(): void
    {
        $item = $this->item(['description' => '<p>Same</p>']);
        $this->save($item, $this->editor, '', [4 => 1], baseline: true)->assertOk()->assertJsonPath('status', 'baseline');
        $this->assertSame([4 => 1], ItemCollabState::where('item_id', $item->id)->first()->html_vector);
        $this->save($item, $this->editor, '<p>Same</p>', [4 => 2])->assertOk()->assertJsonPath('status', 'unchanged');
        $this->assertSame(0, $item->activities()->where('action', 'updated')->count());
    }

    public function test_bootstrap_hands_out_the_description_once_per_lease(): void
    {
        $item = $this->item(['description' => json_encode(['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Legacy']]]])]);
        $claim = str_repeat('a', 20);

        $this->actingAs($this->editor)->postJson($this->itemRoute('items.collab.bootstrap', $item), ['epoch' => 0, 'claim' => $claim])
            ->assertOk()->assertJsonPath('status', 'granted')->assertJsonPath('html', '<p>Legacy</p>')->assertJsonPath('name', $item->title);
        $this->actingAs($this->editor)->postJson($this->itemRoute('items.collab.bootstrap', $item), ['epoch' => 0, 'claim' => str_repeat('b', 20)])
            ->assertOk()->assertJsonPath('status', 'wait');
        $this->actingAs($this->editor)->postJson($this->itemRoute('items.collab.bootstrap', $item), ['epoch' => 3, 'claim' => $claim])
            ->assertStatus(409)->assertJsonPath('reset', true);
    }

    public function test_a_description_changed_elsewhere_starts_a_new_live_document(): void
    {
        $item = $this->item();
        $this->collabSession($item, $this->editor)->assertJsonPath('document', "item.{$item->id}.0");

        // the single-user editor (fallback), MCP, a checkbox toggle: the normal update path
        $this->actingAs($this->editor)->patch($this->itemRoute('boards.items.update', $item), ['description' => '<p>Elsewhere</p>'])->assertRedirect();
        $this->assertSame(1, ItemCollab::epoch($item));
        $this->collabSession($item, $this->editor)->assertJsonPath('document', "item.{$item->id}.1");
        $this->save($item, $this->editor, '<p>Late</p>', [1 => 1], epoch: 0)->assertStatus(409)->assertJsonPath('reset', true);
        $this->assertSame('<p>Elsewhere</p>', $item->fresh()->description);

        // other fields don't touch the live document
        $this->actingAs($this->editor)->patch($this->itemRoute('boards.items.update', $item), ['title' => 'Renamed'])->assertRedirect();
        $this->assertSame(1, ItemCollab::epoch($item));
    }

    public function test_description_and_title_changes_are_published_to_the_issue_channel(): void
    {
        config(['live.enabled' => true, 'live.url' => null, 'live.publish_url' => 'http://127.0.0.1:6299']);
        Cache::forget('live:publish:paused');
        $this->withoutDefer();
        Http::fake(['127.0.0.1:6299/*' => Http::response(['delivered' => 1], 202)]);
        $item = $this->item();

        $this->save($item, $this->editor, '<p>Live</p>', [1 => 1])->assertJsonPath('status', 'saved');
        $this->actingAs($this->editor)->patch($this->itemRoute('boards.items.update', $item), ['title' => 'Renamed', 'story_points' => 3])->assertRedirect();

        $messages = collect(Http::recorded())->flatMap(fn ($pair) => json_decode($pair[0]->body(), true)['messages'])
            ->where('channel', "item.{$item->id}")->where('event', 'item.updated')->pluck('payload')->values()->all();
        $this->assertSame([['id' => $item->id, 'fields' => ['description']], ['id' => $item->id, 'fields' => ['title']]], $messages);
        Http::assertSent(fn (Request $request) => ! str_contains($request->body(), 'Live</p>') && ! str_contains($request->body(), 'Renamed'));
    }

    public function test_item_details_carry_the_current_title_and_description(): void
    {
        $item = $this->item(['title' => 'T', 'description' => '<p>D</p>']);
        $this->actingAs($this->viewer)->getJson($this->itemRoute('boards.items.details', $item))
            ->assertOk()->assertJsonPath('title', 'T')->assertJsonPath('description', '<p>D</p>');
    }
}
