<?php

namespace Tests\Feature\Pages;

use App\Mcp\Servers\BrainitiveServer;
use App\Mcp\Tools\SearchTool;
use App\Models\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\TestCase;

/**
 * Codex web review L: archiving keeps a page's visibility. A never-published draft (or an unpublished page whose
 * pending changes went back into its draft) stays private to its author and admins once archived; an archived page
 * that was published keeps its normal visibility.
 */
class ArchivedDraftPrivacyTest extends TestCase
{
    use ActsAsAppClient;
    use InteractsWithPages;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPages();
    }

    private function treeNames($user): array
    {
        return collect($this->actingAs($user)->getJson(route('pages.tree', [$this->workspace->slug, $this->project->slug]))->assertOk()->json())
            ->flatten()->filter(fn ($v) => is_string($v))->all();
    }

    private function archive(Page $page): void
    {
        $this->actingAs($this->author)->post($this->pageRoute('pages.archive', $page))->assertRedirect();
        $this->assertSame('archived', $page->fresh()->status);
    }

    public function test_an_archived_draft_stays_private(): void
    {
        $draft = $this->draftPage(['name' => 'Secret draft', 'content' => $this->html('<p>SECRET</p>')]);
        $this->actingAs($this->viewer)->get($this->pageRoute('pages.show', $draft))->assertForbidden();

        $this->archive($draft);

        $draft->refresh();
        $this->assertFalse($this->viewer->can('view', $draft));
        $this->actingAs($this->viewer)->get($this->pageRoute('pages.show', $draft))->assertForbidden();
        $this->assertFalse(Page::visibleTo($this->viewer)->whereKey($draft->id)->exists());
        $this->assertNotContains('Secret draft', $this->treeNames($this->viewer));
        $this->getJson('/api/v1/pages/'.$draft->uuid, $this->appHeaders($this->appToken($this->viewer)))->assertNotFound();
        BrainitiveServer::actingAs($this->viewer)->tool(SearchTool::class, ['query' => 'Secret draft'])->assertOk()->assertDontSee('Secret draft');
        BrainitiveServer::actingAs($this->author)->tool(SearchTool::class, ['query' => 'Secret draft'])->assertOk()->assertSee('Secret draft');

        // the author and admins still see it
        $this->assertTrue($this->author->can('view', $draft));
        $this->assertTrue($this->admin->can('view', $draft));
    }

    public function test_unpublished_pending_changes_stay_private_after_archiving(): void
    {
        $page = $this->publishedPage(['name' => 'Runbook']);
        $page->saveDraft($this->author, ['content' => $this->html('<p>PRIVATE EDIT</p>')]);
        $page->fresh()->unpublish($this->author);
        $page->refresh();
        $this->assertFalse($this->viewer->can('view', $page));

        $this->archive($page);
        $this->assertFalse($this->viewer->can('view', $page->fresh()));
        $this->actingAs($this->viewer)->get($this->pageRoute('pages.show', $page))->assertForbidden();
    }

    public function test_an_archived_published_page_keeps_its_visibility(): void
    {
        $page = $this->publishedPage(['name' => 'Public runbook']);
        $this->archive($page);

        $this->assertTrue($this->viewer->can('view', $page->fresh()));
        $this->actingAs($this->viewer)->get($this->pageRoute('pages.show', $page))->assertOk();
        $this->assertTrue(Page::visibleTo($this->viewer)->whereKey($page->id)->exists());
    }

    public function test_publishing_an_archived_draft_makes_it_public_again(): void
    {
        $draft = $this->draftPage(['name' => 'Later']);
        $this->archive($draft);

        $draft->fresh()->publish($this->author);
        $this->assertTrue($this->viewer->can('view', $draft->fresh()));
    }
}
