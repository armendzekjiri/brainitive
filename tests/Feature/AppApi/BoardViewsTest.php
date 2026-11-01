<?php

namespace Tests\Feature\AppApi;

use App\Models\Board;
use App\Models\BoardView;
use App\Models\ItemField;
use App\Models\ItemLink;
use App\Models\ItemPriority;
use App\Models\ItemType;
use App\Models\Project;
use App\Models\Release;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Boards\ItemFieldValues;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * App API v1 project config, saved views and board filters (BRAI-407): the board page's filter params (and a
 * saved view's, sent as they are) applied in SQL like the browser applies them (BoardFilters), views as
 * the board shows them (shared + own, sanitized), the config the issue forms offer.
 */
class BoardViewsTest extends TestCase
{
    use ActsAsAppClient;
    use ForgetsGuardsBetweenRequests;
    use InteractsWithBoards;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
    }

    private function headers(?User $user = null): array
    {
        return $this->appHeaders($this->appToken($user ?? $this->editor));
    }

    /** @return list<int> */
    private function ids(string $query, ?User $user = null): array
    {
        return array_column($this->getJson("/api/v1/boards/{$this->board->id}/items?{$query}", $this->headers($user))->assertOk()->json('data'), 'id');
    }

    public function test_the_project_config(): void
    {
        $label = $this->project->labels()->create(['name' => 'backend', 'slug' => 'backend', 'color' => '#111111']);
        $field = ItemField::create(['project_id' => $this->project->id, 'name' => 'Tier', 'type' => 'select', 'position' => 1, 'board_ids' => [$this->board->id]]);
        $gold = $field->options()->create(['label' => 'Gold', 'position' => 0]);
        Release::create(['project_id' => $this->project->id, 'name' => '1.0', 'status' => Release::STATUS_UNRELEASED]);
        Release::create(['project_id' => $this->project->id, 'name' => '0.1', 'status' => Release::STATUS_ARCHIVED]);

        $response = $this->getJson("/api/v1/projects/{$this->project->id}/config", $this->headers($this->viewer))->assertOk();
        $this->assertSame(['project_id', 'types', 'priorities', 'labels', 'fields', 'releases', 'can'], array_keys($response->json('data')));
        $this->assertEqualsCanonicalizing(ItemType::forProject($this->project)->pluck('id')->all(), array_column($response->json('data.types'), 'id'));
        $this->assertSame(ItemPriority::forProject($this->project)->orderBy('order')->pluck('id')->all(), array_column($response->json('data.priorities'), 'id'));
        $response->assertJsonPath('data.labels.0.id', $label->id)
            ->assertJsonPath('data.fields.0.board_ids', [$this->board->id])
            ->assertJsonPath('data.fields.0.options.0.id', $gold->id)
            ->assertJsonPath('data.fields.0.options.0.label', 'Gold')
            ->assertJsonCount(1, 'data.releases')
            ->assertJsonPath('data.releases.0.name', '1.0')
            ->assertJsonPath('data.can.manage', false);
        $this->getJson("/api/v1/projects/{$this->project->id}/config", $this->headers(User::findOrFail($this->workspace->owner_id)))->assertJsonPath('data.can.manage', true);

        $foreign = Project::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);
        $this->getJson("/api/v1/projects/{$foreign->id}/config", $this->headers())->assertNotFound();
    }

    public function test_saved_views_shared_and_own_sanitized(): void
    {
        $label = $this->project->labels()->create(['name' => 'ui', 'slug' => 'ui', 'color' => '#222222']);
        $shared = BoardView::create(['board_id' => $this->board->id, 'user_id' => $this->viewer->id, 'name' => 'Team UI', 'is_shared' => true, 'position' => 1,
            'config' => ['version' => 1, 'params' => ['labels' => "{$label->id},999999", 'view' => 'list', 'assignee' => 'me']]]);
        $own = BoardView::create(['board_id' => $this->board->id, 'user_id' => $this->editor->id, 'name' => 'Mine', 'is_shared' => false, 'position' => 2,
            'config' => ['version' => 1, 'params' => ['quick' => 'due']]]);
        BoardView::create(['board_id' => $this->board->id, 'user_id' => $this->viewer->id, 'name' => 'Private of someone else', 'is_shared' => false, 'position' => 3, 'config' => []]);
        DB::table('board_view_defaults')->insert(['user_id' => $this->editor->id, 'board_id' => $this->board->id, 'board_view_id' => $own->id, 'created_at' => now(), 'updated_at' => now()]);

        $response = $this->getJson("/api/v1/boards/{$this->board->id}/views", $this->headers())->assertOk();
        $this->assertSame([$shared->id, $own->id], array_column($response->json('data'), 'id'));
        $response->assertJsonPath('data.0.params', ['assignee' => 'me', 'labels' => (string) $label->id, 'view' => 'list'])
            ->assertJsonPath('data.0.layout', 'list')
            ->assertJsonPath('data.0.dropped', ['labels'])
            ->assertJsonPath('data.0.is_own', false)
            ->assertJsonPath('data.0.owner', $this->viewer->name)
            ->assertJsonPath('data.1.is_default', true)
            ->assertJsonPath('data.1.layout', 'kanban');

        $foreign = Board::factory()->create(['project_id' => Project::factory()->create(['workspace_id' => Workspace::factory()->create()->id])->id]);
        $this->getJson("/api/v1/boards/{$foreign->id}/views", $this->headers())->assertNotFound();
    }

    public function test_filters_like_the_board_page(): void
    {
        $ui = $this->project->labels()->create(['name' => 'ui', 'slug' => 'ui', 'color' => '#222222']);
        $high = ItemPriority::forProject($this->project)->orderBy('order')->first();
        $mine = $this->item(['title' => 'Fix the login button', 'assigned_to' => $this->editor->id, 'priority_id' => $high->id, 'due_date' => now()->addDay()->toDateString()]);
        $mine->labels()->attach($ui->id);
        $theirs = $this->item(['title' => 'Write docs', 'assigned_to' => $this->viewer->id, 'column_id' => $this->column('in-progress')]);
        $nobody = $this->item(['title' => 'Login page copy', 'due_date' => now()->addDays(10)->toDateString()]);
        $this->travel(-10)->days();
        $old = $this->item(['title' => 'Ancient']);
        $this->travelBack();
        ItemLink::create(['source_item_id' => $theirs->id, 'target_item_id' => $nobody->id, 'link_type' => 'blocks', 'created_by' => $this->editor->id]);

        $this->assertEqualsCanonicalizing([$mine->id, $nobody->id], $this->ids('q=login'));
        $this->assertSame([$mine->id], $this->ids("q={$mine->key}"));
        $this->assertSame([$mine->id], $this->ids('assignee=me'));
        $this->assertSame([$theirs->id], $this->ids("assignee=user-{$this->viewer->id}"));
        $this->assertEqualsCanonicalizing([$nobody->id, $old->id], $this->ids('assignee=unassigned'));
        $this->assertSame([$theirs->id], $this->ids('status='.$this->column('in-progress')));
        $this->assertSame([$mine->id], $this->ids("labels={$ui->id}"));
        $this->assertSame([$mine->id], $this->ids("priority={$high->id}&assignee=me"));
        $this->assertSame([$mine->id], $this->ids('quick=due'));
        $this->assertSame([$nobody->id], $this->ids('quick=blocked')); // B is blocked by an incoming "blocks"
        $this->assertNotContains($old->id, $this->ids('quick=recent'));

        $this->getJson("/api/v1/boards/{$this->board->id}/items?assignee=someone", $this->headers())->assertUnprocessable()->assertJsonValidationErrors('assignee');
        $this->getJson("/api/v1/boards/{$this->board->id}/items?quick=soon", $this->headers())->assertUnprocessable()->assertJsonValidationErrors('quick');
    }

    public function test_custom_field_filters_and_a_saved_views_params(): void
    {
        $tier = ItemField::create(['project_id' => $this->project->id, 'name' => 'Tier', 'type' => 'select', 'position' => 1]);
        $gold = $tier->options()->create(['label' => 'Gold', 'position' => 0]);
        $silver = $tier->options()->create(['label' => 'Silver', 'position' => 1]);
        $effort = ItemField::create(['project_id' => $this->project->id, 'name' => 'Effort', 'type' => 'number', 'position' => 2]);
        $note = ItemField::create(['project_id' => $this->project->id, 'name' => 'Note', 'type' => 'text', 'position' => 3]);
        $ok = ItemField::create(['project_id' => $this->project->id, 'name' => 'OK', 'type' => 'checkbox', 'position' => 4]);

        $a = $this->item();
        ItemFieldValues::apply($a, [$tier->id => $gold->id, $effort->id => 3, $note->id => 'Needs Design review', $ok->id => true]);
        $b = $this->item();
        ItemFieldValues::apply($b, [$tier->id => $silver->id, $effort->id => 8]);
        $c = $this->item();

        $this->assertSame([$a->id], $this->ids("f{$tier->id}={$gold->id}"));
        $this->assertEqualsCanonicalizing([$a->id, $c->id], $this->ids("f{$tier->id}={$gold->id},none"));
        $this->assertSame([$b->id], $this->ids("f{$effort->id}=5.."));
        $this->assertSame([$a->id], $this->ids("f{$effort->id}=..3"));
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $this->ids("f{$effort->id}=any"));
        $this->assertSame([$c->id], $this->ids("f{$effort->id}=none"));
        $this->assertSame([$a->id], $this->ids("f{$note->id}=~design"));
        $this->assertSame([$a->id], $this->ids("f{$ok->id}=yes"));
        // a value that doesn't fit the field filters nothing, as on the board
        $this->assertCount(3, $this->ids("f{$effort->id}=lots"));

        // a saved view's params, sent as they are
        $view = BoardView::create(['board_id' => $this->board->id, 'user_id' => $this->editor->id, 'name' => 'Big', 'is_shared' => false, 'position' => 1,
            'config' => ['version' => 1, 'params' => ["f{$effort->id}" => '5..', 'view' => 'list', 'sort' => '-key']]]);
        $params = $this->getJson("/api/v1/boards/{$this->board->id}/views", $this->headers())->json('data.0.params');
        $this->assertSame($view->id, $this->getJson("/api/v1/boards/{$this->board->id}/views", $this->headers())->json('data.0.id'));
        $this->assertSame([$b->id], $this->ids(http_build_query($params)));
    }
}
