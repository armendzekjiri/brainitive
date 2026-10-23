<?php

namespace Tests\Feature\Boards;

use App\Models\ItemPriority;
use App\Models\ItemType;
use App\Models\Label;
use App\Models\Project;
use App\Models\Release;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class SwimlaneTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
    }

    private function save($user, array $config)
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user)->put(route('boards.swimlanes.update', [$this->workspace->slug, $this->project->slug, $this->board->slug]), $config);
    }

    public function test_lanes_are_saved_in_order_and_only_with_this_projects_values(): void
    {
        $high = ItemPriority::where('slug', 'high')->value('id');
        $bug = ItemType::where('slug', 'bug')->value('id');
        $label = Label::create(['project_id' => $this->project->id, 'name' => 'ui', 'slug' => 'ui', 'color' => '#22c55e']);
        $foreignLabel = Label::create(['project_id' => Project::factory()->create()->id, 'name' => 'x', 'slug' => 'x', 'color' => '#000000']);
        $foreignRelease = Release::factory()->create();

        $this->save($this->editor, [
            'mode' => 'custom',
            'rest_name' => 'Rest',
            'lanes' => [
                ['id' => 'urgent', 'name' => ' 🔥 Urgent ', 'color' => '#EF4444', 'rules' => ['priority_ids' => [$high, $high], 'overdue' => true]],
                ['name' => 'Bugs', 'rules' => ['type_ids' => [$bug], 'label_ids' => [$label->id, $foreignLabel->id], 'release_ids' => [0, $foreignRelease->id], 'assignee_ids' => [0, $this->outsider->id]]],
            ],
        ])->assertSessionHasNoErrors();

        $stored = $this->board->fresh()->settings['swimlanes'];
        $this->assertSame('custom', $stored['mode']);
        $this->assertSame('Rest', $stored['rest_name']);
        $this->assertSame(['urgent', '🔥 Urgent', '#ef4444'], [$stored['lanes'][0]['id'], $stored['lanes'][0]['name'], $stored['lanes'][0]['color']]);
        $this->assertSame(['priority_ids' => [$high], 'overdue' => true], $stored['lanes'][0]['rules']);
        $this->assertSame(['type_ids' => [$bug], 'label_ids' => [$label->id], 'assignee_ids' => [0], 'release_ids' => [0]], $stored['lanes'][1]['rules']);
        $this->assertMatchesRegularExpression('/^lane-[a-z0-9]{8}$/', $stored['lanes'][1]['id']);

        // The board page hands the setup to everyone who can see the board.
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->viewer)->get(route('boards.show', [$this->workspace->slug, $this->project->slug, $this->board->slug]))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('board.settings.swimlanes.lanes.1.name', 'Bugs'));
    }

    public function test_only_board_editors_change_lanes_and_input_is_bounded(): void
    {
        $this->save($this->viewer, ['mode' => 'priority', 'lanes' => []])->assertForbidden();
        $this->save($this->editor, ['mode' => 'everything', 'lanes' => []])->assertSessionHasErrors('mode');
        $this->save($this->editor, ['mode' => 'custom', 'lanes' => array_fill(0, 21, ['name' => 'x'])])->assertSessionHasErrors('lanes');
        $this->save($this->editor, ['mode' => 'custom', 'lanes' => [['name' => '']]])->assertSessionHasErrors('lanes.0.name');
        $this->save($this->editor, ['mode' => 'custom', 'lanes' => [['name' => 'x', 'color' => 'red']]])->assertSessionHasErrors('lanes.0.color');

        // Other settings on the board are kept.
        $this->board->update(['settings' => ['something' => 1]]);
        $this->save($this->editor, ['mode' => 'assignee', 'lanes' => []])->assertSessionHasNoErrors();
        $this->assertSame(1, $this->board->fresh()->settings['something']);
    }
}
