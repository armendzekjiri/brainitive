<?php

namespace App\Http\Controllers;

use App\Models\Board;
use App\Models\Page;
use App\Models\Project;
use App\Models\Workspace;
use App\Support\Boards\ProjectIssues;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Whiteboard ↔ board issues: put live issue cards on a whiteboard and turn stickies into issues.
 */
class WhiteboardController extends Controller
{
    /**
     * Issues of this project for the whiteboard: a search (`q`) or a refresh of the cards on the board (`ids`).
     */
    public function issues(Request $request, Workspace $workspace, Project $project, Page $page): JsonResponse
    {
        $this->authorize('view', $page);

        $validated = $request->validate([
            'q' => 'nullable|string|max:255',
            'ids' => 'nullable|array|max:500',
            'ids.*' => 'integer',
        ]);

        $items = ProjectIssues::find($project, $validated['q'] ?? null, $validated['ids'] ?? null);

        return response()->json(['items' => ProjectIssues::summaries($workspace, $project, $items)]);
    }

    /**
     * Create one issue per sticky title on a board (first column, backlog on Scrum boards).
     */
    public function storeIssues(Request $request, Workspace $workspace, Project $project, Page $page): JsonResponse
    {
        $this->authorize('update', $page);

        $validated = $request->validate([
            'board_id' => ['required', 'integer', Rule::exists('boards', 'id')->where('project_id', $project->id)],
            'titles' => 'required|array|min:1|max:50',
            'titles.*' => 'required|string|max:255',
        ]);

        $board = Board::findOrFail($validated['board_id']);
        $this->authorize('createItems', $board);

        try {
            $items = ProjectIssues::create($board, $project, $request->user(), array_map(fn (string $title) => ['title' => $title], $validated['titles']));
        } catch (\DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['items' => ProjectIssues::summaries($workspace, $project, $items)], 201);
    }
}
