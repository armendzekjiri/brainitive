<?php

namespace Tests\Feature;

use App\Models\Board;
use App\Models\Page;
use App\Models\Plan;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

class CommandPaletteTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
    }

    private function recent(User $user, array $entries)
    {
        return $this->actingAs($user)->postJson(route('command-palette.recent'), ['entries' => $entries]);
    }

    public function test_recent_entries_resolve_in_order_and_hide_what_is_not_allowed(): void
    {
        $item = $this->item(['title' => 'Fix login']);
        $plan = Plan::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id, 'name' => 'Launch plan']);
        $doc = Page::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id, 'name' => 'Spec', 'type' => 'page', 'status' => 'published']);
        $draft = Page::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id, 'name' => 'Secret draft', 'type' => 'page', 'status' => 'draft']);

        $foreignProject = Project::factory()->create();
        $foreignBoard = Board::factory()->create(['project_id' => $foreignProject->id]);

        $entries = [
            ['type' => 'item', 'id' => $item->id],
            ['type' => 'board', 'id' => $foreignBoard->id],
            ['type' => 'page', 'id' => $draft->id],
            ['type' => 'plan', 'id' => $plan->id],
            ['type' => 'page', 'id' => $doc->id],
            ['type' => 'project', 'id' => $this->project->id],
            ['type' => 'board', 'id' => 999999],
        ];

        $response = $this->recent($this->viewer, $entries)->assertOk();
        $this->assertSame(
            [['item', $item->id], ['plan', $plan->id], ['page', $doc->id], ['project', $this->project->id]],
            collect($response->json('entries'))->map(fn ($e) => [$e['type'], $e['id']])->all(),
        );
        $response->assertJsonPath('entries.0.title', 'Fix login')
            ->assertJsonPath('entries.0.url', fn ($url) => str_ends_with($url, '#item-'.$item->id))
            ->assertDontSee('Secret draft')
            ->assertDontSee($foreignProject->name);

        // The draft's author sees it; after leaving the project nothing is left.
        $this->recent($this->editor, $entries)->assertJsonCount(5, 'entries');
        $this->project->users()->detach($this->viewer->id);
        $this->recent($this->viewer, $entries)->assertJsonCount(0, 'entries');
    }

    public function test_recent_input_is_bounded_and_needs_login(): void
    {
        $this->postJson(route('command-palette.recent'), ['entries' => []])->assertUnauthorized();
        $this->recent($this->viewer, array_fill(0, 13, ['type' => 'item', 'id' => 1]))->assertUnprocessable();
        $this->recent($this->viewer, [['type' => 'user', 'id' => 1]])->assertUnprocessable();
    }

    public function test_search_finds_boards_and_plans_and_ranks_the_current_project_first(): void
    {
        $this->board->update(['name' => 'Roadmap board']);
        Plan::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id, 'name' => 'Roadmap plan']);

        $other = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->editor->id]);
        $other->users()->attach($this->editor->id, ['role' => 'editor']);
        $otherBoard = Board::factory()->create(['project_id' => $other->id, 'name' => 'Roadmap other']);

        $res = $this->actingAs($this->editor)->getJson(route('search.quick', ['q' => 'roadmap', 'project' => $other->id]))->assertOk();
        $this->assertSame([$otherBoard->id, $this->board->id], array_column($res->json('boards'), 'id'));
        $res->assertJsonPath('plans.0.title', 'Roadmap plan');

        // Outsiders find nothing.
        $this->actingAs($this->outsider)->getJson(route('search.quick', ['q' => 'roadmap']))->assertJsonCount(0, 'boards')->assertJsonCount(0, 'plans');
    }
}
