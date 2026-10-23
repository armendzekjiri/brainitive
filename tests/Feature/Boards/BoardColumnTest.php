<?php

namespace Tests\Feature\Boards;

use App\Models\Board;
use App\Models\ItemPriority;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BoardColumnTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
    }

    private function columnsRoute(string $name, array $extra = [], ?Board $board = null): string
    {
        return route($name, [$this->workspace->slug, $this->project->slug, ($board ?? $this->board)->slug, ...$extra]);
    }

    public function test_same_or_symbol_only_names_get_unique_slugs(): void
    {
        foreach (['Review', 'Review', '🚀', '🚀'] as $name) {
            $this->actingAs($this->editor)->post($this->columnsRoute('boards.columns.store'), ['name' => $name])->assertRedirect()->assertSessionHasNoErrors();
        }

        $slugs = $this->board->columns()->orderBy('position')->pluck('slug')->all();
        $this->assertSame(['review', 'review-2', 'column', 'column-2'], array_slice($slugs, -4));
    }

    public function test_columns_of_another_board_cannot_be_edited_or_deleted(): void
    {
        $other = Board::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id]);
        $foreign = $other->columns()->first();

        $this->actingAs($this->editor)->put($this->columnsRoute('boards.columns.update', [$foreign->id]), ['name' => 'Hacked'])->assertNotFound();
        $this->actingAs($this->editor)->delete($this->columnsRoute('boards.columns.destroy', [$foreign->id]))->assertNotFound();
        $this->assertNotSame('Hacked', $foreign->fresh()->name);

        // Through its own board it works, and non-empty columns stay.
        $this->item(['column_id' => $own = $this->board->columns()->first()->id]);
        $this->actingAs($this->editor)->put($this->columnsRoute('boards.columns.update', [$own]), ['name' => 'Backlog'])->assertRedirect();
        $this->actingAs($this->editor)->delete($this->columnsRoute('boards.columns.destroy', [$own]))->assertSessionHasErrors('column');
        $this->assertSame('Backlog', $this->board->columns()->find($own)->name);
    }

    public function test_default_priorities_exist_after_migrating(): void
    {
        $this->assertSame(['highest', 'high', 'medium', 'low', 'lowest'], ItemPriority::whereNull('project_id')->orderBy('order')->pluck('slug')->all());
        $this->assertSame('medium', ItemPriority::where('is_default', true)->value('slug'));
    }
}
