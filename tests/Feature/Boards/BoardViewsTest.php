<?php

namespace Tests\Feature\Boards;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Board;
use App\Models\BoardView;
use App\Models\ItemField;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Saved board views (BRAI-78): save / open round trip, privacy, shared-view permissions,
 * removed fields / members / columns, defaults.
 */
class BoardViewsTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
    }

    public function test_saving_and_opening_a_view_round_trips_the_board_params(): void
    {
        $column = $this->board->columns()->orderBy('position')->value('id');

        $this->actingAs($this->editor)->post($this->route('boards.views.store'), [
            'name' => 'My open bugs',
            'params' => ['status' => (string) $column, 'assignee' => 'me', 'quick' => 'due,bogus', 'view' => 'list', 'sort' => '-due_date', 'evil' => '<script>', 'q' => '  login  '],
        ])->assertRedirect();

        $view = BoardView::firstOrFail();
        $this->assertSame(['assignee' => 'me', 'q' => 'login', 'quick' => 'due', 'sort' => '-due_date', 'status' => (string) $column, 'view' => 'list'], $view->params());
        $this->assertFalse($view->is_shared);

        $this->actingAs($this->editor)->get($this->viewRoute('boards.views.open', $view))
            ->assertRedirect(route('boards.show', [$this->workspace->slug, $this->project->slug, $this->board->slug, ...$view->params(), 'bv' => $view->id]));

        $this->actingAs($this->editor)->get(route('boards.show', [$this->workspace->slug, $this->project->slug, $this->board->slug, 'bv' => $view->id]))
            ->assertInertia(fn (Assert $page) => $page->where('activeViewId', $view->id)->where('savedViews.0.name', 'My open bugs')->where('savedViews.0.params.assignee', 'me'));
    }

    public function test_personal_views_stay_private_and_shared_views_are_for_the_board_audience(): void
    {
        $personal = $this->savedView($this->editor, 'Mine');
        $shared = $this->savedView($this->editor, 'Team', shared: true);

        $this->actingAs($this->viewer)->get($this->viewRoute('boards.views.open', $personal))->assertForbidden();
        $this->actingAs($this->viewer)->get($this->viewRoute('boards.views.open', $shared))->assertRedirect();
        $this->actingAs($this->viewer)->get(route('boards.show', [$this->workspace->slug, $this->project->slug, $this->board->slug, 'bv' => 0]))
            ->assertInertia(fn (Assert $page) => $page->has('savedViews', 1)->where('savedViews.0.name', 'Team')->where('savedViews.0.can_edit', false));

        // sharing never grants access to the board
        $this->actingAs($this->outsider)->get($this->viewRoute('boards.views.open', $shared))->assertForbidden();
    }

    public function test_only_board_managers_create_and_change_shared_views(): void
    {
        $this->actingAs($this->viewer)->post($this->route('boards.views.store'), ['name' => 'Team', 'params' => [], 'is_shared' => true])->assertForbidden();
        $this->actingAs($this->viewer)->post($this->route('boards.views.store'), ['name' => 'Just me', 'params' => []])->assertRedirect();

        $shared = $this->savedView($this->editor, 'Team', shared: true);
        $this->actingAs($this->viewer)->patch($this->viewRoute('boards.views.update', $shared), ['name' => 'Hacked'])->assertForbidden();
        $this->actingAs($this->viewer)->delete($this->viewRoute('boards.views.destroy', $shared))->assertForbidden();
        $this->assertSame('Team', $shared->fresh()->name);

        // a viewer can copy it into a personal view
        $this->actingAs($this->viewer)->post($this->viewRoute('boards.views.duplicate', $shared))->assertRedirect();
        $this->assertDatabaseHas('board_views', ['name' => 'Team (copy)', 'user_id' => $this->viewer->id, 'is_shared' => false]);

        // a viewer's own view can't be pushed to the team either
        $own = BoardView::where('user_id', $this->viewer->id)->where('name', 'Just me')->firstOrFail();
        $this->actingAs($this->viewer)->patch($this->viewRoute('boards.views.update', $own), ['is_shared' => true])->assertForbidden();
        $this->actingAs($this->viewer)->patch($this->viewRoute('boards.views.update', $own), ['name' => 'Renamed', 'params' => ['quick' => 'blocked']])->assertSessionHasNoErrors();
        $this->assertSame(['quick' => 'blocked'], $own->fresh()->params());
    }

    public function test_assigned_to_me_resolves_for_each_viewer(): void
    {
        $shared = $this->savedView($this->editor, 'My work', shared: true, params: ['assignee' => 'me']);

        $this->actingAs($this->viewer)->get($this->viewRoute('boards.views.open', $shared))
            ->assertRedirect(route('boards.show', [$this->workspace->slug, $this->project->slug, $this->board->slug, 'assignee' => 'me', 'bv' => $shared->id]));
    }

    public function test_removed_columns_fields_and_members_are_dropped_with_a_note(): void
    {
        $columns = $this->board->columns()->orderBy('position')->pluck('id');
        $field = ItemField::create(['project_id' => $this->project->id, 'name' => 'Team', 'type' => 'text', 'position' => 1, 'is_active' => true]);
        $leaver = User::factory()->create();
        $view = $this->savedView($this->editor, 'Old', params: [
            'status' => $columns[0].',999999',
            'assignee' => 'user-'.$leaver->id,
            'f'.$field->id => '~core',
            'f999999' => 'yes',
        ]);

        $response = $this->actingAs($this->editor)->get($this->viewRoute('boards.views.open', $view));
        $response->assertRedirect(route('boards.show', [$this->workspace->slug, $this->project->slug, $this->board->slug, 'f'.$field->id => '~core', 'status' => (string) $columns[0], 'bv' => $view->id]));
        $response->assertSessionHas('warning', fn ($w) => str_contains($w, 'columns') && str_contains($w, 'member') && str_contains($w, 'field'));
    }

    public function test_a_default_view_applies_to_the_plain_board_and_goes_away_with_the_view(): void
    {
        $view = $this->savedView($this->editor, 'Default', params: ['quick' => 'due']);
        $board = route('boards.show', [$this->workspace->slug, $this->project->slug, $this->board->slug]);

        $this->actingAs($this->editor)->post($this->route('boards.views.default'), ['board_view_id' => $view->id])->assertSessionHasNoErrors();

        // applied in place (no redirect: Inertia visits would lose #item-N links)
        $this->actingAs($this->editor)->get($board)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('activeViewId', $view->id)->where('defaultViewId', $view->id)->where('appliedViewParams.quick', 'due'));
        $inertia = ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request())];
        $this->actingAs($this->editor)->get($board, $inertia)->assertOk()->assertJsonPath('props.activeViewId', $view->id);
        $this->actingAs($this->editor)->get($board.'?bv=0')
            ->assertInertia(fn (Assert $page) => $page->where('activeViewId', null)->where('appliedViewParams', null));
        $this->actingAs($this->editor)->get($board.'?quick=blocked')
            ->assertInertia(fn (Assert $page) => $page->where('activeViewId', null)->where('appliedViewParams', null));

        // someone else's personal view can't become my default
        $other = $this->savedView($this->viewer, 'Theirs');
        $this->actingAs($this->editor)->post($this->route('boards.views.default'), ['board_view_id' => $other->id])->assertForbidden();

        $this->actingAs($this->editor)->delete($this->viewRoute('boards.views.destroy', $view))->assertRedirect();
        $this->assertSame(0, DB::table('board_view_defaults')->count());
        $this->actingAs($this->editor)->get($board)
            ->assertInertia(fn (Assert $page) => $page->where('activeViewId', null)->where('appliedViewParams', null));
    }

    public function test_an_applied_default_with_removed_parts_is_cleaned_up_with_a_note(): void
    {
        $columns = $this->board->columns()->orderBy('position')->pluck('id');
        $view = $this->savedView($this->editor, 'Old', params: ['status' => $columns[0].',999999']);
        DB::table('board_view_defaults')->insert(['user_id' => $this->editor->id, 'board_id' => $this->board->id, 'board_view_id' => $view->id, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->editor)->get(route('boards.show', [$this->workspace->slug, $this->project->slug, $this->board->slug]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('appliedViewParams.status', (string) $columns[0])
                // the open view is compared against what the board shows, not the stored params
                ->where('savedViews.0.params.status', (string) $columns[0])
                ->where('flash.warning', fn ($w) => str_contains((string) $w, 'columns')));
    }

    public function test_board_load_does_not_query_per_saved_view(): void
    {
        $board = route('boards.show', [$this->workspace->slug, $this->project->slug, $this->board->slug, 'bv' => 0]);
        $this->savedView($this->editor, 'Mine');
        $this->savedView($this->editor, 'Team', shared: true);
        $this->actingAs($this->editor)->get($board)->assertOk(); // warm up

        $queries = fn () => tap(0, function () use ($board, &$count) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->editor)->get($board)->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();
        }) + $count;

        $before = $queries();
        foreach (range(1, 8) as $i) {
            $this->savedView($this->editor, "Mine {$i}");
            $this->savedView($this->editor, "Team {$i}", shared: true);
        }
        $this->assertSame($before, $queries());
    }

    public function test_views_of_another_board_are_not_found(): void
    {
        $otherBoard = Board::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id]);
        $foreign = BoardView::create(['board_id' => $otherBoard->id, 'user_id' => $this->editor->id, 'name' => 'Elsewhere', 'is_shared' => true, 'config' => ['version' => 1, 'params' => []]]);

        $this->actingAs($this->editor)->get($this->viewRoute('boards.views.open', $foreign))->assertNotFound();
        $this->actingAs($this->editor)->patch($this->viewRoute('boards.views.update', $foreign), ['name' => 'Moved'])->assertNotFound();
        $this->actingAs($this->editor)->post($this->viewRoute('boards.views.duplicate', $foreign))->assertNotFound();
        $this->actingAs($this->editor)->delete($this->viewRoute('boards.views.destroy', $foreign))->assertNotFound();
        $this->actingAs($this->editor)->post($this->route('boards.views.default'), ['board_view_id' => $foreign->id])->assertNotFound();
        $this->assertSame('Elsewhere', $foreign->fresh()->name);
    }

    public function test_personal_views_are_off_limits_even_for_board_managers(): void
    {
        $personal = $this->savedView($this->viewer, 'Private');

        $this->actingAs($this->editor)->get($this->viewRoute('boards.views.open', $personal))->assertForbidden();
        $this->actingAs($this->editor)->patch($this->viewRoute('boards.views.update', $personal), ['name' => 'Mine now'])->assertForbidden();
        $this->actingAs($this->editor)->delete($this->viewRoute('boards.views.destroy', $personal))->assertForbidden();
        $this->actingAs($this->editor)->post($this->viewRoute('boards.views.duplicate', $personal))->assertForbidden();
        $this->assertSame('Private', $personal->fresh()->name);
    }

    public function test_outsiders_cannot_save_views_or_pick_defaults(): void
    {
        $shared = $this->savedView($this->editor, 'Team', shared: true);

        $this->actingAs($this->outsider)->post($this->route('boards.views.store'), ['name' => 'Spy', 'params' => []])->assertForbidden();
        $this->actingAs($this->outsider)->post($this->route('boards.views.default'), ['board_view_id' => $shared->id])->assertForbidden();
        $this->assertSame(1, BoardView::count());
    }

    public function test_board_managers_rename_and_delete_shared_views_and_viewers_cannot_unshare_them(): void
    {
        $shared = $this->savedView($this->viewer, 'Team', shared: true);

        $this->actingAs($this->viewer)->patch($this->viewRoute('boards.views.update', $shared), ['is_shared' => false])->assertForbidden();
        $this->assertTrue($shared->fresh()->is_shared);

        $this->actingAs($this->editor)->patch($this->viewRoute('boards.views.update', $shared), ['name' => 'Sprint board'])->assertSessionHasNoErrors();
        $this->assertSame('Sprint board', $shared->fresh()->name);
        $this->actingAs($this->editor)->delete($this->viewRoute('boards.views.destroy', $shared))->assertRedirect();
        $this->assertModelMissing($shared);
    }

    public function test_deleting_a_view_that_is_not_open_keeps_the_current_board_url(): void
    {
        $open = $this->savedView($this->editor, 'Open');
        $other = $this->savedView($this->editor, 'Other');
        $current = route('boards.show', [$this->workspace->slug, $this->project->slug, $this->board->slug, 'quick' => 'due', 'bv' => $open->id]);

        $this->actingAs($this->editor)->from($current)->delete($this->viewRoute('boards.views.destroy', $other))->assertRedirect($current);
        $this->actingAs($this->editor)->from($current)->delete($this->viewRoute('boards.views.destroy', $open))
            ->assertRedirect(route('boards.show', [$this->workspace->slug, $this->project->slug, $this->board->slug, 'bv' => 0]));
    }

    public function test_view_input_is_validated(): void
    {
        $store = $this->route('boards.views.store');

        $this->actingAs($this->editor)->post($store, ['name' => str_repeat('x', 81), 'params' => []])->assertSessionHasErrors('name');
        $this->actingAs($this->editor)->post($store, ['name' => 'Bad', 'params' => 'q=1'])->assertSessionHasErrors('params');
        $this->actingAs($this->editor)->post($store, ['name' => 'Huge', 'params' => array_fill_keys(array_map(fn ($i) => "f{$i}", range(1, 61)), 'yes')])->assertSessionHasErrors('params');
        $this->assertSame(0, BoardView::count());
    }

    public function test_unsharing_a_view_drops_it_as_other_peoples_default(): void
    {
        $shared = $this->savedView($this->editor, 'Team', shared: true);
        $this->actingAs($this->viewer)->post($this->route('boards.views.default'), ['board_view_id' => $shared->id])->assertSessionHasNoErrors();

        $this->actingAs($this->editor)->patch($this->viewRoute('boards.views.update', $shared), ['is_shared' => false])->assertSessionHasNoErrors();

        $this->assertSame(0, DB::table('board_view_defaults')->where('user_id', $this->viewer->id)->count());
        $this->actingAs($this->viewer)->get(route('boards.show', [$this->workspace->slug, $this->project->slug, $this->board->slug]))->assertOk();
    }

    private function savedView(User $owner, string $name, bool $shared = false, array $params = []): BoardView
    {
        return BoardView::create([
            'board_id' => $this->board->id,
            'user_id' => $owner->id,
            'name' => $name,
            'is_shared' => $shared,
            'config' => ['version' => 1, 'params' => $params],
        ]);
    }

    private function route(string $name): string
    {
        return route($name, [$this->workspace->slug, $this->project->slug, $this->board->slug]);
    }

    private function viewRoute(string $name, BoardView $view): string
    {
        return route($name, [$this->workspace->slug, $this->project->slug, $this->board->slug, $view->id]);
    }
}
