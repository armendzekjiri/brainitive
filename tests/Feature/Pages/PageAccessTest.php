<?php

namespace Tests\Feature\Pages;

use App\Models\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PageAccessTest extends TestCase
{
    use InteractsWithPages;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPages();
    }

    public function test_unpublished_pages_are_visible_to_their_author_and_admins_only(): void
    {
        $page = $this->draftPage();

        $this->actingAs($this->author)->get($this->pageRoute('pages.show', $page))->assertOk();
        $this->actingAs($this->admin)->get($this->pageRoute('pages.show', $page))->assertOk();
        $this->actingAs($this->workspace->owner)->get($this->pageRoute('pages.show', $page))->assertOk();
        $this->actingAs($this->editor)->get($this->pageRoute('pages.show', $page))->assertForbidden();
        $this->actingAs($this->viewer)->get($this->pageRoute('pages.show', $page))->assertForbidden();
    }

    public function test_published_pages_and_folders_are_visible_to_project_members_but_not_outsiders(): void
    {
        $page = $this->publishedPage();
        $folder = Page::factory()->folder()->create(['project_id' => $this->project->id, 'created_by' => $this->author->id]);

        $this->actingAs($this->viewer)->get($this->pageRoute('pages.show', $page))->assertOk();
        $this->actingAs($this->editor)->get($this->pageRoute('pages.show', $folder))->assertOk();
        $this->actingAs($this->outsider)->get($this->pageRoute('pages.show', $page))->assertForbidden();
    }

    public function test_viewers_cannot_edit_or_publish(): void
    {
        $page = $this->publishedPage();

        $this->actingAs($this->viewer)->putJson($this->pageRoute('pages.draft.save', $page), ['content' => $this->html('<p>x</p>')])->assertForbidden();
        $this->actingAs($this->viewer)->put($this->pageRoute('pages.publish-changes', $page))->assertForbidden();
        $this->actingAs($this->viewer)->put($this->pageRoute('pages.update', $page), ['name' => 'Hijacked'])->assertForbidden();
        $this->actingAs($this->viewer)->post($this->pageRoute('pages.unpublish', $page))->assertForbidden();
        $this->actingAs($this->viewer)->delete($this->pageRoute('pages.draft.discard', $page))->assertForbidden();
        $this->actingAs($this->outsider)->putJson($this->pageRoute('pages.draft.save', $page), ['name' => 'x'])->assertForbidden();

        $this->assertSame('Runbook', $page->fresh()->name);
    }

    public function test_viewers_cannot_create_pages(): void
    {
        $route = route('pages.store', [$this->workspace->slug, $this->project->slug]);

        $this->actingAs($this->viewer)->post($route, ['name' => 'Nope', 'type' => 'page'])->assertForbidden();
        $this->actingAs($this->editor)->post($route, ['name' => 'Yes', 'type' => 'page'])->assertRedirect();

        $this->assertDatabaseHas('pages', ['name' => 'Yes', 'created_by' => $this->editor->id, 'status' => 'draft']);
        $this->assertDatabaseMissing('pages', ['name' => 'Nope']);
    }

    public function test_other_editors_cannot_edit_someone_elses_unpublished_page(): void
    {
        $page = $this->draftPage();

        $this->actingAs($this->editor)->putJson($this->pageRoute('pages.draft.save', $page), ['name' => 'Mine now'])->assertForbidden();
        $this->actingAs($this->admin)->putJson($this->pageRoute('pages.draft.save', $page), ['name' => 'Admin edit'])->assertOk();
    }

    public function test_only_the_author_or_admins_can_delete(): void
    {
        $page = $this->publishedPage();
        $other = $this->publishedPage(['name' => 'Other']);

        $this->actingAs($this->editor)->delete($this->pageRoute('pages.destroy', $page))->assertForbidden();
        $this->actingAs($this->author)->delete($this->pageRoute('pages.destroy', $page))->assertRedirect();
        $this->actingAs($this->admin)->delete($this->pageRoute('pages.destroy', $other))->assertRedirect();

        $this->assertDatabaseMissing('pages', ['id' => $page->id]);
        $this->assertDatabaseMissing('pages', ['id' => $other->id]);
    }

    public function test_listings_hide_other_peoples_unpublished_pages(): void
    {
        $published = $this->publishedPage(['name' => 'Public doc']);
        $draft = $this->draftPage(['name' => 'Secret draft']);
        $child = $this->draftPage(['name' => 'Secret child', 'parent_id' => $published->id]);

        $this->actingAs($this->editor)
            ->getJson(route('pages.tree', [$this->workspace->slug, $this->project->slug]))
            ->assertOk()
            ->assertJsonFragment(['name' => 'Public doc'])
            ->assertJsonMissing(['name' => 'Secret draft'])
            ->assertJsonMissing(['name' => 'Secret child']);

        $this->actingAs($this->editor)
            ->get($this->pageRoute('pages.show', $published))
            ->assertInertia(fn (Assert $inertia) => $inertia
                ->has('page.children', 0)
                ->has('currentWorkspace.projects.0.pages', 1)
                ->where('currentWorkspace.projects.0.pages.0.name', 'Public doc')
                ->has('currentWorkspace.projects.0.pages.0.children', 0));

        $published->saveDraft($this->author, ['content' => $this->html('<p>Unreleased plan</p>')]);
        $this->actingAs($this->viewer)
            ->getJson(route('pages.tree', [$this->workspace->slug, $this->project->slug]))
            ->assertJsonMissingPath('0.draft_content')
            ->assertDontSee('Unreleased plan');

        $this->actingAs($this->author)
            ->getJson(route('pages.tree', [$this->workspace->slug, $this->project->slug]))
            ->assertJsonFragment(['name' => 'Secret draft'])
            ->assertJsonFragment(['name' => 'Secret child']);

        $this->assertSame(
            [$published->id],
            Page::visibleTo($this->editor)->whereIn('id', [$published->id, $draft->id, $child->id])->pluck('id')->all(),
        );
    }

    public function test_editors_can_duplicate_and_the_copy_starts_clean(): void
    {
        $page = $this->publishedPage();
        $page->saveDraft($this->author, ['content' => $this->html('<p>Pending</p>')]);

        $this->actingAs($this->editor)
            ->post($this->pageRoute('pages.duplicate', $page))
            ->assertRedirect();

        $copy = Page::where('name', 'Copy of Runbook')->firstOrFail();
        $this->assertTrue($copy->isDraft());
        $this->assertSame($this->editor->id, $copy->created_by);
        $this->assertSame('<p>Live text</p>', $copy->content['html']);
        $this->assertNull($copy->draft_content);
        $this->assertSame(0, $copy->current_version);
    }

    public function test_watching_only_affects_the_signed_in_user(): void
    {
        $page = $this->publishedPage();

        $this->actingAs($this->viewer)
            ->post($this->pageRoute('pages.watch', $page), ['user_id' => $this->editor->id, 'watch' => true])
            ->assertRedirect();

        $this->assertTrue($page->watchers()->whereKey($this->viewer->id)->exists());
        $this->assertFalse($page->watchers()->whereKey($this->editor->id)->exists());
    }
}
