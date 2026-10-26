<?php

namespace Tests\Feature;

use App\Models\Board;
use App\Models\Item;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CalendarDeletedBoardTest extends TestCase
{
    use RefreshDatabase;

    public function test_calendar_skips_items_of_a_deleted_board(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $workspace->addUser($user, 'admin');
        $project = Project::factory()->create(['workspace_id' => $workspace->id, 'created_by' => $user->id]);

        $board = Board::factory()->create(['project_id' => $project->id, 'created_by' => $user->id]);
        $deletedBoard = Board::factory()->create(['project_id' => $project->id, 'created_by' => $user->id]);
        $kept = Item::factory()->create(['board_id' => $board->id, 'created_by' => $user->id, 'due_date' => '2026-11-05']);
        Item::factory()->create(['board_id' => $deletedBoard->id, 'created_by' => $user->id, 'due_date' => '2026-11-05']);
        $deletedBoard->delete();

        $this->actingAs($user)
            ->get(route('calendar.index', ['view' => 'month', 'date' => '2026-11-05']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Calendar/Index')
                ->has('events', 1)
                ->where('events.0.id', $kept->id));
    }
}
