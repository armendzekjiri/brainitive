<?php

namespace Tests\Feature\Pages;

use App\Models\Board;
use App\Models\Item;
use App\Models\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class WhiteboardTest extends TestCase
{
    use InteractsWithPages;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPages();
        Notification::fake();
    }

    public function test_save_bumps_the_revision_and_the_first_save_records_a_checkpoint(): void
    {
        $page = $this->whiteboard();

        $this->actingAs($this->editor)
            ->postJson($this->pageRoute('pages.excalidraw.save', $page), $this->scene(['a'], $page->edit_revision))
            ->assertOk()
            ->assertJson(['success' => true, 'edit_revision' => $page->edit_revision + 1, 'version' => 2]);

        $page->refresh();
        $this->assertSame('a', $page->content['elements'][0]['id']);
        $this->assertSame($this->editor->id, $page->last_edited_by);
        $this->assertSame(2, $page->versions()->count());
    }

    public function test_autosaves_by_the_same_user_within_the_interval_do_not_add_versions(): void
    {
        $page = $this->whiteboard();
        $page->saveWhiteboard($this->editor, $this->content(['a']));

        $this->actingAs($this->editor)
            ->postJson($this->pageRoute('pages.excalidraw.save', $page), $this->scene(['a', 'b'], $page->fresh()->edit_revision))
            ->assertOk()
            ->assertJsonPath('version', null);

        $this->assertSame(2, $page->versions()->count());
        $this->assertCount(2, $page->fresh()->content['elements']);
    }

    public function test_a_checkpoint_is_recorded_after_the_interval_on_explicit_save_and_when_another_user_saves(): void
    {
        $page = $this->whiteboard();
        $page->saveWhiteboard($this->editor, $this->content(['a']));

        $this->travel(Page::WHITEBOARD_VERSION_INTERVAL_MINUTES + 1)->minutes();
        $this->assertNotNull($page->fresh()->saveWhiteboard($this->editor, $this->content(['a', 'b'])));

        $this->assertNotNull($page->fresh()->saveWhiteboard($this->editor, $this->content(['a', 'b', 'c']), checkpoint: true));

        $this->assertNotNull($page->fresh()->saveWhiteboard($this->author, $this->content(['x'])));
        $this->assertSame(5, $page->versions()->count());
        $this->assertSame($this->author->id, $page->versions()->first()->created_by);
    }

    public function test_a_stale_base_revision_is_rejected_with_a_conflict_unless_forced(): void
    {
        $page = $this->whiteboard();
        $staleRevision = $page->edit_revision;
        $page->saveWhiteboard($this->author, $this->content(['theirs']));

        $this->actingAs($this->editor)
            ->postJson($this->pageRoute('pages.excalidraw.save', $page), $this->scene(['mine'], $staleRevision))
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('conflict.updated_by.name', $this->author->name);
        $this->assertSame('theirs', $page->fresh()->content['elements'][0]['id']);

        $this->actingAs($this->editor)
            ->postJson($this->pageRoute('pages.excalidraw.save', $page), $this->scene(['mine'], $staleRevision) + ['force' => true])
            ->assertOk();
        $this->assertSame('mine', $page->fresh()->content['elements'][0]['id']);
    }

    public function test_save_without_a_base_revision_keeps_working_for_old_clients(): void
    {
        $page = $this->whiteboard();
        $page->saveWhiteboard($this->author, $this->content(['theirs']));

        $this->actingAs($this->editor)
            ->postJson($this->pageRoute('pages.excalidraw.save', $page), ['diagram_data' => json_encode($this->content(['mine']))])
            ->assertOk();
    }

    public function test_viewers_cannot_save_a_whiteboard(): void
    {
        $page = $this->whiteboard();

        $this->actingAs($this->viewer)
            ->postJson($this->pageRoute('pages.excalidraw.save', $page), $this->scene(['a'], $page->edit_revision))
            ->assertForbidden();
    }

    public function test_restoring_a_whiteboard_version_records_a_new_version(): void
    {
        $page = $this->whiteboard();
        $page->saveWhiteboard($this->editor, $this->content(['first']), checkpoint: true);
        $page->fresh()->saveWhiteboard($this->editor, $this->content(['second']), checkpoint: true);

        $this->actingAs($this->editor)
            ->post($this->pageRoute('pages.versions.restore', $page, [2]))
            ->assertRedirect();

        $page->refresh();
        $this->assertSame('first', $page->content['elements'][0]['id']);
        $this->assertSame(4, $page->current_version);
        $this->assertSame('Restored from version 2', $page->versions()->first()->message);
    }

    public function test_editor_config_includes_the_revision_history_issue_urls_and_new_flag(): void
    {
        $page = $this->whiteboard();
        $board = $this->board();

        $response = $this->actingAs($this->editor)
            ->get($this->pageRoute('pages.excalidraw', $page).'?new=1')
            ->assertOk();

        $config = $response->viewData('excalidrawConfig');
        $this->assertTrue($config['autosave']);
        $this->assertTrue($config['isNew']);
        $this->assertSame($page->edit_revision, $config['editRevision']);
        $this->assertStringContainsString('history=1', $config['historyUrl']);
        $this->assertSame([['id' => $board->id, 'name' => $board->name]], $config['boards']->all());
    }

    public function test_the_template_picker_only_opens_for_an_empty_whiteboard(): void
    {
        $page = $this->whiteboard(['content' => $this->content(['a'])]);

        $config = $this->actingAs($this->editor)
            ->get($this->pageRoute('pages.excalidraw', $page).'?new=1')
            ->viewData('excalidrawConfig');

        $this->assertFalse($config['isNew']);
    }

    public function test_issue_search_returns_cards_of_this_project_only(): void
    {
        $page = $this->whiteboard();
        $board = $this->board();
        $todo = $this->item($board, ['title' => 'Login form', 'assigned_to' => $this->editor->id]);
        $done = $this->item($board, ['title' => 'Logout', 'column_id' => $board->columns()->where('is_done_column', true)->value('id')]);
        $otherProjectBoard = Board::factory()->create();
        Item::factory()->create(['board_id' => $otherProjectBoard->id, 'project_id' => $otherProjectBoard->project_id, 'title' => 'Login elsewhere']);

        $response = $this->actingAs($this->viewer)
            ->getJson($this->pageRoute('pages.whiteboard.issues', $page).'?q=Log')
            ->assertOk()
            ->assertJsonCount(2, 'items');

        $cards = collect($response->json('items'))->keyBy('id');
        $this->assertSame('todo', $cards[$todo->id]['status_category']);
        $this->assertSame($this->editor->name, $cards[$todo->id]['assignee']['name']);
        $this->assertSame('done', $cards[$done->id]['status_category']);
        $this->assertStringContainsString("/items/{$todo->id}", $cards[$todo->id]['url']);
    }

    public function test_issue_refresh_by_ids_ignores_items_of_other_projects(): void
    {
        $page = $this->whiteboard();
        $mine = $this->item($this->board());
        $foreignBoard = Board::factory()->create();
        $foreign = Item::factory()->create(['board_id' => $foreignBoard->id, 'project_id' => $foreignBoard->project_id]);

        $this->actingAs($this->editor)
            ->getJson($this->pageRoute('pages.whiteboard.issues', $page).'?'.http_build_query(['ids' => [$mine->id, $foreign->id]]))
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $mine->id);
    }

    public function test_outsiders_cannot_search_issues(): void
    {
        $this->actingAs($this->outsider)
            ->getJson($this->pageRoute('pages.whiteboard.issues', $this->whiteboard()))
            ->assertForbidden();
    }

    public function test_stickies_become_issues_in_the_first_column_and_backlog(): void
    {
        $page = $this->whiteboard();
        $board = $this->board();

        $response = $this->actingAs($this->editor)
            ->postJson($this->pageRoute('pages.whiteboard.issues.store', $page), [
                'board_id' => $board->id,
                'titles' => ['Onboarding email', ' Pricing page '],
            ])
            ->assertCreated()
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.title', 'Onboarding email')
            ->assertJsonPath('items.1.title', 'Pricing page')
            ->assertJsonPath('items.0.status_category', 'todo');

        $items = Item::whereIn('id', collect($response->json('items'))->pluck('id'))->orderBy('id')->get();
        $firstColumnId = $board->columns()->value('id');
        $this->assertTrue($items->every(fn (Item $item) => $item->column_id === $firstColumnId && $item->sprint_id === null));
        $this->assertSame($this->editor->id, $items[0]->created_by);
        $this->assertNotSame($items[0]->key, $items[1]->key);
        $this->assertSame([1, 2], $items->pluck('position')->all());
    }

    public function test_creating_issues_needs_page_edit_rights_and_a_board_of_this_project(): void
    {
        $page = $this->whiteboard();
        $board = $this->board();
        $foreignBoard = Board::factory()->create();

        $this->actingAs($this->viewer)
            ->postJson($this->pageRoute('pages.whiteboard.issues.store', $page), ['board_id' => $board->id, 'titles' => ['A']])
            ->assertForbidden();

        $this->actingAs($this->editor)
            ->postJson($this->pageRoute('pages.whiteboard.issues.store', $page), ['board_id' => $foreignBoard->id, 'titles' => ['A']])
            ->assertJsonValidationErrors('board_id');

        $this->actingAs($this->editor)
            ->postJson($this->pageRoute('pages.whiteboard.issues.store', $page), ['board_id' => $board->id, 'titles' => []])
            ->assertJsonValidationErrors('titles');

        $this->assertSame(0, Item::count());
    }

    /**
     * A published whiteboard, so every project member can open it.
     */
    private function whiteboard(array $attributes = []): Page
    {
        return $this->publishedPage($attributes + ['name' => 'Retro', 'content' => $this->content([])]);
    }

    /**
     * @param  list<string>  $elementIds
     * @return array{type: string, elements: list<array{id: string}>, appState: array<string, mixed>, files: array<string, mixed>, libraryItems: list<mixed>}
     */
    private function content(array $elementIds): array
    {
        return [
            'type' => 'excalidraw',
            'elements' => array_map(fn (string $id) => ['id' => $id], $elementIds),
            'appState' => [],
            'files' => [],
            'libraryItems' => [],
        ];
    }

    /**
     * @param  list<string>  $elementIds
     * @return array{diagram_data: string, base_revision: int}
     */
    private function scene(array $elementIds, int $baseRevision): array
    {
        return ['diagram_data' => json_encode($this->content($elementIds)), 'base_revision' => $baseRevision];
    }

    private function board(): Board
    {
        return Board::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->author->id]);
    }

    private function item(Board $board, array $attributes = []): Item
    {
        return Item::factory()->create($attributes + [
            'board_id' => $board->id,
            'project_id' => $this->project->id,
            'column_id' => $board->columns()->value('id'),
            'created_by' => $this->author->id,
        ]);
    }
}
