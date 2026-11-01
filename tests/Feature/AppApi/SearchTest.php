<?php

namespace Tests\Feature\AppApi;

use App\Models\Board;
use App\Models\Page;
use App\Models\Project;
use App\Models\Workspace;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\Feature\Search\SearchTestCase;

/**
 * App API v1 search (BRAI-392): the shared SearchService — same visibility and ranking as the web,
 * plus the workspace filter, page cursors and places.
 */
class SearchTest extends SearchTestCase
{
    use ActsAsAppClient;
    use ForgetsGuardsBetweenRequests;

    private Workspace $second;

    private Project $secondProject;

    protected function setUp(): void
    {
        parent::setUp();

        // the editor's second workspace, with its own project
        $this->second = Workspace::factory()->create(['name' => 'Second']);
        $this->second->addUser($this->editor, 'admin');
        $this->secondProject = Project::factory()->create(['workspace_id' => $this->second->id, 'key' => 'OPS', 'name' => 'Operations']);
    }

    private function find(string $query, ?string $token = null)
    {
        return $this->getJson('/api/v1/search?'.$query, $this->appHeaders($token ?? $this->appToken($this->editor)));
    }

    public function test_finds_what_the_user_can_open_like_the_web(): void
    {
        $mine = $this->item(['title' => 'Rocket launch checklist']);
        $other = $this->item(['title' => 'Rocket fuel order', 'board_id' => Board::factory()->create(['project_id' => $this->secondProject->id])->id]);
        $hiddenWorkspace = Workspace::factory()->create();
        $hidden = $this->item(['title' => 'Rocket secret', 'board_id' => Board::factory()->create(['project_id' => Project::factory()->create(['workspace_id' => $hiddenWorkspace->id])->id])->id]);
        $this->flush();

        $response = $this->find('q=rocket')->assertOk();

        $ids = array_column($response->json('data'), 'id');
        $this->assertEqualsCanonicalizing(["item:{$mine->id}", "item:{$other->id}"], $ids);
        $this->assertNotContains("item:{$hidden->id}", $ids);
        $this->assertSame(['data', 'next_cursor', 'total', 'counts', 'fuzzy', 'places'], array_keys($response->json()));
        $response->assertJsonPath('total', 2)->assertJsonPath('counts.issues', 2)->assertJsonPath('next_cursor', null)->assertJsonPath('fuzzy', false);

        $hit = collect($response->json('data'))->firstWhere('id', "item:{$mine->id}");
        $this->assertSame('item', $hit['type']);
        $this->assertSame('issues', $hit['group']);
        $this->assertSame('Rocket launch checklist', $hit['title']);
        $this->assertSame($this->project->id, $hit['project']['id']);
        $this->assertStringContainsString('#item-'.$mine->id, $hit['url']);

        // the same results as the web search service
        $this->assertEqualsCanonicalizing($this->ids($this->editor, 'rocket'), $ids);
    }

    public function test_filters_by_workspace_type_and_status(): void
    {
        $mine = $this->item(['title' => 'Rocket launch checklist']);
        $other = $this->item(['title' => 'Rocket fuel order', 'board_id' => Board::factory()->create(['project_id' => $this->secondProject->id])->id]);
        $doc = Page::factory()->published()->create(['project_id' => $this->project->id, 'name' => 'Rocket handbook', 'created_by' => $this->owner->id]);
        $this->flush();

        $this->assertSame(["item:{$other->id}"], array_column($this->find("q=rocket&workspace={$this->second->slug}")->assertOk()->json('data'), 'id'));
        $this->assertEqualsCanonicalizing(["item:{$mine->id}", "page:{$doc->id}"], array_column($this->find("q=rocket&workspace={$this->workspace->slug}")->json('data'), 'id'));
        $this->assertSame(["page:{$doc->id}"], array_column($this->find('q=rocket&type=docs')->json('data'), 'id'));
        $this->assertSame(["item:{$mine->id}"], array_column($this->find("q=rocket&project[]={$this->project->id}&type=issues")->json('data'), 'id'));

        // a moved project's content follows it, although its index entries still name the old workspace
        // (a project member keeps access: the editor is no admin of the target workspace)
        $this->secondProject->addUser($this->editor, 'editor');
        $this->secondProject->update(['workspace_id' => $this->workspace->id]);
        $this->assertContains("item:{$other->id}", array_column($this->find("q=rocket&workspace={$this->workspace->slug}")->json('data'), 'id'));
        $this->assertSame([], $this->find("q=rocket&workspace={$this->second->slug}")->json('data'));
    }

    public function test_pages_with_a_cursor_and_lists_places_on_the_first_unfiltered_page(): void
    {
        foreach (range(1, 3) as $n) {
            $this->item(['title' => "Rocket part {$n}"]);
        }
        Board::factory()->create(['project_id' => $this->project->id, 'name' => 'Rocket board']);
        $this->flush();

        $first = $this->find('q=rocket&limit=2')->assertOk();
        $first->assertJsonCount(2, 'data')->assertJsonPath('next_cursor', '2');
        $this->assertContains('Rocket board', array_column($first->json('places'), 'title'));
        $this->assertSame(['type', 'id', 'title', 'subtitle', 'url', 'workspace'], array_keys($first->json('places.0')));

        $second = $this->find('q=rocket&limit=2&cursor=2')->assertOk();
        $second->assertJsonCount(1, 'data')->assertJsonPath('next_cursor', null)->assertJsonPath('places', []);
        $this->assertEmpty(array_intersect(array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id')));

        $this->assertSame([], $this->find('q=rocket&type=issues')->json('places'));
    }

    public function test_validation_and_access(): void
    {
        $this->find('')->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors('q');
        $this->find('q=x&type=widgets&limit=51&cursor=abc&status=maybe')->assertStatus(422)
            ->assertJsonValidationErrors(['type', 'limit', 'cursor', 'status']);
        // a workspace the user isn't in
        $this->find('q=rocket&workspace='.Workspace::factory()->create()->slug)->assertNotFound()->assertJsonPath('code', 'not_found');
        // one letter finds nothing, never an error
        $this->find('q=r')->assertOk()->assertJsonPath('data', [])->assertJsonPath('places', []);

        $this->getJson('/api/v1/search?q=rocket')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
        $this->getJson('/api/v1/search?q=rocket', $this->appHeaders($this->passportToken($this->editor)))->assertUnauthorized();
        // nor does an app token work on the web / MCP search routes of the extension
        $this->getJson('/api/ext/v1/search?q=rocket', $this->appHeaders($this->appToken($this->editor)))->assertUnauthorized();
    }
}
