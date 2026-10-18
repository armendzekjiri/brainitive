<?php

namespace Tests\Feature\Pages;

use App\Mcp\Servers\BrainitiveServer;
use App\Mcp\Tools\CreatePageTool;
use App\Mcp\Tools\GetPageTool;
use App\Mcp\Tools\ListPagesTool;
use App\Mcp\Tools\UpdatePageTool;
use App\Models\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageMcpToolsTest extends TestCase
{
    use InteractsWithPages;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPages();
    }

    /**
     * @return array{workspace: string, project: string}
     */
    private function scope(): array
    {
        return ['workspace' => $this->workspace->slug, 'project' => $this->project->slug];
    }

    public function test_list_and_get_hide_other_peoples_unpublished_pages(): void
    {
        $this->publishedPage(['name' => 'Public doc']);
        $draft = $this->draftPage(['name' => 'Secret draft']);

        BrainitiveServer::actingAs($this->editor)
            ->tool(ListPagesTool::class, $this->scope())
            ->assertOk()
            ->assertSee('Public doc')
            ->assertDontSee('Secret draft');

        BrainitiveServer::actingAs($this->editor)
            ->tool(GetPageTool::class, $this->scope() + ['page_uuid' => $draft->uuid])
            ->assertHasErrors(['No page found with that uuid in this project.']);

        BrainitiveServer::actingAs($this->author)
            ->tool(GetPageTool::class, $this->scope() + ['page_uuid' => $draft->uuid])
            ->assertOk()
            ->assertSee('Secret draft');
    }

    public function test_update_on_a_published_page_records_a_version(): void
    {
        $page = $this->publishedPage();

        BrainitiveServer::actingAs($this->editor)
            ->tool(UpdatePageTool::class, $this->scope() + ['page_uuid' => $page->uuid, 'markdown' => 'Rewritten by the agent'])
            ->assertOk();

        $page->refresh();
        $this->assertStringContainsString('Rewritten by the agent', $page->content['html']);
        $this->assertSame(2, $page->current_version);
        $this->assertDatabaseHas('page_versions', ['page_id' => $page->id, 'version' => 2, 'message' => 'Edited via MCP', 'created_by' => $this->editor->id]);
    }

    public function test_viewers_cannot_update_or_create_through_mcp(): void
    {
        $page = $this->publishedPage();

        BrainitiveServer::actingAs($this->viewer)
            ->tool(UpdatePageTool::class, $this->scope() + ['page_uuid' => $page->uuid, 'title' => 'Hijacked'])
            ->assertHasErrors();

        BrainitiveServer::actingAs($this->viewer)
            ->tool(CreatePageTool::class, $this->scope() + ['title' => 'Nope'])
            ->assertHasErrors();

        $this->assertSame('Runbook', $page->fresh()->name);
        $this->assertDatabaseMissing('pages', ['name' => 'Nope']);
    }

    public function test_create_makes_an_author_only_draft_unless_asked_to_publish(): void
    {
        BrainitiveServer::actingAs($this->editor)
            ->tool(CreatePageTool::class, $this->scope() + ['title' => 'Agent notes'])
            ->assertOk()
            ->assertSee('draft');

        BrainitiveServer::actingAs($this->editor)
            ->tool(CreatePageTool::class, $this->scope() + ['title' => 'Team guide', 'markdown' => '# Hello', 'publish' => true])
            ->assertOk()
            ->assertSee('published');

        $this->assertTrue(Page::where('name', 'Agent notes')->firstOrFail()->isDraft());
        $guide = Page::where('name', 'Team guide')->firstOrFail();
        $this->assertTrue($guide->isPublished());
        $this->assertSame(1, $guide->current_version);
    }
}
