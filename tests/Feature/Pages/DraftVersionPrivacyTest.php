<?php

namespace Tests\Feature\Pages;

use App\Models\Page;
use App\Models\PageVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\TestCase;

/**
 * Codex web review M: checkpoints recorded while a page is a private draft (whiteboard saves, restores) stay private
 * to its author and admins after it is published — in the version list and the full content, on the web and in the
 * App API. Published history stays visible to everyone who can view the page.
 */
class DraftVersionPrivacyTest extends TestCase
{
    use ActsAsAppClient;
    use InteractsWithPages;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPages();
        Notification::fake();
    }

    private function scene(string $text): array
    {
        return ['type' => 'excalidraw', 'elements' => [['id' => 'e1', 'type' => 'text', 'text' => $text]], 'appState' => [], 'files' => [], 'libraryItems' => []];
    }

    private function saveScene(Page $page, string $text): void
    {
        $this->actingAs($this->author)->postJson($this->pageRoute('pages.excalidraw.save', $page), [
            'diagram_data' => json_encode($this->scene($text)), 'base_revision' => $page->fresh()->edit_revision, 'checkpoint' => true,
        ])->assertOk();
    }

    private function versionNumbers($user, Page $page): array
    {
        return collect($this->actingAs($user)->getJson($this->pageRoute('pages.versions.index', $page))->assertOk()->json('versions'))->pluck('version')->all();
    }

    public function test_draft_checkpoints_stay_private_after_publishing(): void
    {
        $board = $this->draftPage(['name' => 'Retro', 'content' => $this->scene('start')]);
        $this->saveScene($board, 'PRIVATE DRAFT');
        $this->saveScene($board, 'clean');
        $this->actingAs($this->author)->post($this->pageRoute('pages.publish', $board))->assertSessionHasNoErrors();

        $board->refresh();
        $secret = $board->versions()->get()->first(fn (PageVersion $v) => str_contains(json_encode($v->content), 'PRIVATE DRAFT'));
        $published = $board->versions()->first();
        $this->assertNotNull($secret);
        $this->assertTrue($this->viewer->can('view', $board));

        // the viewer sees only the published snapshot
        $this->assertSame([$published->version], $this->versionNumbers($this->viewer, $board));
        $this->actingAs($this->viewer)->getJson($this->pageRoute('pages.versions.show', $board, [$secret->version]))->assertNotFound();
        $this->actingAs($this->viewer)->getJson($this->pageRoute('pages.versions.show', $board, [$published->version]))->assertOk()->assertDontSee('PRIVATE DRAFT');
        $headers = $this->appHeaders($this->appToken($this->viewer));
        $this->assertSame([$published->version], collect($this->getJson('/api/v1/pages/'.$board->uuid.'/versions', $headers)->assertOk()->json('data'))->pluck('version')->all());
        $this->getJson('/api/v1/pages/'.$board->uuid.'/versions/'.$secret->version, $headers)->assertNotFound();

        // another editor can't restore (and so publish) a version they can't see
        $this->actingAs($this->editor)->post($this->pageRoute('pages.versions.restore', $board, [$secret->version]))->assertNotFound();

        // the author and admins keep the whole history
        $this->assertContains($secret->version, $this->versionNumbers($this->author, $board));
        $this->actingAs($this->admin)->getJson($this->pageRoute('pages.versions.show', $board, [$secret->version]))->assertOk()->assertSee('PRIVATE DRAFT');
    }

    public function test_checkpoints_after_unpublishing_stay_private_after_republishing(): void
    {
        $board = $this->publishedPage(['name' => 'Retro', 'content' => $this->scene('v1')]);
        $board->recordVersion($this->author);
        $board->fresh()->unpublish($this->author);
        $this->saveScene($board, 'UNPUBLISHED SECRET');
        $board->fresh()->publish($this->author);

        $secret = $board->versions()->get()->first(fn (PageVersion $v) => str_contains(json_encode($v->content), 'UNPUBLISHED SECRET') && $v->is_draft);
        $this->assertNotNull($secret);
        $this->assertNotContains($secret->version, $this->versionNumbers($this->viewer, $board->fresh()));
    }

    public function test_the_backfill_marks_whiteboard_versions_made_before_the_first_publish(): void
    {
        $board = $this->publishedPage(['name' => 'Retro', 'content' => $this->scene('x'), 'published_at' => now()]);
        $board->versions()->update(['created_at' => now()]);
        $old = $board->versions()->create(['version' => 11, 'name' => 'Retro', 'content' => $this->scene('old'), 'created_by' => $this->author->id]);
        $old->forceFill(['created_at' => now()->subDay()])->save();
        $live = $board->versions()->create(['version' => 12, 'name' => 'Retro', 'content' => $this->scene('x'), 'created_by' => $this->author->id]);
        $doc = $this->publishedPage(['name' => 'Doc', 'published_at' => now()]);
        $docVersion = $doc->versions()->create(['version' => 11, 'name' => 'Doc', 'content' => $this->html('<p>x</p>'), 'created_by' => $this->author->id]);
        $docVersion->forceFill(['created_at' => now()->subDay()])->save();

        Artisan::call('pages:mark-draft-versions');
        $this->assertFalse((bool) $old->fresh()->is_draft); // dry run

        Artisan::call('pages:mark-draft-versions', ['--force' => true]);
        $this->assertTrue((bool) $old->fresh()->is_draft);
        $this->assertFalse((bool) $live->fresh()->is_draft);
        $this->assertFalse((bool) $docVersion->fresh()->is_draft); // docs only record versions when published
    }
}
