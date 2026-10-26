<?php

namespace Tests\Feature\Search;

use App\Models\Board;
use App\Models\Helpdesk;
use App\Models\Item;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Search\SearchIndexer;
use App\Support\Search\SearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Shared setup of the search tests (BRAI-55): a workspace with project WEB (owner = workspace
 * admin, an editor, a viewer), a board and a helpdesk, plus an outsider.
 */
abstract class SearchTestCase extends TestCase
{
    use RefreshDatabase;

    protected Workspace $workspace;

    protected Project $project;

    protected Board $board;

    protected Helpdesk $helpdesk;

    protected User $owner;

    protected User $editor;

    protected User $viewer;

    protected User $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->owner = User::factory()->create(['name' => 'Olga Owner']);
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->owner->id]);
        $this->workspace->addUser($this->owner, 'admin');
        $this->project = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->owner->id, 'key' => 'WEB', 'name' => 'Website']);
        $this->board = Board::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->owner->id, 'name' => 'Main Board']);
        $this->helpdesk = Helpdesk::create(['project_id' => $this->project->id, 'name' => 'Support', 'slug' => 'support', 'created_by' => $this->owner->id]);

        $this->editor = $this->member('editor');
        $this->viewer = $this->member('viewer');
        $this->outsider = User::factory()->create();
    }

    protected function member(string $role, ?Project $project = null): User
    {
        $project ??= $this->project;
        $user = User::factory()->create();
        $project->workspace->addUser($user, 'member');
        $project->users()->attach($user->id, ['role' => $role]);

        return $user;
    }

    protected function item(array $attributes = []): Item
    {
        return Item::factory()->create($attributes + ['board_id' => $this->board->id, 'created_by' => $this->editor->id, 'title' => 'Plain issue']);
    }

    protected function ticket(array $attributes = []): Ticket
    {
        static $n = 0;
        $n++;

        return Ticket::create($attributes + [
            'helpdesk_id' => $this->helpdesk->id,
            'column_id' => $this->helpdesk->columns()->first()->id,
            'key' => 'SUP-'.$n,
            'subject' => 'Ticket '.$n,
        ]);
    }

    /** Write pending index changes, as the end of a request does. */
    protected function flush(): void
    {
        app(SearchIndexer::class)->flush();
    }

    /**
     * Search as $user through the service.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    protected function search(User $user, string $q, array $filters = []): array
    {
        return app(SearchService::class)->search($user, SearchService::queryFrom(['q' => $q] + $filters, $user));
    }

    /** @return list<string> "type:id" of the results */
    protected function ids(User $user, string $q, array $filters = []): array
    {
        return array_column($this->search($user, $q, $filters)['results'], 'id');
    }

    protected function entry(string $type, int $id): ?object
    {
        $this->flush();

        return DB::table('search_entries')->where('type', $type)->where('source_id', $id)->first();
    }
}
