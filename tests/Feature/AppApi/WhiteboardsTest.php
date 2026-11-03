<?php

namespace Tests\Feature\AppApi;

use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/** App API v1 whiteboards, read-only (BRAI-414): the scene, PagePolicy visibility, ETag / 304. */
class WhiteboardsTest extends TestCase
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
    }

    private function whiteboard(array $attributes = []): Page
    {
        return Page::factory()->published()->create($attributes + [
            'project_id' => $this->project->id, 'created_by' => $this->editor->id, 'name' => 'Flow',
            'content' => [
                'type' => 'excalidraw',
                'elements' => [['id' => 'a', 'type' => 'rectangle', 'x' => 1], ['id' => 'b', 'type' => 'text', 'text' => 'gone', 'isDeleted' => true]],
                'appState' => ['viewBackgroundColor' => '#fff', 'collaborators' => ['x' => 1], 'openMenu' => 'shape'],
                'files' => ['f1' => ['mimeType' => 'image/png', 'dataURL' => 'data:image/png;base64,AAAA']],
                'previewImageUrl' => 'http://localhost/storage/excalidraw-previews/p.png',
            ],
        ]);
    }

    public function test_viewers_read_the_scene_without_deleted_elements_or_session_state(): void
    {
        $board = $this->whiteboard();

        $response = $this->getJson("/api/v1/whiteboards/{$board->uuid}", $this->headers($this->viewer))->assertOk()
            ->assertJsonPath('data.kind', 'whiteboard')
            ->assertJsonPath('data.name', 'Flow')
            ->assertJsonCount(1, 'data.scene.elements')
            ->assertJsonPath('data.scene.elements.0.id', 'a')
            ->assertJsonPath('data.scene.app_state', ['viewBackgroundColor' => '#fff'])
            ->assertJsonPath('data.scene.files.f1.mimeType', 'image/png')
            ->assertJsonPath('data.preview_image_url', 'http://localhost/storage/excalidraw-previews/p.png')
            ->assertJsonPath('data.can.update', false);

        // nothing changed → 304; a save → a new ETag
        $etag = $response->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $this->getJson("/api/v1/whiteboards/{$board->uuid}", $this->headers($this->viewer) + ['If-None-Match' => $etag])->assertStatus(304);
        $board->saveWhiteboard($this->editor, ['type' => 'excalidraw', 'elements' => [], 'appState' => []]);
        $this->getJson("/api/v1/whiteboards/{$board->uuid}", $this->headers($this->viewer) + ['If-None-Match' => $etag])->assertOk()
            ->assertJsonCount(0, 'data.scene.elements')->assertJsonPath('data.preview_image_url', null);
    }

    public function test_docs_drafts_outsiders_and_tokens(): void
    {
        $doc = Page::factory()->published()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id]);
        $this->getJson("/api/v1/whiteboards/{$doc->uuid}", $this->headers())->assertNotFound();

        $draft = $this->whiteboard(['status' => 'draft']);
        $this->getJson("/api/v1/whiteboards/{$draft->uuid}", $this->headers())->assertOk()->assertJsonPath('data.can.update', true);
        $this->getJson("/api/v1/whiteboards/{$draft->uuid}", $this->headers($this->viewer))->assertNotFound();

        $board = $this->whiteboard();
        $this->getJson("/api/v1/whiteboards/{$board->uuid}", $this->headers($this->outsider))->assertNotFound();
        $this->getJson("/api/v1/whiteboards/{$board->uuid}")->assertUnauthorized();
        $this->getJson("/api/v1/whiteboards/{$board->uuid}", $this->appHeaders($this->passportToken($this->editor)))->assertUnauthorized();
    }
}
