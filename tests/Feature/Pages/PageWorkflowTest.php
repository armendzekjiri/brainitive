<?php

namespace Tests\Feature\Pages;

use App\Models\Page;
use App\Notifications\PageUpdatedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PageWorkflowTest extends TestCase
{
    use InteractsWithPages;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPages();
        Notification::fake();
    }

    public function test_autosave_on_a_published_page_is_kept_as_a_draft_and_readers_still_see_the_live_version(): void
    {
        $page = $this->publishedPage();

        $this->actingAs($this->editor)
            ->putJson($this->pageRoute('pages.draft.save', $page), [
                'content' => json_encode($this->html('<p>Work in progress</p>')),
                'base_revision' => $page->edit_revision,
            ])
            ->assertOk()
            ->assertJson(['has_unpublished_changes' => true, 'edit_revision' => $page->edit_revision + 1]);

        $page->refresh();
        $this->assertSame('<p>Live text</p>', $page->content['html']);
        $this->assertSame('<p>Work in progress</p>', $page->draft_content['html']);
        $this->assertSame($this->editor->id, $page->draft_updated_by);

        $this->actingAs($this->viewer)
            ->get($this->pageRoute('pages.show', $page))
            ->assertOk()
            ->assertInertia(fn (Assert $inertia) => $inertia
                ->component('Pages/Show')
                ->where('page.content.html', '<p>Live text</p>')
                ->missing('page.draft_content')
                ->where('can.update', false));

        $this->actingAs($this->editor)
            ->get($this->pageRoute('pages.show', $page))
            ->assertInertia(fn (Assert $inertia) => $inertia
                ->where('page.draft_content.html', '<p>Work in progress</p>')
                ->where('can.update', true));
    }

    public function test_title_and_icon_changes_on_a_published_page_wait_for_update(): void
    {
        $page = $this->publishedPage();

        $this->actingAs($this->editor)
            ->putJson($this->pageRoute('pages.draft.save', $page), ['name' => 'Runbook v2', 'icon' => '🚀'])
            ->assertOk();

        $page->refresh();
        $this->assertSame('Runbook', $page->name);
        $this->assertSame('Runbook v2', $page->draft_name);
        $this->assertSame('🚀', $page->draft_icon);
        $this->assertSame('<p>Live text</p>', $page->draft_content['html']);
    }

    public function test_autosave_on_an_unpublished_page_updates_its_content_directly(): void
    {
        $page = $this->draftPage();

        $this->actingAs($this->author)
            ->putJson($this->pageRoute('pages.draft.save', $page), [
                'content' => $this->html('<p>Second draft</p>'),
                'name' => 'Renamed',
            ])
            ->assertOk()
            ->assertJson(['has_unpublished_changes' => false]);

        $page->refresh();
        $this->assertSame('<p>Second draft</p>', $page->content['html']);
        $this->assertSame('Renamed', $page->name);
        $this->assertNull($page->draft_content);
        $this->assertSame($this->author->id, $page->last_edited_by);
    }

    public function test_publishing_a_draft_records_version_one(): void
    {
        $page = $this->draftPage();

        $this->actingAs($this->author)
            ->post($this->pageRoute('pages.publish', $page), ['message' => 'Initial version'])
            ->assertRedirect();

        $page->refresh();
        $this->assertTrue($page->isPublished());
        $this->assertNotNull($page->published_at);
        $this->assertSame(1, $page->current_version);
        $this->assertDatabaseHas('page_versions', [
            'page_id' => $page->id,
            'version' => 1,
            'message' => 'Initial version',
            'created_by' => $this->author->id,
        ]);
    }

    public function test_update_makes_the_draft_live_as_a_new_version_and_notifies_watchers(): void
    {
        $page = $this->publishedPage();
        $page->addWatcher($this->viewer);
        $page->saveDraft($this->editor, ['content' => $this->html('<p>Better text</p>'), 'name' => 'Runbook v2']);

        $this->actingAs($this->editor)
            ->put($this->pageRoute('pages.publish-changes', $page), ['message' => 'Clarified steps'])
            ->assertRedirect()
            ->assertSessionHas('success', 'Page updated to version 2.');

        $page->refresh();
        $this->assertSame('<p>Better text</p>', $page->content['html']);
        $this->assertSame('Runbook v2', $page->name);
        $this->assertFalse($page->hasUnpublishedChanges());
        $this->assertSame(2, $page->current_version);
        $this->assertSame($this->editor->id, $page->last_edited_by);
        $this->assertDatabaseHas('page_versions', ['page_id' => $page->id, 'version' => 2, 'message' => 'Clarified steps', 'name' => 'Runbook v2']);

        Notification::assertSentTo($this->viewer, PageUpdatedNotification::class);
        Notification::assertNotSentTo($this->editor, PageUpdatedNotification::class);
    }

    public function test_update_can_skip_notifying_watchers(): void
    {
        $page = $this->publishedPage();
        $page->addWatcher($this->viewer);
        $page->saveDraft($this->editor, ['content' => $this->html('<p>Typo fix</p>')]);

        $this->actingAs($this->editor)
            ->put($this->pageRoute('pages.publish-changes', $page), ['notify' => false])
            ->assertRedirect();

        $this->assertSame('<p>Typo fix</p>', $page->fresh()->content['html']);
        Notification::assertNothingSent();
    }

    public function test_update_publishes_the_editor_state_sent_with_the_request(): void
    {
        $page = $this->publishedPage();

        $this->actingAs($this->editor)
            ->put($this->pageRoute('pages.publish-changes', $page), [
                'content' => json_encode($this->html('<p>Typed just before clicking Update</p>')),
            ])
            ->assertRedirect();

        $this->assertSame('<p>Typed just before clicking Update</p>', $page->fresh()->content['html']);
    }

    public function test_update_without_changes_creates_no_version(): void
    {
        $page = $this->publishedPage();

        $this->actingAs($this->editor)
            ->put($this->pageRoute('pages.publish-changes', $page))
            ->assertRedirect()
            ->assertSessionHas('success', 'No changes to publish.');

        $this->assertSame(1, $page->fresh()->current_version);
        $this->assertSame(1, $page->versions()->count());
    }

    public function test_update_is_rejected_on_an_unpublished_page(): void
    {
        $page = $this->draftPage();

        $this->actingAs($this->author)
            ->put($this->pageRoute('pages.publish-changes', $page))
            ->assertSessionHasErrors('page');

        $this->assertTrue($page->fresh()->isDraft());
    }

    public function test_discarding_returns_the_page_to_its_published_state(): void
    {
        $page = $this->publishedPage();
        $page->saveDraft($this->editor, ['content' => $this->html('<p>Bad idea</p>'), 'name' => 'Oops']);

        $this->actingAs($this->editor)
            ->delete($this->pageRoute('pages.draft.discard', $page))
            ->assertRedirect();

        $page->refresh();
        $this->assertFalse($page->hasUnpublishedChanges());
        $this->assertNull($page->draft_content);
        $this->assertSame('Runbook', $page->name);
        $this->assertSame('<p>Live text</p>', $page->content['html']);
    }

    public function test_unpublishing_keeps_pending_changes_as_the_draft_content(): void
    {
        $page = $this->publishedPage();
        $page->saveDraft($this->author, ['content' => $this->html('<p>Pending</p>')]);

        $this->actingAs($this->author)
            ->post($this->pageRoute('pages.unpublish', $page))
            ->assertRedirect();

        $page->refresh();
        $this->assertTrue($page->isDraft());
        $this->assertSame('<p>Pending</p>', $page->content['html']);
        $this->assertNull($page->draft_content);
    }

    public function test_a_stale_autosave_is_rejected_with_a_conflict_and_can_be_forced(): void
    {
        $page = $this->publishedPage();
        $staleRevision = $page->edit_revision;
        $page->saveDraft($this->editor, ['content' => $this->html('<p>Editor A</p>')]);

        $this->actingAs($this->author)
            ->putJson($this->pageRoute('pages.draft.save', $page), [
                'content' => $this->html('<p>Editor B</p>'),
                'base_revision' => $staleRevision,
            ])
            ->assertStatus(409)
            ->assertJsonPath('conflict.updated_by.name', $this->editor->name)
            ->assertJsonPath('conflict.edit_revision', $staleRevision + 1);

        $this->assertSame('<p>Editor A</p>', $page->fresh()->draft_content['html']);

        $this->actingAs($this->author)
            ->putJson($this->pageRoute('pages.draft.save', $page), [
                'content' => $this->html('<p>Editor B</p>'),
                'base_revision' => $staleRevision,
                'force' => true,
            ])
            ->assertOk();

        $this->assertSame('<p>Editor B</p>', $page->fresh()->draft_content['html']);
    }

    public function test_a_stale_update_is_rejected(): void
    {
        $page = $this->publishedPage();
        $staleRevision = $page->edit_revision;
        $page->saveDraft($this->editor, ['content' => $this->html('<p>Editor A</p>')]);

        $this->actingAs($this->author)
            ->put($this->pageRoute('pages.publish-changes', $page), [
                'content' => $this->html('<p>Editor B</p>'),
                'base_revision' => $staleRevision,
            ])
            ->assertSessionHasErrors('page');

        $this->assertSame('<p>Live text</p>', $page->fresh()->content['html']);
    }

    public function test_excalidraw_embeds_survive_autosave_and_update(): void
    {
        $embed = ['elements' => [['id' => 'a']], 'appState' => [], 'files' => [], 'libraryItems' => []];
        $page = $this->publishedPage(['content' => $this->html('<p>Diagram</p>') + ['excalidrawEmbeds' => ['d1' => $embed]]]);

        $this->actingAs($this->editor)
            ->putJson($this->pageRoute('pages.draft.save', $page), ['content' => $this->html('<p>Diagram v2</p>')])
            ->assertOk();
        $this->actingAs($this->editor)
            ->put($this->pageRoute('pages.publish-changes', $page))
            ->assertRedirect();

        $content = $page->fresh()->content;
        $this->assertSame('<p>Diagram v2</p>', $content['html']);
        $this->assertSame($embed, $content['excalidrawEmbeds']['d1']);
    }

    public function test_history_lists_versions_and_shows_their_content(): void
    {
        $page = $this->publishedPage();
        $page->saveDraft($this->editor, ['content' => $this->html('<p>Second</p>')]);
        $page->publishChanges($this->editor, 'Second pass');

        $this->actingAs($this->viewer)
            ->getJson($this->pageRoute('pages.versions.index', $page))
            ->assertOk()
            ->assertJsonPath('current_version', 2)
            ->assertJsonCount(2, 'versions')
            ->assertJsonPath('versions.0.version', 2)
            ->assertJsonPath('versions.0.message', 'Second pass')
            ->assertJsonPath('versions.0.author.name', $this->editor->name)
            ->assertJsonMissingPath('versions.0.content');

        $this->actingAs($this->viewer)
            ->getJson($this->pageRoute('pages.versions.show', $page, [1]))
            ->assertOk()
            ->assertJsonPath('content.html', '<p>Live text</p>');

        $this->actingAs($this->viewer)
            ->getJson($this->pageRoute('pages.versions.show', $page, [99]))
            ->assertNotFound();
    }

    public function test_restoring_a_version_of_a_published_page_records_a_new_version(): void
    {
        $page = $this->publishedPage();
        $page->saveDraft($this->editor, ['content' => $this->html('<p>Broken</p>'), 'name' => 'Broken runbook']);
        $page->publishChanges($this->editor);
        $page->saveDraft($this->editor, ['content' => $this->html('<p>Unsaved idea</p>')]);

        $this->actingAs($this->editor)
            ->post($this->pageRoute('pages.versions.restore', $page, [1]))
            ->assertRedirect()
            ->assertSessionHas('success', 'Version 1 restored as version 3.');

        $page->refresh();
        $this->assertSame('<p>Live text</p>', $page->content['html']);
        $this->assertSame('Runbook', $page->name);
        $this->assertFalse($page->hasUnpublishedChanges());
        $this->assertSame(3, $page->current_version);
        $this->assertDatabaseHas('page_versions', ['page_id' => $page->id, 'version' => 3, 'message' => 'Restored from version 1']);
    }

    public function test_viewers_cannot_restore_versions(): void
    {
        $page = $this->publishedPage();

        $this->actingAs($this->viewer)
            ->post($this->pageRoute('pages.versions.restore', $page, [1]))
            ->assertForbidden();
    }

    public function test_presence_lists_other_people_on_the_page_and_the_current_revision(): void
    {
        $page = $this->publishedPage();

        $this->actingAs($this->editor)
            ->postJson($this->pageRoute('pages.presence', $page), ['editing' => true])
            ->assertOk()
            ->assertJsonCount(0, 'users');

        $page->saveDraft($this->editor, ['content' => $this->html('<p>Typing</p>')]);

        $this->actingAs($this->viewer)
            ->postJson($this->pageRoute('pages.presence', $page))
            ->assertOk()
            ->assertJsonCount(1, 'users')
            ->assertJsonPath('users.0.name', $this->editor->name)
            ->assertJsonPath('users.0.editing', true)
            ->assertJsonPath('edit_revision', $page->fresh()->edit_revision)
            ->assertJsonPath('draft_updated_by.name', $this->editor->name);

        $this->actingAs($this->editor)
            ->postJson($this->pageRoute('pages.presence', $page), ['leave' => true])
            ->assertOk();

        $this->actingAs($this->viewer)
            ->postJson($this->pageRoute('pages.presence', $page))
            ->assertJsonCount(0, 'users');
    }

    public function test_migration_backfills_version_one_for_already_published_pages(): void
    {
        $migration = require database_path('migrations/2026_10_18_201813_create_page_versions_table.php');
        $migration->down();

        $published = Page::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->author->id, 'name' => 'Legacy']);
        DB::table('pages')->where('id', $published->id)->update(['status' => 'published', 'published_at' => now()]);
        $draft = $this->draftPage();

        $migration->up();

        $this->assertSame(1, $published->fresh()->current_version);
        $this->assertDatabaseHas('page_versions', ['page_id' => $published->id, 'version' => 1, 'name' => 'Legacy']);
        $this->assertSame(0, $draft->fresh()->current_version);
        $this->assertDatabaseMissing('page_versions', ['page_id' => $draft->id]);
    }
}
