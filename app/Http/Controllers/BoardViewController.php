<?php

namespace App\Http\Controllers;

use App\Models\Board;
use App\Models\BoardView;
use App\Models\Project;
use App\Models\Workspace;
use App\Support\Boards\BoardViewConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Saved board views (BRAI-78): save / rename / share / duplicate / delete, open (sanitized
 * against the current board, then redirected to the board with the view's params) and the
 * personal default per board.
 */
class BoardViewController extends Controller
{
    public function store(Request $request, Workspace $workspace, Project $project, Board $board): RedirectResponse
    {
        $this->authorize('view', $board);
        $validated = $request->validate([
            'name' => 'required|string|max:80',
            'params' => 'present|array|max:60',
            'is_shared' => 'sometimes|boolean',
        ]);
        $shared = (bool) ($validated['is_shared'] ?? false);
        if ($shared) {
            $this->authorize('update', $board);
        }

        $view = $board->views()->create([
            'user_id' => $request->user()->id,
            'name' => $validated['name'],
            'is_shared' => $shared,
            'config' => BoardViewConfig::normalize($validated['params']),
            'position' => (int) $board->views()->max('position') + 1,
        ]);

        return redirect()->route('boards.views.open', [$workspace->slug, $project->slug, $board->slug, $view->id])
            ->with('success', "View “{$view->name}” saved.");
    }

    /** Rename, replace the saved params ("Save changes"), or share / unshare. */
    public function update(Request $request, Workspace $workspace, Project $project, Board $board, BoardView $boardView): RedirectResponse
    {
        $this->authorize('update', $boardView);
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:80',
            'params' => 'sometimes|array|max:60',
            'is_shared' => 'sometimes|boolean',
        ]);

        if (array_key_exists('is_shared', $validated) && (bool) $validated['is_shared'] !== $boardView->is_shared) {
            // sharing / unsharing changes what the team sees: board managers only
            $this->authorize('update', $board);
            // a view taken back from the team becomes the personal view of whoever does it
            if (! $validated['is_shared']) {
                $boardView->user_id = $request->user()->id;
            }
        }

        $changes = [];
        if (isset($validated['name'])) {
            $changes['name'] = $validated['name'];
        }
        if (array_key_exists('params', $validated)) {
            $changes['config'] = BoardViewConfig::normalize($validated['params']);
        }
        if (array_key_exists('is_shared', $validated)) {
            $changes['is_shared'] = (bool) $validated['is_shared'];
        }
        $boardView->fill($changes)->save();

        if (! $boardView->is_shared) {
            // a view that is now personal can't stay someone else's default
            DB::table('board_view_defaults')->where('board_view_id', $boardView->id)->where('user_id', '!=', $boardView->user_id)->delete();
        }

        return back()->with('success', 'View updated.');
    }

    /** A personal copy for the current user (of any view they can see). */
    public function duplicate(Request $request, Workspace $workspace, Project $project, Board $board, BoardView $boardView): RedirectResponse
    {
        $this->authorize('view', $boardView);

        $copy = $board->views()->create([
            'user_id' => $request->user()->id,
            'name' => mb_substr($boardView->name.' (copy)', 0, 80),
            'is_shared' => false,
            'config' => $boardView->config,
            'position' => (int) $board->views()->max('position') + 1,
        ]);

        return redirect()->route('boards.views.open', [$workspace->slug, $project->slug, $board->slug, $copy->id]);
    }

    public function destroy(Workspace $workspace, Project $project, Board $board, BoardView $boardView): RedirectResponse
    {
        $this->authorize('delete', $boardView);
        $boardView->delete(); // its defaults go with it (FK cascade)

        // Deleting the open view leaves the ordinary board; any other keeps the current URL
        parse_str((string) parse_url(url()->previous(), PHP_URL_QUERY), $previous);
        if ((int) ($previous['bv'] ?? 0) !== $boardView->id) {
            return back()->with('success', 'View deleted.');
        }

        return redirect()->route('boards.show', [$workspace->slug, $project->slug, $board->slug, 'bv' => 0])
            ->with('success', 'View deleted.');
    }

    /**
     * Open a view: its params, minus whatever no longer exists on the board (with a note), on the
     * ordinary board URL — so the result is shareable / bookmarkable like any filtered board.
     */
    public function open(Request $request, Workspace $workspace, Project $project, Board $board, BoardView $boardView): RedirectResponse
    {
        $this->authorize('view', $boardView);

        ['params' => $params, 'dropped' => $dropped] = BoardViewConfig::sanitize((array) $boardView->config, $board);
        $redirect = redirect()->route('boards.show', [$workspace->slug, $project->slug, $board->slug, ...$params, 'bv' => $boardView->id]);

        $note = BoardViewConfig::droppedNote($boardView->name, $dropped);

        return $note === null ? $redirect : $redirect->with('warning', $note);
    }

    /** Make a view the current user's default for this board, or clear it (view = null). */
    public function setDefault(Request $request, Workspace $workspace, Project $project, Board $board): RedirectResponse
    {
        $this->authorize('view', $board);
        $validated = $request->validate(['board_view_id' => 'nullable|integer']);
        $user = $request->user();

        if (empty($validated['board_view_id'])) {
            DB::table('board_view_defaults')->where('user_id', $user->id)->where('board_id', $board->id)->delete();

            return back()->with('success', 'Default view cleared.');
        }

        $view = $board->views()->findOrFail($validated['board_view_id']);
        $this->authorize('view', $view);
        DB::table('board_view_defaults')->updateOrInsert(
            ['user_id' => $user->id, 'board_id' => $board->id],
            ['board_view_id' => $view->id, 'updated_at' => now(), 'created_at' => now()],
        );

        return back()->with('success', "“{$view->name}” opens by default on this board.");
    }
}
