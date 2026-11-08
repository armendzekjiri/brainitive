<?php

namespace Tests\Feature\AppApi;

use App\Models\BoardColumn;
use App\Models\Item;
use App\Models\User;
use App\Support\Boards\WorkflowRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/** Board workflow rules (v1.52.0) in the App API: 422 + `workflow_rule` on refused moves / creates, hints per column. */
class WorkflowRulesTest extends TestCase
{
    use ActsAsAppClient;
    use ForgetsGuardsBetweenRequests;
    use InteractsWithBoards;
    use RefreshDatabase;

    private User $max;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        Notification::fake();
        $this->max = $this->projectMember('editor');
    }

    /** @param array<string, mixed> $rule */
    private function rule(string $slug, array $rule): void
    {
        WorkflowRules::save(BoardColumn::findOrFail($this->column($slug)), $rule + [
            'allowed_user_ids' => [], 'allowed_roles' => [], 'allowed_from_column_ids' => [],
            'require_assignee' => false, 'require_subtasks_done' => false, 'require_due_date' => false,
        ]);
    }

    private function columnOf(Item $item): string
    {
        return (string) BoardColumn::whereKey($item->fresh()->column_id)->value('slug');
    }

    public function test_app_api_move_and_create_answer_422_with_the_rule_and_the_board_lists_it(): void
    {
        $this->rule('done', ['allowed_user_ids' => [$this->max->id], 'require_assignee' => true]);
        $item = $this->item(['column_id' => $this->column('in-progress')]);
        $headers = $this->appHeaders($this->appToken($this->editor));

        $this->postJson("/api/v1/items/{$item->id}/move", ['column_id' => $this->column('done')], $headers)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('message', "Only {$this->max->name} can move cards to Done.")
            ->assertJsonPath('errors.column_id.0', "Only {$this->max->name} can move cards to Done.")
            ->assertJsonPath('workflow_rule.reason', 'actor')
            ->assertJsonPath('workflow_rule.column', 'Done')
            ->assertJsonPath('workflow_rule.item_key', $item->key);
        $this->assertSame('in-progress', $this->columnOf($item));

        $this->postJson("/api/v1/boards/{$this->board->id}/items", ['title' => 'x', 'column_id' => $this->column('done')], $headers)
            ->dump()->assertUnprocessable()->assertJsonPath('workflow_rule.reason', 'actor');

        $response = $this->getJson("/api/v1/boards/{$this->board->id}", $headers)->assertOk()
            ->assertJsonPath('data.columns.0.workflow', null)
            ->assertJsonPath('data.columns.2.workflow.can_move_here', false)
            ->assertJsonPath('data.columns.2.workflow.allowed_from_column_ids', [])
            ->assertJsonPath('data.columns.2.workflow.requires', ['assignee' => true, 'subtasks_done' => false, 'due_date' => false]);
        $this->assertSame(["Only {$this->max->name} can move cards here.", 'Requires an assignee.'], $response->json('data.columns.2.workflow.summary'));

        $maxHeaders = $this->appHeaders($this->appToken($this->max));
        $this->getJson("/api/v1/boards/{$this->board->id}", $maxHeaders)->assertJsonPath('data.columns.2.workflow.can_move_here', true);
        $this->postJson("/api/v1/items/{$item->id}/move", ['column_id' => $this->column('done')], $maxHeaders)
            ->assertUnprocessable()->assertJsonPath('workflow_rule.reason', 'assignee');
        $item->update(['assigned_to' => $this->max->id]);
        $this->postJson("/api/v1/items/{$item->id}/move", ['column_id' => $this->column('done')], $maxHeaders)->assertOk();
        $this->assertSame('done', $this->columnOf($item));
    }
}
