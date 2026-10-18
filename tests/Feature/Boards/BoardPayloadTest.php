<?php

namespace Tests\Feature\Boards;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BoardPayloadTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        Notification::fake();
    }

    public function test_board_cards_carry_counts_instead_of_comment_and_attachment_bodies(): void
    {
        $item = $this->item();
        $item->comments()->create(['user_id' => $this->editor->id, 'body' => 'First']);
        $item->comments()->create(['user_id' => $this->editor->id, 'body' => 'Second']);
        $this->board->columns()->where('slug', 'in-progress')->update(['wip_limit' => 3]);

        $this->actingAs($this->viewer)
            ->get(route('boards.show', [$this->workspace->slug, $this->project->slug, $this->board->slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Boards/Show')
                ->where('board.columns.0.items.0.comments_count', 2)
                ->where('board.columns.0.items.0.attachments_count', 0)
                ->missing('board.columns.0.items.0.comments')
                ->missing('board.columns.0.items.0.attachments')
                ->where('board.columns.1.wip_limit', 3));
    }

    public function test_item_details_return_comments_attachments_and_history(): void
    {
        $item = $this->item(['original_estimate' => 120]);
        $this->actingAs($this->editor)->post($this->itemRoute('boards.items.comments.store', $item), ['body' => 'Hello']);
        $this->actingAs($this->editor)->post($this->itemRoute('boards.items.work-logs.store', $item), ['time' => '30m']);

        $this->actingAs($this->viewer)
            ->getJson($this->itemRoute('boards.items.details', $item))
            ->assertOk()
            ->assertJsonCount(1, 'comments')
            ->assertJsonPath('comments.0.body', 'Hello')
            ->assertJsonPath('comments.0.user.name', $this->editor->name)
            ->assertJsonCount(0, 'attachments')
            ->assertJsonPath('history.0.action', 'work_logged')
            ->assertJsonPath('history.0.user.name', $this->editor->name)
            ->assertJsonPath('history.2.action', 'created')
            ->assertJsonPath('time_spent', 30)
            ->assertJsonPath('original_estimate', 120);
    }

    public function test_item_details_require_project_access(): void
    {
        $item = $this->item();

        $this->actingAs($this->outsider)
            ->getJson($this->itemRoute('boards.items.details', $item))
            ->assertForbidden();
    }

    public function test_full_item_page_keeps_working_with_slim_columns(): void
    {
        $item = $this->item();
        $item->comments()->create(['user_id' => $this->editor->id, 'body' => 'On the item']);

        $this->actingAs($this->viewer)
            ->get($this->itemRoute('boards.items.show', $item))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Items/Show')
                ->where('item.comments.0.body', 'On the item')
                ->where('columns.0.items.0.comments_count', 1)
                ->missing('columns.0.items.0.comments'));
    }
}
