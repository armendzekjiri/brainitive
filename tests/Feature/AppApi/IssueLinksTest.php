<?php

namespace Tests\Feature\AppApi;

use App\Models\ActivityLog;
use App\Models\Board;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * App API v1 subtasks and linked issues (BRAI-406): children in board order, subtasks created through
 * `parent_id`, links through ItemLinks (one row, either side, history), restricted issues stay hidden.
 */
class IssueLinksTest extends TestCase
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

    public function test_children_and_creating_a_subtask(): void
    {
        $parent = $this->item(['title' => 'Epic']);
        $second = $this->item(['parent_id' => $parent->id, 'position' => 2, 'title' => 'Second']);
        $first = $this->item(['parent_id' => $parent->id, 'position' => 1, 'title' => 'First']);
        $this->item(['parent_id' => $first->id, 'title' => 'Grandchild']);

        $response = $this->getJson("/api/v1/items/{$parent->id}/children", $this->headers($this->viewer))->assertOk();
        $this->assertSame([$first->id, $second->id], array_column($response->json('data'), 'id'));
        $response->assertJsonPath('data.0.counts.subtasks', 1)->assertJsonPath('data.0.parent_id', $parent->id);

        // a new subtask = an issue with parent_id
        $created = $this->postJson("/api/v1/boards/{$this->board->id}/items", ['title' => 'Third', 'parent_id' => $parent->id], $this->headers())
            ->assertCreated()->assertJsonPath('data.parent.id', $parent->id);
        $this->assertContains($created->json('data.id'), array_column($this->getJson("/api/v1/items/{$parent->id}/children", $this->headers())->json('data'), 'id'));

        // three levels at most
        $grandchild = Item::where('title', 'Grandchild')->firstOrFail();
        $this->postJson("/api/v1/boards/{$this->board->id}/items", ['title' => 'Too deep', 'parent_id' => $grandchild->id], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('parent_id');
    }

    public function test_links_read_from_each_side_and_added_once(): void
    {
        $a = $this->item(['title' => 'A']);
        $b = $this->item(['title' => 'B']);

        $created = $this->postJson("/api/v1/items/{$a->id}/links", ['target_id' => $b->id, 'type' => 'blocks'], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.type', 'blocks')
            ->assertJsonPath('data.direction', 'outgoing')
            ->assertJsonPath('data.restricted', false)
            ->assertJsonPath('data.issue.key', $b->key);
        $this->assertSame(1, ActivityLog::where('loggable_id', $a->id)->where('action', 'linked')->count());

        // the same link from the other side is the same link: 200, nothing new
        $this->postJson("/api/v1/items/{$b->id}/links", ['target_id' => $a->id, 'type' => 'is_blocked_by'], $this->headers())
            ->assertOk()->assertJsonPath('data.id', $created->json('data.id'))->assertJsonPath('data.direction', 'incoming');
        $this->assertSame(1, ItemLink::count());

        $this->getJson("/api/v1/items/{$b->id}/links", $this->headers($this->viewer))->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'is_blocked_by')
            ->assertJsonPath('data.0.label', 'is blocked by')
            ->assertJsonPath('data.0.issue.id', $a->id);

        // removed from the other side → gone for both
        $this->deleteJson("/api/v1/items/{$b->id}/links/{$created->json('data.id')}", [], $this->headers())->assertNoContent();
        $this->getJson("/api/v1/items/{$a->id}/links", $this->headers())->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_link_rules_rights_and_restricted_issues(): void
    {
        $a = $this->item();
        $foreignProject = Project::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);
        $hidden = Item::factory()->create(['board_id' => Board::factory()->create(['project_id' => $foreignProject->id])->id, 'title' => 'Secret']);

        $this->postJson("/api/v1/items/{$a->id}/links", ['target_id' => $this->item()->id, 'type' => 'relates_to'], $this->headers($this->viewer))->assertForbidden();
        $this->postJson("/api/v1/items/{$a->id}/links", ['target_id' => $hidden->id, 'type' => 'blocks'], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('target_id');
        $this->postJson("/api/v1/items/{$a->id}/links", ['target_id' => $a->id, 'type' => 'blocks'], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('target_id');
        $this->postJson("/api/v1/items/{$a->id}/links", ['target_id' => $a->id, 'type' => 'loves'], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('type');

        // a link to an issue the user can't open: listed, restricted, nothing about the other issue
        $link = ItemLink::create(['source_item_id' => $a->id, 'target_item_id' => $hidden->id, 'link_type' => 'blocks', 'created_by' => $this->editor->id]);
        $this->getJson("/api/v1/items/{$a->id}/links", $this->headers())->assertOk()
            ->assertJsonPath('data.0.restricted', true)
            ->assertJsonPath('data.0.issue', null)
            ->assertDontSee('Secret');

        $this->deleteJson("/api/v1/items/{$a->id}/links/{$link->id}", [], $this->headers($this->viewer))->assertForbidden();
        $this->deleteJson("/api/v1/items/{$this->item()->id}/links/{$link->id}", [], $this->headers())->assertNotFound(); // not a link of that issue
        $this->deleteJson("/api/v1/items/{$a->id}/links/abc", [], $this->headers())->assertNotFound();
        $this->getJson("/api/v1/items/{$hidden->id}/links", $this->headers())->assertNotFound();
        $this->getJson("/api/v1/items/{$hidden->id}/children", $this->headers())->assertNotFound();
        $this->postJson("/api/v1/items/{$hidden->id}/links", [], $this->headers())->assertNotFound();
        $this->getJson("/api/v1/items/{$a->id}/links", $this->appHeaders($this->passportToken($this->editor)))->assertUnauthorized();
    }
}
