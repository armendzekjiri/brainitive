<?php

namespace Tests\Feature\AppApi;

use App\Models\Page;
use App\Models\PageCollabState;
use App\Models\PageReview;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUsage;
use App\Notifications\MentionNotification;
use App\Notifications\PageUpdatedNotification;
use App\Support\Entitlements\ConfigEntitlements;
use App\Support\Entitlements\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * App API v1 docs (BRAI-411): the page tree, read, create, edit, publish, move — PagePolicy visibility (private
 * drafts), Markdown in / out, the edit-revision check, the review gate and the live document reset.
 */
class PagesTest extends TestCase
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

    private function page(array $attributes = []): Page
    {
        return Page::factory()->create($attributes + ['project_id' => $this->project->id, 'created_by' => $this->editor->id]);
    }

    private function published(array $attributes = []): Page
    {
        return Page::factory()->published()->create($attributes + ['project_id' => $this->project->id, 'created_by' => $this->editor->id]);
    }

    // ── tree ────────────────────────────────────────────────────────────────────────────────

    public function test_the_tree_lists_what_the_user_may_see_without_content(): void
    {
        $folder = Page::factory()->folder()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id, 'name' => 'Specs', 'position' => 0]);
        $doc = $this->published(['name' => 'Intro', 'parent_id' => $folder->id, 'position' => 0]);
        $board = $this->published(['name' => 'Flow', 'position' => 1, 'content' => ['type' => 'excalidraw', 'elements' => [], 'appState' => []]]);
        $mine = $this->page(['name' => 'My draft', 'position' => 2]);
        $other = Page::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->viewer->id, 'name' => 'Viewer draft']);
        $image = $this->published(['name' => 'Logo page', 'icon' => 'data:image/png;base64,'.str_repeat('A', 500), 'position' => 3]);

        $response = $this->getJson("/api/v1/projects/{$this->project->id}/pages", $this->headers())->assertOk()
            ->assertJsonStructure(['data' => [['id', 'uuid', 'parent_id', 'parent_uuid', 'kind', 'name', 'icon', 'has_image_icon', 'status', 'position', 'url', 'updated_at']], 'next_cursor']);
        $byUuid = collect($response->json('data'))->keyBy('uuid');
        $this->assertEqualsCanonicalizing([$folder->uuid, $doc->uuid, $board->uuid, $mine->uuid, $image->uuid], $byUuid->keys()->all());
        $this->assertSame('folder', $byUuid[$folder->uuid]['kind']);
        $this->assertSame('whiteboard', $byUuid[$board->uuid]['kind']);
        $this->assertSame(['doc', $folder->uuid], [$byUuid[$doc->uuid]['kind'], $byUuid[$doc->uuid]['parent_uuid']]);
        $this->assertSame([null, true], [$byUuid[$image->uuid]['icon'], $byUuid[$image->uuid]['has_image_icon']]);
        $this->assertArrayNotHasKey('markdown', $byUuid[$doc->uuid]);

        // the viewer sees their own draft, not the editor's; a project admin sees every draft
        $viewerUuids = array_column($this->getJson("/api/v1/projects/{$this->project->id}/pages", $this->headers($this->viewer))->json('data'), 'uuid');
        $this->assertContains($other->uuid, $viewerUuids);
        $this->assertNotContains($mine->uuid, $viewerUuids);
        $admin = $this->projectMember('admin');
        $this->assertCount(6, $this->getJson("/api/v1/projects/{$this->project->id}/pages", $this->headers($admin))->json('data'));

        // one level, paginated
        $root = $this->getJson("/api/v1/projects/{$this->project->id}/pages?parent=root&limit=2", $this->headers())->assertOk();
        $this->assertCount(2, $root->json('data'));
        $next = $this->getJson("/api/v1/projects/{$this->project->id}/pages?parent=root&limit=2&cursor=".urlencode($root->json('next_cursor')), $this->headers())->assertOk();
        $this->assertCount(2, $next->json('data'));
        $this->assertNull($next->json('next_cursor'));
        $this->assertNotContains($doc->uuid, [...array_column($root->json('data'), 'uuid'), ...array_column($next->json('data'), 'uuid')]);
        $this->assertSame([$doc->uuid], array_column($this->getJson("/api/v1/projects/{$this->project->id}/pages?parent={$folder->uuid}", $this->headers())->json('data'), 'uuid'));
        $this->getJson("/api/v1/projects/{$this->project->id}/pages?parent={$other->uuid}", $this->headers())->assertNotFound();
        $this->getJson("/api/v1/projects/{$this->project->id}/pages?parent=nope", $this->headers())->assertUnprocessable();
    }

    public function test_tokens_and_other_projects(): void
    {
        $this->getJson("/api/v1/projects/{$this->project->id}/pages")->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
        $this->getJson("/api/v1/projects/{$this->project->id}/pages", $this->appHeaders($this->passportToken($this->editor)))->assertUnauthorized();

        $doc = $this->published();
        $this->getJson("/api/v1/projects/{$this->project->id}/pages", $this->headers($this->outsider))->assertNotFound();
        $this->getJson("/api/v1/pages/{$doc->uuid}", $this->headers($this->outsider))->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->getJson('/api/v1/pages/'.Str::uuid(), $this->headers())->assertNotFound();
        $this->patchJson("/api/v1/pages/{$doc->uuid}", ['name' => 'x'], $this->headers($this->outsider))->assertNotFound();
        $this->patchJson('/api/v1/pages/not-a-uuid', ['name' => 'x'], $this->headers())->assertNotFound();

        $otherWorkspace = Workspace::factory()->create();
        $foreign = Project::factory()->create(['workspace_id' => $otherWorkspace->id]);
        $this->postJson("/api/v1/projects/{$foreign->id}/pages", ['name' => 'x'], $this->headers())->assertNotFound();
    }

    // ── read ────────────────────────────────────────────────────────────────────────────────

    public function test_a_doc_reads_as_markdown_editors_see_the_draft_readers_the_published_version(): void
    {
        $doc = $this->published([
            'name' => 'Handbook',
            'content' => ['type' => 'lexical', 'html' => '<h2>Hello</h2><p>Ask <span class="mention" data-mention-user-id="'.$this->viewer->id.'">@Vi</span> <strong>now</strong></p><script>alert(1)</script>'],
        ]);
        $doc->saveDraft($this->editor, ['content' => ['type' => 'lexical', 'html' => '<p>Draft text</p>'], 'name' => 'Handbook v2']);

        $this->getJson("/api/v1/pages/{$doc->uuid}", $this->headers($this->viewer))->assertOk()
            ->assertJsonPath('data.kind', 'doc')
            ->assertJsonPath('data.name', 'Handbook')
            ->assertJsonPath('data.markdown', "## Hello\n\nAsk @[Vi](user:{$this->viewer->id}) **now**")
            ->assertJsonPath('data.has_unpublished_changes', false)
            ->assertJsonPath('data.can.update', false)
            ->assertJsonPath('data.version', 1)
            ->assertJsonMissingPath('data.content');
        $this->assertStringNotContainsString('<script', $this->getJson("/api/v1/pages/{$doc->uuid}", $this->headers($this->viewer))->json('data.html'));

        $this->getJson("/api/v1/pages/{$doc->uuid}", $this->headers())->assertOk()
            ->assertJsonPath('data.name', 'Handbook v2')
            ->assertJsonPath('data.markdown', 'Draft text')
            ->assertJsonPath('data.has_unpublished_changes', true)
            ->assertJsonPath('data.revision', $doc->fresh()->edit_revision)
            ->assertJsonPath('data.can.update', true)
            ->assertJsonPath('data.can.publish', true)
            ->assertJsonPath('data.creator.id', $this->editor->id);

        // someone else's unpublished draft: 404, like the web
        $private = Page::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->viewer->id]);
        $this->getJson("/api/v1/pages/{$private->uuid}", $this->headers())->assertNotFound();
        $this->getJson("/api/v1/pages/{$private->uuid}", $this->headers($this->viewer))->assertOk()->assertJsonPath('data.status', 'draft');
    }

    public function test_breadcrumbs_and_image_icons(): void
    {
        $folder = Page::factory()->folder()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id, 'name' => 'Specs']);
        $doc = $this->published(['parent_id' => $folder->id, 'icon' => '/storage/icons/a.png']);

        $this->getJson("/api/v1/pages/{$doc->uuid}", $this->headers())->assertOk()
            ->assertJsonPath('data.breadcrumbs', [['uuid' => $folder->uuid, 'name' => 'Specs', 'type' => 'folder']])
            ->assertJsonPath('data.icon', null)
            ->assertJsonPath('data.icon_image', url('/storage/icons/a.png'));
        $this->getJson("/api/v1/pages/{$folder->uuid}", $this->headers())->assertOk()
            ->assertJsonPath('data.kind', 'folder')->assertJsonPath('data.markdown', null)->assertJsonPath('data.can.publish', false);
    }

    // ── create ──────────────────────────────────────────────────────────────────────────────

    public function test_editors_create_docs_folders_and_whiteboards(): void
    {
        Notification::fake();
        $folder = $this->postJson("/api/v1/projects/{$this->project->id}/pages", ['name' => 'Specs', 'kind' => 'folder'], $this->headers())
            ->assertCreated()->assertJsonPath('data.kind', 'folder')->assertJsonPath('data.icon', '📁');

        $requestId = (string) Str::uuid();
        $body = ['name' => 'API notes', 'parent_uuid' => $folder->json('data.uuid'), 'markdown' => "# Title\n\n- one\n- two\n\nHi @[Vi](user:{$this->viewer->id})", 'publish' => true, 'request_id' => $requestId];
        $doc = $this->postJson("/api/v1/projects/{$this->project->id}/pages", $body, $this->headers())->assertCreated()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.parent_uuid', $folder->json('data.uuid'))
            ->assertJsonPath('data.version', 1);
        $this->assertStringContainsString("- one\n- two", $doc->json('data.markdown'));
        $this->assertStringContainsString("@[{$this->viewer->name}](user:{$this->viewer->id})", $doc->json('data.markdown'));
        $stored = Page::where('uuid', $doc->json('data.uuid'))->firstOrFail();
        $this->assertSame('lexical', $stored->content['type']);
        $this->assertStringContainsString('data-mention-user-id="'.$this->viewer->id.'"', $stored->content['html']);
        $this->assertTrue($stored->watchers()->whereKey($this->editor->id)->exists());

        // a retry answers 200 with the same page; the same id with other input → 409
        $this->postJson("/api/v1/projects/{$this->project->id}/pages", $body, $this->headers())->assertOk()->assertJsonPath('data.uuid', $stored->uuid);
        $this->postJson("/api/v1/projects/{$this->project->id}/pages", ['name' => 'Other'] + $body, $this->headers())->assertStatus(409)->assertJsonPath('code', 'conflict');
        $this->assertSame(1, Page::where('name', 'API notes')->count());

        $this->postJson("/api/v1/projects/{$this->project->id}/pages", ['name' => 'Flow', 'kind' => 'whiteboard'], $this->headers())
            ->assertCreated()->assertJsonPath('data.kind', 'whiteboard')->assertJsonPath('data.status', 'draft')->assertJsonPath('data.markdown', null);
    }

    public function test_create_rules_roles_and_the_storage_limit(): void
    {
        $this->postJson("/api/v1/projects/{$this->project->id}/pages", ['name' => 'x'], $this->headers($this->viewer))->assertForbidden()->assertJsonPath('code', 'forbidden');

        $private = Page::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->viewer->id]);
        $this->postJson("/api/v1/projects/{$this->project->id}/pages", ['name' => '', 'kind' => 'chart', 'icon' => 'data:image/png;base64,AAA', 'parent_uuid' => $private->uuid], $this->headers())
            ->assertUnprocessable()->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors(['name', 'kind', 'icon']);
        $this->postJson("/api/v1/projects/{$this->project->id}/pages", ['name' => 'x', 'parent_uuid' => $private->uuid], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('parent_uuid');
        $this->postJson("/api/v1/projects/{$this->project->id}/pages", ['name' => 'x', 'kind' => 'folder', 'markdown' => 'text', 'publish' => true], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors(['markdown', 'publish']);

        $this->app->instance(Entitlements::class, new ConfigEntitlements(['enforced' => true, 'content_bytes' => 1000]));
        WorkspaceUsage::create(['workspace_id' => $this->workspace->id, 'content_bytes' => 1000]);
        $this->postJson("/api/v1/projects/{$this->project->id}/pages", ['name' => 'Too much'], $this->headers())
            ->assertStatus(402)->assertJsonPath('code', 'limit_reached')->assertJsonPath('limit.kind', 'content');
    }

    // ── edit ────────────────────────────────────────────────────────────────────────────────

    public function test_markdown_needs_the_revision_and_lands_in_the_draft_of_a_published_doc(): void
    {
        $doc = $this->published(['name' => 'Guide', 'content' => ['type' => 'lexical', 'html' => '<p>Live text</p>']]);
        PageCollabState::create(['page_id' => $doc->id, 'epoch' => 3]);
        $revision = $doc->edit_revision;

        $this->patchJson("/api/v1/pages/{$doc->uuid}", ['markdown' => 'New text'], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->patchJson("/api/v1/pages/{$doc->uuid}", ['markdown' => 'New **text**', 'revision' => $revision], $this->headers())->assertOk()
            ->assertJsonPath('data.markdown', 'New **text**')
            ->assertJsonPath('data.has_unpublished_changes', true)
            ->assertJsonPath('data.revision', $revision + 1);

        // readers still see the published text; the live document starts over (open browsers reload)
        $this->getJson("/api/v1/pages/{$doc->uuid}", $this->headers($this->viewer))->assertJsonPath('data.markdown', 'Live text');
        $this->assertSame(4, PageCollabState::where('page_id', $doc->id)->value('epoch'));

        // a stale revision (someone saved since — e.g. a live editor) → 409, nothing changes
        $this->patchJson("/api/v1/pages/{$doc->uuid}", ['markdown' => 'Overwrite', 'revision' => $revision], $this->headers())
            ->assertStatus(409)->assertJsonPath('code', 'conflict');
        $this->assertSame('<p>New <strong>text</strong></p>', $doc->fresh()->draft_content['html']);

        // a rename goes to the draft too, the tree keeps the published name
        $this->patchJson("/api/v1/pages/{$doc->uuid}", ['name' => 'Guide 2'], $this->headers())->assertOk()->assertJsonPath('data.name', 'Guide 2');
        $this->assertSame('Guide', $doc->fresh()->name);
    }

    public function test_drafts_change_directly_whiteboards_and_folders_take_name_and_icon_only(): void
    {
        $draft = $this->page(['content' => ['type' => 'lexical', 'html' => '<p>a</p>']]);
        $this->patchJson("/api/v1/pages/{$draft->uuid}", ['markdown' => 'b', 'name' => 'Renamed', 'icon' => '🚀', 'revision' => $draft->edit_revision], $this->headers())->assertOk();
        $this->assertSame(['Renamed', '🚀', '<p>b</p>'], [$draft->fresh()->name, $draft->fresh()->icon, $draft->fresh()->content['html']]);

        $board = $this->published(['content' => ['type' => 'excalidraw', 'elements' => [['id' => 'x']], 'appState' => []]]);
        $this->patchJson("/api/v1/pages/{$board->uuid}", ['markdown' => 'text', 'revision' => 0], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('markdown');
        $this->patchJson("/api/v1/pages/{$board->uuid}", ['name' => 'Flow'], $this->headers())->assertOk()->assertJsonPath('data.name', 'Flow');
        $this->assertSame([['id' => 'x']], $board->fresh()->content['elements']);

        $this->patchJson("/api/v1/pages/{$draft->uuid}", ['name' => 'x'], $this->headers($this->viewer))->assertNotFound(); // not theirs
        $published = $this->published();
        $this->patchJson("/api/v1/pages/{$published->uuid}", ['name' => 'x'], $this->headers($this->viewer))->assertForbidden();
    }

    // ── publish ─────────────────────────────────────────────────────────────────────────────

    public function test_publish_a_draft_then_changes_watchers_hear(): void
    {
        Notification::fake();
        $draft = $this->page(['name' => 'Plan']);
        $this->getJson("/api/v1/pages/{$draft->uuid}", $this->headers($this->viewer))->assertNotFound();

        $this->postJson("/api/v1/pages/{$draft->uuid}/publish", ['message' => 'First'], $this->headers())->assertOk()
            ->assertJsonPath('data.status', 'published')->assertJsonPath('data.version', 1);
        $this->getJson("/api/v1/pages/{$draft->uuid}", $this->headers($this->viewer))->assertOk();

        $draft = $draft->fresh();
        $draft->addWatcher($this->viewer);
        $this->patchJson("/api/v1/pages/{$draft->uuid}", ['markdown' => 'v2', 'revision' => $draft->edit_revision], $this->headers())->assertOk();
        $this->postJson("/api/v1/pages/{$draft->uuid}/publish", ['revision' => 0], $this->headers())->assertStatus(409);
        $this->postJson("/api/v1/pages/{$draft->uuid}/publish", [], $this->headers())->assertOk()
            ->assertJsonPath('data.version', 2)->assertJsonPath('data.has_unpublished_changes', false);
        Notification::assertSentTo($this->viewer, PageUpdatedNotification::class);
        Notification::assertNotSentTo($this->editor, PageUpdatedNotification::class);
        $this->getJson("/api/v1/pages/{$draft->uuid}", $this->headers($this->viewer))->assertJsonPath('data.markdown', 'v2');

        // nothing left to publish: 200, same version
        $this->postJson("/api/v1/pages/{$draft->uuid}/publish", [], $this->headers())->assertOk()->assertJsonPath('data.version', 2);
        $this->postJson("/api/v1/pages/{$draft->uuid}/publish", [], $this->headers($this->viewer))->assertForbidden();
    }

    public function test_a_review_required_doc_publishes_only_an_approved_draft(): void
    {
        $doc = $this->published(['review_required' => true]);
        $this->patchJson("/api/v1/pages/{$doc->uuid}", ['markdown' => 'Needs review', 'revision' => $doc->edit_revision], $this->headers())->assertOk();

        $this->postJson("/api/v1/pages/{$doc->uuid}/publish", [], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('review');
        $this->assertSame(1, $doc->fresh()->current_version);
        $this->assertSame(0, PageReview::count());

        $folder = Page::factory()->folder()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id]);
        $this->postJson("/api/v1/pages/{$folder->uuid}/publish", [], $this->headers())->assertUnprocessable();
    }

    public function test_a_mention_in_new_text_notifies_once(): void
    {
        Notification::fake();
        $doc = $this->page();
        $this->patchJson("/api/v1/pages/{$doc->uuid}", ['markdown' => "Hi @[Vi](user:{$this->viewer->id}) and @[Out](user:{$this->outsider->id})", 'revision' => $doc->edit_revision], $this->headers())->assertOk();
        Notification::assertSentToTimes($this->viewer, MentionNotification::class, 1);
        Notification::assertNotSentTo($this->outsider, MentionNotification::class);
        $this->assertStringNotContainsString('data-mention-user-id="'.$this->outsider->id.'"', $doc->fresh()->content['html']);
    }

    // ── move ────────────────────────────────────────────────────────────────────────────────

    public function test_move_into_a_folder_next_to_a_sibling_and_back_to_the_top(): void
    {
        $folder = Page::factory()->folder()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id]);
        $a = $this->published(['parent_id' => $folder->id, 'position' => 0]);
        $b = $this->published(['parent_id' => $folder->id, 'position' => 1]);
        $doc = $this->published();

        $this->postJson("/api/v1/pages/{$doc->uuid}/move", ['parent_uuid' => $folder->uuid, 'before_uuid' => $b->uuid], $this->headers())->assertOk()
            ->assertJsonPath('data.parent_uuid', $folder->uuid);
        $this->assertSame([$a->id, $doc->id, $b->id], Page::where('parent_id', $folder->id)->orderBy('position')->pluck('id')->all());

        $this->postJson("/api/v1/pages/{$folder->uuid}/move", ['parent_uuid' => $doc->uuid], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('parent_uuid');
        $this->postJson("/api/v1/pages/{$doc->uuid}/move", ['parent_uuid' => (string) Str::uuid()], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('parent_uuid');
        $this->postJson("/api/v1/pages/{$doc->uuid}/move", [], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('parent_uuid');
        $this->postJson("/api/v1/pages/{$doc->uuid}/move", ['parent_uuid' => null], $this->headers($this->viewer))->assertForbidden();

        $this->postJson("/api/v1/pages/{$doc->uuid}/move", ['parent_uuid' => null], $this->headers())->assertOk()->assertJsonPath('data.parent_uuid', null);
    }
}
