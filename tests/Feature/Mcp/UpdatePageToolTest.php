<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\BrainitiveServer;
use App\Mcp\Tools\GetPageTool;
use App\Mcp\Tools\UpdatePageTool;
use App\Models\Page;
use App\Models\PageCollabState;
use App\Notifications\PageUpdatedNotification;
use App\Support\Collab\PageCollab;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\Feature\Pages\InteractsWithPages;
use Tests\TestCase;

/**
 * MCP update_page writes like the web editor / App API (PageWrites): revision check, never over a draft or live
 * editors, published pages change through "publish changes".
 */
class UpdatePageToolTest extends TestCase
{
    use InteractsWithPages;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPages();
    }

    private function update(Page $page, array $args, $as = null)
    {
        return BrainitiveServer::actingAs($as ?? $this->editor)->tool(UpdatePageTool::class, [
            'workspace' => $this->workspace->slug,
            'project' => $this->project->slug,
            'page_uuid' => $page->uuid,
        ] + $args);
    }

    private function withDraft(Page $page, string $html = '<p>Author draft</p>'): Page
    {
        $page->saveDraft($this->author, ['content' => $this->html($html)]);

        return $page->fresh();
    }

    public function test_a_stale_expected_revision_changes_nothing(): void
    {
        $page = $this->publishedPage();
        $folder = Page::factory()->folder()->create(['project_id' => $this->project->id, 'created_by' => $this->author->id]);
        $revision = (int) $page->edit_revision;
        $page = $this->withDraft($page); // someone saved since the agent read it

        $this->update($page, ['markdown' => 'Agent text', 'title' => 'New', 'parent_uuid' => $folder->uuid, 'expected_revision' => $revision])
            ->assertHasErrors(['changed since you read it', "edit_revision {$page->edit_revision}", 'get_page']);

        $fresh = $page->fresh();
        $this->assertSame('<p>Live text</p>', $fresh->content['html']);
        $this->assertSame('<p>Author draft</p>', $fresh->draft_content['html']);
        $this->assertSame('Runbook', $fresh->name);
        $this->assertNull($fresh->parent_id);
        $this->assertSame($page->edit_revision, $fresh->edit_revision);
    }

    public function test_a_never_published_page_saves_like_the_web_editor(): void
    {
        $page = $this->draftPage();
        $revision = (int) $page->edit_revision;

        $this->update($page, ['markdown' => 'Second draft', 'expected_revision' => $revision], $this->author)
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('result', 'saved')->where('status', 'draft')->where('edit_revision', $revision + 1)->etc());

        $fresh = $page->fresh();
        $this->assertStringContainsString('Second draft', $fresh->content['html']);
        $this->assertTrue($fresh->isDraft());
        $this->assertSame(0, $fresh->versions()->count());
        $this->assertSame($this->author->id, $fresh->last_edited_by);
    }

    public function test_a_published_page_without_draft_or_editors_publishes_a_new_version(): void
    {
        Notification::fake();
        $page = $this->publishedPage();
        $page->addWatcher($this->viewer);
        $page->addWatcher($this->editor);
        $collab = PageCollabState::create(['page_id' => $page->id, 'epoch' => 3]);

        $this->update($page, ['markdown' => 'Rewritten by the agent', 'expected_revision' => (int) $page->edit_revision])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('result', 'published')->where('version', 2)->where('has_unpublished_changes', false)->etc());

        $fresh = $page->fresh();
        $this->assertStringContainsString('Rewritten by the agent', $fresh->content['html']);
        $this->assertNull($fresh->draft_content);
        $this->assertDatabaseHas('page_versions', ['page_id' => $page->id, 'version' => 2, 'message' => 'Edited via MCP', 'created_by' => $this->editor->id]);
        Notification::assertSentTo($this->viewer, PageUpdatedNotification::class);
        Notification::assertNotSentTo($this->editor, PageUpdatedNotification::class);
        // a write from outside the live document starts a new one: open browsers reload
        $this->assertSame(4, $collab->fresh()->epoch);
    }

    public function test_an_unpublished_draft_is_never_lost(): void
    {
        $page = $this->withDraft($this->publishedPage());

        // replace without having read the draft: refused
        $this->update($page, ['markdown' => 'Agent text'])
            ->assertHasErrors(['unpublished changes', 'Nothing was changed', 'expected_revision']);
        $this->assertSame('<p>Author draft</p>', $page->fresh()->draft_content['html']);
        $this->assertSame('<p>Live text</p>', $page->fresh()->content['html']);

        // append adds to the draft; the published text stays
        $this->update($page, ['markdown' => 'Agent addition', 'mode' => 'append'])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('result', 'saved_as_draft')->where('has_unpublished_changes', true)
                ->where('note', fn (string $note) => str_contains($note, 'Publish it in Brainitive'))->etc());
        $fresh = $page->fresh();
        $this->assertStringContainsString('Author draft', $fresh->draft_content['html']);
        $this->assertStringContainsString('Agent addition', $fresh->draft_content['html']);
        $this->assertSame('<p>Live text</p>', $fresh->content['html']);
        $this->assertSame(1, $fresh->current_version);

        // replace with the current revision (the agent read draft_markdown): goes into the draft, not live
        $this->update($fresh, ['markdown' => 'Agent rewrite of the draft', 'expected_revision' => (int) $fresh->edit_revision])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('result', 'saved_as_draft')->etc());
        $this->assertStringContainsString('Agent rewrite of the draft', $page->fresh()->draft_content['html']);
        $this->assertSame('<p>Live text</p>', $page->fresh()->content['html']);
    }

    public function test_someone_editing_right_now_gets_the_change_in_the_draft(): void
    {
        $page = $this->publishedPage();
        $this->actingAs($this->author)->postJson($this->pageRoute('pages.presence', $page), ['editing' => true])->assertOk();

        $this->update($page, ['markdown' => 'Agent text'])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('result', 'saved_as_draft')
                ->where('note', fn (string $note) => str_contains($note, 'editing this page right now'))->etc());

        $fresh = $page->fresh();
        $this->assertSame('<p>Live text</p>', $fresh->content['html']);
        $this->assertStringContainsString('Agent text', $fresh->draft_content['html']);
    }

    public function test_live_typing_counts_as_editing_and_old_presence_does_not(): void
    {
        $page = $this->publishedPage();
        $state = PageCollabState::create(['page_id' => $page->id, 'epoch' => 0, 'state_bytes' => 12]);
        $this->assertTrue(PageCollab::editingNow($page));

        $state->forceFill(['updated_at' => now()->subMinutes(5)])->save();
        $this->assertFalse(PageCollab::editingNow($page));

        // a reader viewing the page (not in edit mode) doesn't hold changes back
        $this->actingAs($this->viewer)->postJson($this->pageRoute('pages.presence', $page), ['editing' => false])->assertOk();
        $this->assertFalse(PageCollab::editingNow($page));
    }

    public function test_title_only_renames_and_keeps_the_draft(): void
    {
        $page = $this->withDraft($this->publishedPage());

        $this->update($page, ['title' => 'Runbook v2'])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('result', 'renamed')->etc());

        $fresh = $page->fresh();
        $this->assertSame('Runbook v2', $fresh->name);
        $this->assertSame('Runbook v2', $fresh->draft_name);
        $this->assertSame('<p>Author draft</p>', $fresh->draft_content['html']);
        $this->assertSame(2, $fresh->current_version);

        // publishing the draft later keeps the new title
        $fresh->publishChanges($this->author);
        $this->assertSame('Runbook v2', $fresh->fresh()->name);
    }

    public function test_no_access_gets_the_existing_errors(): void
    {
        $page = $this->publishedPage();

        $this->update($page, ['markdown' => 'Hijacked'], $this->viewer)->assertHasErrors();
        $this->update($page, ['markdown' => 'Hijacked'], $this->outsider)
            ->assertHasErrors(["You do not have access to a workspace named \"{$this->workspace->slug}\"."]);
        $this->update($this->draftPage(), ['markdown' => 'Hijacked'])
            ->assertHasErrors(['No page found with that uuid in this project.']);

        $this->assertSame('<p>Live text</p>', $page->fresh()->content['html']);
        $this->assertNull($page->fresh()->draft_content);
    }

    public function test_get_page_returns_the_revision_and_the_draft_for_editors(): void
    {
        $page = $this->withDraft($this->publishedPage());
        $args = ['workspace' => $this->workspace->slug, 'project' => $this->project->slug, 'page_uuid' => $page->uuid];

        BrainitiveServer::actingAs($this->editor)->tool(GetPageTool::class, $args)
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('edit_revision', $page->edit_revision)
                ->where('has_unpublished_changes', true)->where('status', 'published')->where('being_edited', false)
                ->where('markdown', 'Live text')->where('draft_markdown', 'Author draft')->etc());

        BrainitiveServer::actingAs($this->viewer)->tool(GetPageTool::class, $args)
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('draft_markdown', null)->where('markdown', 'Live text')->etc());
    }
}
