<?php

namespace Tests\Feature\AppApi;

use App\Models\Page;
use App\Models\PageCollabState;
use App\Models\User;
use App\Support\Collab\CollabToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * App API v1 live co-editing for native clients (BRAI-412): the token (PageCollab::access, the rule the realtime
 * server checks), the bootstrap claim and the live draft save — the browser's three calls.
 */
class PageCollabTest extends TestCase
{
    use ActsAsAppClient;
    use ForgetsGuardsBetweenRequests;
    use InteractsWithBoards;
    use RefreshDatabase;

    private function headers(?User $user = null): array
    {
        return $this->appHeaders($this->appToken($user ?? $this->editor));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        Notification::fake();
        config(['collab.url' => 'wss://collab.example.test/collab', 'collab.secret' => 'test-collab-secret', 'collab.e2e_opt_in' => false]);
    }

    private function published(array $attributes = []): Page
    {
        return Page::factory()->published()->create($attributes + [
            'project_id' => $this->project->id, 'created_by' => $this->editor->id, 'content' => ['type' => 'lexical', 'html' => '<p>Live text</p>'],
        ]);
    }

    public function test_editors_get_an_edit_token_readers_of_a_draft_a_view_token(): void
    {
        $page = $this->published();
        $this->getJson("/api/v1/pages/{$page->uuid}", $this->headers())->assertJsonPath('data.live', 'edit');

        $response = $this->postJson("/api/v1/pages/{$page->uuid}/collab-token", [], $this->headers())->assertOk()
            ->assertJsonPath('data.url', 'wss://collab.example.test/collab')
            ->assertJsonPath('data.document', "page.{$page->uuid}.0")
            ->assertJsonPath('data.edit', true)
            ->assertJsonPath('data.html_vector', null)
            ->assertJsonPath('data.user.id', $this->editor->id);
        $payload = CollabToken::verify($response->json('data.token'));
        $this->assertSame([$this->editor->id, "page.{$page->uuid}.0", true], [$payload['sub'], $payload['doc'], $payload['edit']]);
        $this->assertSame($payload['exp'], $response->json('data.expires_at'));

        // a project admin reading someone's never-published doc watches; readers of a published doc get none
        $draft = Page::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id]);
        $viewerOwnDraft = Page::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->viewer->id]);
        $this->postJson("/api/v1/pages/{$viewerOwnDraft->uuid}/collab-token", [], $this->headers($this->viewer))->assertOk()->assertJsonPath('data.edit', false);
        $this->getJson("/api/v1/pages/{$viewerOwnDraft->uuid}", $this->headers($this->viewer))->assertJsonPath('data.live', 'view');
        $this->postJson("/api/v1/pages/{$page->uuid}/collab-token", [], $this->headers($this->viewer))->assertForbidden()->assertJsonPath('code', 'forbidden');
        $this->getJson("/api/v1/pages/{$page->uuid}", $this->headers($this->viewer))->assertJsonPath('data.live', null);
        $this->postJson("/api/v1/pages/{$draft->uuid}/collab-token", [], $this->headers($this->viewer))->assertNotFound();
        $this->postJson("/api/v1/pages/{$page->uuid}/collab-token", [], $this->headers($this->outsider))->assertNotFound();
        $this->postJson("/api/v1/pages/{$page->uuid}/collab-token", [], $this->appHeaders($this->passportToken($this->editor)))->assertUnauthorized();
        $this->postJson('/api/v1/pages/nope/collab-token', [], $this->headers())->assertNotFound();
    }

    public function test_no_token_for_whiteboards_folders_or_without_a_realtime_server(): void
    {
        $board = $this->published(['content' => ['type' => 'excalidraw', 'elements' => [], 'appState' => []]]);
        $folder = Page::factory()->folder()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id]);
        $this->postJson("/api/v1/pages/{$board->uuid}/collab-token", [], $this->headers())->assertForbidden();
        $this->postJson("/api/v1/pages/{$folder->uuid}/collab-token", [], $this->headers())->assertForbidden();

        config(['collab.secret' => null]);
        $page = $this->published();
        $this->postJson("/api/v1/pages/{$page->uuid}/collab-token", [], $this->headers())->assertForbidden();
        $this->getJson("/api/v1/pages/{$page->uuid}", $this->headers())->assertJsonPath('data.live', null);
    }

    public function test_bootstrap_then_baseline_then_saves_write_the_draft(): void
    {
        $page = $this->published();
        $this->postJson("/api/v1/pages/{$page->uuid}/collab-token", [], $this->headers())->assertOk();

        $this->postJson("/api/v1/pages/{$page->uuid}/collab/bootstrap", ['epoch' => 0, 'claim' => str_repeat('a', 20)], $this->headers())->assertOk()
            ->assertJsonPath('data.status', 'granted')->assertJsonPath('data.html', '<p>Live text</p>');
        $this->postJson("/api/v1/pages/{$page->uuid}/collab/bootstrap", ['epoch' => 0, 'claim' => str_repeat('b', 20)], $this->headers())->assertOk()
            ->assertJsonPath('data.status', 'wait')->assertJsonPath('data.html', null);

        $base = ['epoch' => 0, 'name' => $page->name, 'icon' => '📄'];
        $this->putJson("/api/v1/pages/{$page->uuid}/collab/draft", $base + ['html' => '<p>Live text</p>', 'vector' => ['1' => 1], 'baseline' => true], $this->headers())
            ->assertOk()->assertJsonPath('data.status', 'baseline');
        $this->putJson("/api/v1/pages/{$page->uuid}/collab/draft", $base + ['html' => '<p>Together</p>', 'vector' => ['1' => 5, '2' => 3]], $this->headers())
            ->assertOk()->assertJsonPath('data.status', 'saved')->assertJsonPath('data.has_unpublished_changes', true);
        // an older snapshot never overwrites the newer one
        $this->putJson("/api/v1/pages/{$page->uuid}/collab/draft", $base + ['html' => '<p>Old</p>', 'vector' => ['1' => 4, '2' => 3]], $this->headers())
            ->assertOk()->assertJsonPath('data.status', 'covered');

        $page->refresh();
        $this->assertSame('<p>Together</p>', $page->draft_content['html']);
        $this->assertSame('<p>Live text</p>', $page->content['html'], 'readers keep the published version');
        $this->assertSame(0, PageCollabState::where('page_id', $page->id)->value('epoch'), 'a live save never resets the live document');
    }

    public function test_a_replaced_document_readers_and_bad_input_are_refused(): void
    {
        $page = $this->published();
        $this->postJson("/api/v1/pages/{$page->uuid}/collab-token", [], $this->headers())->assertOk();

        // a text change from outside (the app's PATCH, a discard, a restore) starts a new live document
        $this->patchJson("/api/v1/pages/{$page->uuid}", ['markdown' => 'From outside', 'revision' => $page->edit_revision], $this->headers())->assertOk();
        $this->putJson("/api/v1/pages/{$page->uuid}/collab/draft", ['epoch' => 0, 'html' => '<p>x</p>', 'name' => 'n', 'vector' => ['1' => 1]], $this->headers())
            ->assertStatus(409)->assertJsonPath('code', 'conflict')->assertJsonPath('epoch', 1);
        $this->postJson("/api/v1/pages/{$page->uuid}/collab/bootstrap", ['epoch' => 0, 'claim' => str_repeat('a', 20)], $this->headers())
            ->assertStatus(409)->assertJsonPath('epoch', 1);
        $this->postJson("/api/v1/pages/{$page->uuid}/collab-token", [], $this->headers())->assertJsonPath('data.document', "page.{$page->uuid}.1");

        $this->putJson("/api/v1/pages/{$page->uuid}/collab/draft", ['epoch' => 1, 'html' => '<p>x</p>', 'name' => 'n', 'vector' => ['a' => 'b']], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('vector');
        $this->postJson("/api/v1/pages/{$page->uuid}/collab/bootstrap", ['epoch' => 1, 'claim' => 'short'], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('claim');
        $this->putJson("/api/v1/pages/{$page->uuid}/collab/draft", ['epoch' => 1, 'html' => '<p>x</p>', 'name' => 'n', 'vector' => ['1' => 1]], $this->headers($this->viewer))
            ->assertForbidden();
        $this->putJson("/api/v1/pages/{$page->uuid}/collab/draft", ['epoch' => 1, 'html' => '<p>x</p>', 'name' => 'n', 'vector' => ['1' => 1]], $this->headers($this->outsider))
            ->assertNotFound();
    }
}
