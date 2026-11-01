<?php

namespace Tests\Feature\Boards;

use App\Models\Board;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * BRAI-435: linked issues in a project the viewer can't open show as "Restricted" — no id, key,
 * title or board — on the board, the live card refetch and the issue page.
 */
class LinkedIssueVisibilityTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    private Item $item;

    private Item $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        $owner = User::find($this->workspace->owner_id);

        // a project in the same workspace the editor is not a member of
        $private = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $owner->id]);
        $privateBoard = Board::factory()->create(['project_id' => $private->id, 'created_by' => $owner->id]);
        $this->secret = Item::factory()->create(['board_id' => $privateBoard->id, 'title' => 'Secret roadmap item', 'created_by' => $owner->id]);

        $this->item = $this->item(['title' => 'Visible issue']);
        $sibling = $this->item(['title' => 'Sibling issue']);
        ItemLink::create(['source_item_id' => $this->secret->id, 'target_item_id' => $this->item->id, 'link_type' => 'blocks', 'created_by' => $owner->id]);
        ItemLink::create(['source_item_id' => $this->item->id, 'target_item_id' => $this->secret->id, 'link_type' => 'relates_to', 'created_by' => $owner->id]);
        ItemLink::create(['source_item_id' => $this->item->id, 'target_item_id' => $sibling->id, 'link_type' => 'duplicates', 'created_by' => $owner->id]);
    }

    public function test_the_board_hides_linked_issues_from_a_project_the_viewer_cannot_open(): void
    {
        $response = $this->actingAs($this->editor)
            ->get(route('boards.show', [$this->workspace->slug, $this->project->slug, $this->board->slug]))
            ->assertOk();

        $this->assertStringNotContainsString('Secret roadmap item', $response->getContent());
        $response->assertInertia(fn (Assert $page) => $page->where('board.columns', function ($columns) {
            $card = collect($columns)->flatMap(fn ($column) => $column['items'])->firstWhere('id', $this->item->id);
            $incoming = $card['incoming_links'][0];
            $outgoing = collect($card['outgoing_links'])->keyBy('link_type');

            // still blocked (the badge needs the type), but nothing about the other issue
            return $incoming['link_type'] === 'blocks' && $incoming['restricted'] === true
                && $incoming['source_item'] === null && ! array_key_exists('source_item_id', $incoming)
                && $outgoing['relates_to']['restricted'] === true && $outgoing['relates_to']['target_item'] === null
                // a link inside the project is untouched
                && ! isset($outgoing['duplicates']['restricted']) && $outgoing['duplicates']['target_item']['title'] === 'Sibling issue';
        }));
    }

    public function test_the_issue_page_and_the_live_card_refetch_hide_them_too(): void
    {
        $page = $this->actingAs($this->editor)->get($this->itemRoute('boards.items.show', $this->item))->assertOk();
        $this->assertStringNotContainsString('Secret roadmap item', $page->getContent());
        $this->assertStringNotContainsString((string) $this->secret->key, $page->getContent());

        $cards = $this->actingAs($this->editor)
            ->getJson(route('boards.cards', [$this->workspace->slug, $this->project->slug, $this->board->slug, 'ids' => [$this->item->id]]))
            ->assertOk()
            ->assertJsonPath('items.0.incoming_links.0.restricted', true)
            ->assertJsonPath('items.0.incoming_links.0.source_item', null);
        $this->assertStringNotContainsString('Secret roadmap item', $cards->getContent());
    }

    public function test_a_workspace_admin_who_can_open_both_projects_sees_the_title(): void
    {
        $owner = User::find($this->workspace->owner_id);

        $this->actingAs($owner)
            ->getJson(route('boards.cards', [$this->workspace->slug, $this->project->slug, $this->board->slug, 'ids' => [$this->item->id]]))
            ->assertOk()
            ->assertJsonPath('items.0.incoming_links.0.source_item.title', 'Secret roadmap item')
            ->assertJsonMissingPath('items.0.incoming_links.0.restricted');
    }
}
