<?php

namespace App\Http\Controllers;

use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Project;
use App\Models\Workspace;
use App\Support\Boards\WorkflowRules;
use Illuminate\Http\Request;

/**
 * Board settings → Workflow rules (v1.52.0): one rule per target column — who may move cards in, from which
 * columns, conditions. Project admins and workspace admins only (ProjectPolicy::update) — the editors a rule restricts
 * must not be able to lift it. Enforced by Support\Boards\WorkflowGuard; changes are logged on the board.
 */
class BoardWorkflowRuleController extends Controller
{
    public function update(Request $request, Workspace $workspace, Project $project, Board $board, BoardColumn $column)
    {
        $this->authorize('update', $project);
        abort_unless($column->board_id === $board->id, 404);

        $validated = $request->validate(WorkflowRules::rules($board));
        $rule = WorkflowRules::save($column, $validated);

        return back()->with('success', $rule ? "Workflow rule for {$column->name} saved." : "{$column->name} has no workflow rule now.");
    }

    public function destroy(Workspace $workspace, Project $project, Board $board, BoardColumn $column)
    {
        $this->authorize('update', $project);
        abort_unless($column->board_id === $board->id, 404);

        WorkflowRules::delete($column);

        return back()->with('success', "Workflow rule for {$column->name} removed.");
    }
}
