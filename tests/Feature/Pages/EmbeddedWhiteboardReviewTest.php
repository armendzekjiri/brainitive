<?php

namespace Tests\Feature\Pages;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Codex web review H: saving a whiteboard embedded in a doc goes through the page's draft like every other edit —
 * it never writes into the published content of a published page (the review gate, BRAI-80).
 */
class EmbeddedWhiteboardReviewTest extends TestCase
{
    use InteractsWithPages;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPages();
        Storage::fake('public');
    }

    private function saveEmbed($page, string $label)
    {
        return $this->actingAs($this->editor)->post($this->pageRoute('pages.excalidraw.embed.save', $page), [
            'diagram_data' => json_encode(['elements' => [['id' => 'e1', 'type' => 'text', 'text' => $label]], 'appState' => []]),
            'diagram_id' => 'd1',
            'block_id' => 'b1',
            'image' => UploadedFile::fake()->image('drawing.png'),
        ]);
    }

    public function test_a_published_review_required_page_keeps_its_published_content(): void
    {
        $page = $this->publishedPage(['review_required' => true]);
        $published = $page->content;

        $this->saveEmbed($page, 'unreviewed')->assertOk()->assertJsonPath('success', true);

        $page->refresh();
        $this->assertSame($published, $page->content);
        $this->assertTrue($page->hasUnpublishedChanges());
        $this->assertSame('unreviewed', $page->draft_content['excalidrawEmbeds']['d1']['elements'][0]['text']);
        $this->assertSame('<p>Live text</p>', $page->draft_content['html']);
    }

    public function test_a_published_page_collects_the_scene_in_its_draft_and_publishes_it_with_it(): void
    {
        $page = $this->publishedPage();
        $this->saveEmbed($page, 'v2')->assertOk();

        $page->refresh();
        $this->assertArrayNotHasKey('excalidrawEmbeds', $page->content);
        $this->assertSame('v2', $page->draft_content['excalidrawEmbeds']['d1']['elements'][0]['text']);

        $page->publishChanges($this->editor);
        $this->assertSame('v2', $page->fresh()->content['excalidrawEmbeds']['d1']['elements'][0]['text']);
    }

    public function test_a_never_published_page_is_its_own_draft(): void
    {
        $page = $this->draftPage(['created_by' => $this->editor->id]);
        $this->saveEmbed($page, 'draft')->assertOk();

        $this->assertSame('draft', $page->fresh()->content['excalidrawEmbeds']['d1']['elements'][0]['text']);
    }
}
