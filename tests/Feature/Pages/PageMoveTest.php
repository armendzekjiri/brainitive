<?php

namespace Tests\Feature\Pages;

use App\Models\Page;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Moving pages, whiteboards and folders in the project tree (BRAI-431, GitHub #130).
 */
class PageMoveTest extends TestCase
{
    use InteractsWithPages;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPages();
    }

    private function folder(string $name, ?Page $parent = null): Page
    {
        return Page::factory()->folder()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->author->id,
            'parent_id' => $parent?->id,
            'name' => $name,
        ]);
    }

    private function doc(string $name, ?Page $parent = null): Page
    {
        return Page::factory()->published()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->author->id,
            'parent_id' => $parent?->id,
            'name' => $name,
        ]);
    }

    private function move(Page $page, array $data)
    {
        return $this->postJson(route('pages.move', [$this->workspace->slug, $this->project->slug, $page->uuid]), $data);
    }

    /**
     * @return array<int, string>
     */
    private function order(?Page $parent): array
    {
        return Page::where('project_id', $this->project->id)
            ->where('parent_id', $parent?->id)
            ->orderBy('type', 'desc')->orderBy('position')
            ->pluck('name')->all();
    }

    /**
     * @return array<int, int>
     */
    private function positions(?Page $parent): array
    {
        return Page::where('project_id', $this->project->id)
            ->where('parent_id', $parent?->id)
            ->orderBy('type', 'desc')->orderBy('position')
            ->pluck('position')->all();
    }

    public function test_reporters_case_moves_a_doc_into_a_sibling_folder(): void
    {
        $meetings = $this->folder('Meetings');
        $log = $this->doc('Decision log', $meetings);
        $newFolder = $this->folder('New Folder', $meetings);

        $this->actingAs($this->editor);
        $this->move($log, ['parent_id' => $newFolder->id])
            ->assertOk()
            ->assertJson(['id' => $log->id, 'parent_id' => $newFolder->id, 'position' => 0, 'path' => "{$this->project->name} / Meetings / New Folder"]);

        $this->assertSame($newFolder->id, $log->fresh()->parent_id);
        $this->assertSame(['New Folder'], $this->order($meetings));
        $this->assertSame([0], $this->positions($meetings));
    }

    public function test_moves_to_the_top_level(): void
    {
        $meetings = $this->folder('Meetings');
        $log = $this->doc('Decision log', $meetings);

        $this->actingAs($this->editor);
        $this->move($log, ['parent_id' => null])->assertOk()->assertJson(['parent_id' => null, 'path' => $this->project->name]);

        $this->assertNull($log->fresh()->parent_id);
        $this->assertSame([], $this->order($meetings));
    }

    public function test_before_and_after_keep_positions_consistent_in_both_parents(): void
    {
        $a = $this->folder('A');
        $b = $this->folder('B');
        $a1 = $this->doc('A1', $a);
        $a2 = $this->doc('A2', $a);
        $a3 = $this->doc('A3', $a);
        $b1 = $this->doc('B1', $b);
        $b2 = $this->doc('B2', $b);

        $this->actingAs($this->editor);
        $this->move($a2, ['parent_id' => $b->id, 'before_id' => $b2->id])->assertOk();

        $this->assertSame(['A1', 'A3'], $this->order($a));
        $this->assertSame([0, 1], $this->positions($a));
        $this->assertSame(['B1', 'A2', 'B2'], $this->order($b));
        $this->assertSame([0, 1, 2], $this->positions($b));

        $this->move($a1, ['parent_id' => $b->id, 'after_id' => $b2->id])->assertOk();
        $this->assertSame(['B1', 'A2', 'B2', 'A1'], $this->order($b));
        $this->assertSame([0, 1, 2, 3], $this->positions($b));
        $this->assertSame([0], $this->positions($a));
    }

    public function test_reorders_within_the_same_parent(): void
    {
        $folder = $this->folder('Docs');
        $one = $this->doc('One', $folder);
        $two = $this->doc('Two', $folder);
        $three = $this->doc('Three', $folder);

        $this->actingAs($this->editor);
        $this->move($three, ['parent_id' => $folder->id, 'before_id' => $one->id])->assertOk();

        $this->assertSame(['Three', 'One', 'Two'], $this->order($folder));
        $this->assertSame([0, 1, 2], $this->positions($folder));
        $this->assertSame($folder->id, $three->fresh()->parent_id);
        $this->assertNotNull($two->fresh());
    }

    public function test_a_folder_cannot_go_into_itself_or_its_subfolder(): void
    {
        $meetings = $this->folder('Meetings');
        $sub = $this->folder('Sub', $meetings);
        $deeper = $this->folder('Deeper', $sub);

        $this->actingAs($this->editor);
        $this->move($meetings, ['parent_id' => $meetings->id])->assertStatus(422)->assertJsonValidationErrors('parent_id');
        $this->move($meetings, ['parent_id' => $deeper->id])->assertStatus(422)->assertJsonValidationErrors('parent_id');

        $this->assertNull($meetings->fresh()->parent_id);
        $this->assertSame($meetings->id, $sub->fresh()->parent_id);
    }

    public function test_destination_in_another_project_is_rejected(): void
    {
        $doc = $this->doc('Decision log');
        $otherProject = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->admin->id]);
        $foreign = Page::factory()->folder()->create(['project_id' => $otherProject->id, 'created_by' => $this->admin->id, 'name' => 'Foreign']);

        $this->actingAs($this->editor);
        $this->move($doc, ['parent_id' => $foreign->id])->assertStatus(422)->assertJsonValidationErrors('parent_id');

        $sibling = $this->doc('Elsewhere');
        $foreignDoc = Page::factory()->create(['project_id' => $otherProject->id, 'created_by' => $this->admin->id, 'name' => 'Foreign doc']);
        $this->move($doc, ['parent_id' => null, 'before_id' => $foreignDoc->id])->assertStatus(422);

        // an anchor that isn't in the destination folder
        $folder = $this->folder('Folder');
        $this->move($doc, ['parent_id' => $folder->id, 'before_id' => $sibling->id])->assertStatus(422)->assertJsonValidationErrors('before_id');

        $this->assertNull($doc->fresh()->parent_id);
    }

    public function test_page_of_another_project_cannot_be_moved_through_this_project(): void
    {
        $otherProject = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->admin->id]);
        $foreign = Page::factory()->published()->create(['project_id' => $otherProject->id, 'created_by' => $this->admin->id]);

        $this->actingAs($this->admin);
        $this->move($foreign, ['parent_id' => null])->assertNotFound();
    }

    public function test_viewers_and_outsiders_cannot_move(): void
    {
        $folder = $this->folder('Meetings');
        $doc = $this->doc('Decision log');

        $this->actingAs($this->viewer);
        $this->move($doc, ['parent_id' => $folder->id])->assertForbidden();

        $this->actingAs($this->outsider);
        $this->move($doc, ['parent_id' => $folder->id])->assertForbidden();

        $this->assertNull($doc->fresh()->parent_id);
    }

    public function test_nobody_but_author_and_admins_can_move_into_an_unpublished_page(): void
    {
        $draft = $this->draftPage(['name' => 'Private draft']);
        $doc = $this->doc('Decision log');

        $this->actingAs($this->editor);
        $this->move($doc, ['parent_id' => $draft->id])->assertStatus(422)->assertJsonValidationErrors('parent_id');

        $this->actingAs($this->author);
        $this->move($doc, ['parent_id' => $draft->id])->assertOk();
    }

    public function test_whiteboards_and_folders_move_too(): void
    {
        $target = $this->folder('Target');
        $board = Page::factory()->published()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->author->id,
            'name' => 'Sketch',
            'content' => ['type' => 'excalidraw', 'elements' => [], 'appState' => []],
        ]);
        $folder = $this->folder('Old stuff');
        $this->doc('Inside old stuff', $folder);

        $this->actingAs($this->editor);
        $this->move($board, ['parent_id' => $target->id])->assertOk();
        $this->move($folder, ['parent_id' => $target->id])->assertOk();

        $this->assertSame($target->id, $board->fresh()->parent_id);
        $this->assertSame($target->id, $folder->fresh()->parent_id);
        $this->assertSame(['Sketch', 'Old stuff'], $this->order($target));
    }

    public function test_a_slug_clash_in_the_destination_gets_a_new_slug_and_updated_at_stays(): void
    {
        $folder = $this->folder('Meetings');
        $this->doc('Decision log', $folder);
        $doc = $this->doc('Decision log');
        $doc->forceFill(['updated_at' => now()->subWeek()])->saveQuietly();
        $before = $doc->fresh()->updated_at;

        $this->actingAs($this->editor);
        $this->move($doc, ['parent_id' => $folder->id])->assertOk();

        $doc->refresh();
        $this->assertSame('decision-log-2', $doc->slug);
        $this->assertTrue($before->equalTo($doc->updated_at));
    }

    public function test_update_with_a_parent_goes_through_the_same_checks(): void
    {
        $meetings = $this->folder('Meetings');
        $sub = $this->folder('Sub', $meetings);
        $doc = $this->doc('Decision log');

        $this->actingAs($this->editor);
        $this->putJson(route('pages.update', [$this->workspace->slug, $this->project->slug, $meetings->uuid]), ['parent_id' => $sub->id])
            ->assertStatus(422)->assertJsonValidationErrors('parent_id');

        $this->putJson(route('pages.update', [$this->workspace->slug, $this->project->slug, $doc->uuid]), ['parent_id' => $meetings->id])
            ->assertOk();
        $this->assertSame($meetings->id, $doc->fresh()->parent_id);
        $this->assertSame(['Decision log', 'Sub'], $this->order($meetings));
    }

    public function test_reorder_endpoint_no_longer_changes_parents(): void
    {
        $meetings = $this->folder('Meetings');
        $sub = $this->folder('Sub', $meetings);
        $one = $this->doc('One');
        $two = $this->doc('Two');

        $this->actingAs($this->editor);
        $this->postJson(route('pages.reorder', [$this->workspace->slug, $this->project->slug]), [
            'pages' => [['id' => $meetings->id, 'position' => 0, 'parent_id' => $sub->id]],
        ])->assertStatus(422);
        $this->assertNull($meetings->fresh()->parent_id);

        $this->postJson(route('pages.reorder', [$this->workspace->slug, $this->project->slug]), [
            'pages' => [['id' => $two->id, 'position' => 0, 'parent_id' => null], ['id' => $one->id, 'position' => 1, 'parent_id' => null]],
        ])->assertOk();
        $this->assertSame(['Two', 'One', 'Meetings'], $this->order(null));
    }

    public function test_new_pages_are_appended_to_their_parent(): void
    {
        $folder = $this->folder('Docs');
        $this->actingAs($this->editor);
        foreach (['One', 'Two', 'Three'] as $name) {
            $this->postJson(route('pages.store', [$this->workspace->slug, $this->project->slug]), ['name' => $name, 'type' => 'page', 'parent_id' => $folder->id])->assertOk();
        }

        $this->assertSame(['One', 'Two', 'Three'], $this->order($folder));
        $this->assertSame([0, 1, 2], $this->positions($folder));
    }

    public function test_sidebar_marks_which_projects_allow_page_edits(): void
    {
        $this->actingAs($this->editor)
            ->get(route('projects.show', [$this->workspace->slug, $this->project->slug]))
            ->assertInertia(fn ($page) => $page->where('currentWorkspace.projects.0.can_edit_pages', true));

        $this->actingAs($this->viewer)
            ->get(route('projects.show', [$this->workspace->slug, $this->project->slug]))
            ->assertInertia(fn ($page) => $page->where('currentWorkspace.projects.0.can_edit_pages', false));
    }
}
