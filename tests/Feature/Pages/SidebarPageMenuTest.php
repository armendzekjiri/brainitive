<?php

namespace Tests\Feature\Pages;

use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The menu of a doc / whiteboard / folder row in the sidebar (GitHub #155): `can_delete` per page of the
 * sidebar tree, rename through pages.update and delete without leaving the current page (JSON).
 */
class SidebarPageMenuTest extends TestCase
{
    use InteractsWithPages;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPages();
    }

    private function page(User $author, array $attributes = []): Page
    {
        return Page::factory()->published()->create($attributes + [
            'project_id' => $this->project->id,
            'created_by' => $author->id,
        ]);
    }

    /**
     * Every page of the sidebar tree, flattened: id => can_delete.
     *
     * @return array<int, bool|null>
     */
    private function sidebarFlags(User $user): array
    {
        $response = $this->actingAs($user)
            ->get(route('projects.show', [$this->workspace->slug, $this->project->slug]))
            ->assertOk();
        $props = json_decode(json_encode($response->viewData('page')['props']), true);
        $project = collect($props['currentWorkspace']['projects'])->firstWhere('id', $this->project->id);

        $flags = [];
        $walk = function (array $pages) use (&$walk, &$flags) {
            foreach ($pages as $page) {
                $flags[$page['id']] = $page['can_delete'] ?? null;
                $walk($page['children'] ?? []);
            }
        };
        $walk($project['pages'] ?? []);

        return $flags;
    }

    public function test_sidebar_can_delete_matches_the_page_policy_for_every_role(): void
    {
        $folder = Page::factory()->folder()->create(['project_id' => $this->project->id, 'created_by' => $this->author->id, 'name' => 'Docs']);
        $nested = $this->page($this->author, ['parent_id' => $folder->id, 'name' => 'Nested']);
        $byAuthor = $this->page($this->author, ['name' => 'By author']);
        $byEditor = $this->page($this->editor, ['name' => 'By editor']);
        $pages = [$folder, $nested, $byAuthor, $byEditor];

        $owner = User::find($this->workspace->owner_id);
        foreach ([$this->author, $this->editor, $this->viewer, $this->admin, $owner] as $user) {
            $flags = $this->sidebarFlags($user);
            foreach ($pages as $page) {
                $this->assertArrayHasKey($page->id, $flags, "page {$page->name} is in the tree");
                $this->assertSame(
                    $user->can('delete', $page->fresh()),
                    $flags[$page->id],
                    "can_delete of {$page->name} for user {$user->id}",
                );
            }
        }

        // spot checks of the rule itself
        $this->assertTrue($this->sidebarFlags($this->author)[$nested->id]);
        $this->assertFalse($this->sidebarFlags($this->editor)[$byAuthor->id]);
        $this->assertFalse($this->sidebarFlags($this->viewer)[$byAuthor->id]);
        $this->assertTrue($this->sidebarFlags($this->admin)[$byEditor->id]);
    }

    public function test_author_who_became_a_viewer_can_no_longer_delete(): void
    {
        $page = $this->page($this->author);
        $this->project->users()->updateExistingPivot($this->author->id, ['role' => 'viewer']);

        $this->assertFalse($this->sidebarFlags($this->author)[$page->id]);
        $this->assertFalse($this->author->can('delete', $page->fresh()));
    }

    public function test_rename_from_the_sidebar_renames_a_published_page_and_records_a_version(): void
    {
        $page = $this->publishedPage();
        $versions = $page->versions()->count();

        $this->actingAs($this->editor)
            ->putJson($this->pageRoute('pages.update', $page), ['name' => 'Ops runbook'])
            ->assertOk()
            ->assertJsonPath('name', 'Ops runbook');

        $page->refresh();
        $this->assertSame('Ops runbook', $page->name);
        $this->assertSame($versions + 1, $page->versions()->count());
        $this->assertSame('Renamed', $page->versions()->latest('version')->first()->message);
    }

    public function test_rename_keeps_an_open_draft_in_step(): void
    {
        $page = $this->publishedPage();
        $page->saveDraft($this->author, ['content' => $this->html('<p>Work in progress</p>')]);
        $this->assertTrue($page->fresh()->hasUnpublishedChanges());

        $this->actingAs($this->editor)
            ->putJson($this->pageRoute('pages.update', $page), ['name' => 'Ops runbook'])
            ->assertOk();

        $page->refresh();
        $this->assertSame('Ops runbook', $page->name);
        $this->assertSame('Ops runbook', $page->draft_name);
        // publishing the draft doesn't bring the old title back
        $page->publishChanges($this->author);
        $this->assertSame('Ops runbook', $page->fresh()->name);
    }

    public function test_rename_of_a_review_required_page_waits_in_the_draft(): void
    {
        $page = $this->publishedPage(['review_required' => true]);

        $this->actingAs($this->editor)
            ->putJson($this->pageRoute('pages.update', $page), ['name' => 'Ops runbook'])
            ->assertOk()
            ->assertJsonPath('name', 'Runbook');

        $page->refresh();
        $this->assertSame('Runbook', $page->name);
        $this->assertSame('Ops runbook', $page->draft_name);
    }

    public function test_viewer_can_not_rename(): void
    {
        $page = $this->publishedPage();

        $this->actingAs($this->viewer)
            ->putJson($this->pageRoute('pages.update', $page), ['name' => 'Nope'])
            ->assertForbidden();

        $this->assertSame('Runbook', $page->fresh()->name);
    }

    public function test_delete_as_json_stays_on_the_current_page(): void
    {
        $page = $this->publishedPage();

        $this->actingAs($this->author)
            ->deleteJson($this->pageRoute('pages.destroy', $page))
            ->assertOk()
            ->assertExactJson(['deleted' => true]);

        $this->assertNull(Page::find($page->id));
    }

    public function test_delete_without_json_still_redirects_to_the_parent(): void
    {
        $folder = Page::factory()->folder()->create(['project_id' => $this->project->id, 'created_by' => $this->author->id]);
        $page = $this->publishedPage(['parent_id' => $folder->id]);

        $this->actingAs($this->author)
            ->delete($this->pageRoute('pages.destroy', $page))
            ->assertRedirect(route('pages.show', [$this->workspace->slug, $this->project->slug, $folder->uuid]));
    }

    public function test_editor_who_is_not_the_author_and_viewer_can_not_delete(): void
    {
        $page = $this->publishedPage();

        $this->actingAs($this->editor)->deleteJson($this->pageRoute('pages.destroy', $page))->assertForbidden();
        $this->actingAs($this->viewer)->deleteJson($this->pageRoute('pages.destroy', $page))->assertForbidden();

        $this->assertNotNull(Page::find($page->id));
    }
}
