<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Models\Project;
use App\Models\Workspace;
use App\Support\Collab\CollabConfig;
use App\Support\Collab\StateVector;
use App\Support\Collab\WhiteboardCollab;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Live whiteboards from the browser (BRAI-153): connection session (token), storing the live scene as
 * the whiteboard content, and the one-time load of the stored scene into an empty Y document. The
 * realtime server talks to CollabInternalController. Plan: docs/ai/tasks/feat-live-whiteboards.md.
 */
class WhiteboardCollabController extends Controller
{
    /** Server URL, document name and a short-lived token; `view=1` = read-only (the page preview). */
    public function session(Request $request, Workspace $workspace, Project $project, Page $page): JsonResponse
    {
        $this->authorize('view', $page);
        abort_unless(CollabConfig::enabled($request), 404);

        $user = $request->user();
        $access = WhiteboardCollab::access($page, $user);
        abort_if($access === null, 403, 'Live drawing is not available for this page.');

        return response()->json(WhiteboardCollab::session($page, $user, $access, $request->boolean('view')) + [
            'user' => ['id' => $user->id, 'name' => $user->name],
        ]);
    }

    /** The scene of the live document, stored as the whiteboard content (POST: may carry the preview PNG). */
    public function save(Request $request, Workspace $workspace, Project $project, Page $page): JsonResponse
    {
        $this->authorize('update', $page);
        $this->abortUnlessEditable($request, $page);

        $validated = $request->validate([
            'epoch' => 'required|integer|min:0',
            'scene' => 'required|string|max:52428800',
            'vector' => 'required|string|max:200000',
            'baseline' => 'boolean',
            'checkpoint' => 'boolean',
            'image' => 'nullable|image|mimes:png|max:10240',
        ]);

        $scene = $request->boolean('baseline') ? null : WhiteboardCollab::normalizeScene(json_decode($validated['scene'], true));
        $vector = StateVector::normalize(json_decode($validated['vector'], true));
        if ((! $request->boolean('baseline') && $scene === null) || $vector === null) {
            return response()->json(['message' => 'Invalid whiteboard state.'], 422);
        }

        // "Save & exit" sends a preview, like the single-user editor
        $stored = $request->hasFile('image') ? $request->file('image')->store('excalidraw-previews', 'public') : null;
        $preview = is_string($stored) ? asset('storage/'.$stored) : null;

        $result = WhiteboardCollab::save(
            $page,
            $request->user(),
            (int) $validated['epoch'],
            $scene ?? ['elements' => [], 'appState' => [], 'files' => [], 'libraryItems' => null],
            $vector,
            $request->boolean('baseline'),
            $request->boolean('checkpoint'),
            $preview,
        );

        if ($result['status'] === 'stale') {
            if ($preview !== null) {
                Storage::disk('public')->delete($stored); // nothing points to it
            }

            return response()->json(['message' => 'This whiteboard was replaced while you were drawing.', 'reset' => true, 'epoch' => $result['epoch']], 409);
        }

        return response()->json([
            'status' => $result['status'],
            'edit_revision' => $result['page']->edit_revision,
            'version' => $result['version']?->version,
        ]);
    }

    /** First sync of an empty live whiteboard: one editor gets the stored elements to load into it. */
    public function bootstrap(Request $request, Workspace $workspace, Project $project, Page $page): JsonResponse
    {
        $this->authorize('update', $page);
        $this->abortUnlessEditable($request, $page);

        $validated = $request->validate([
            'epoch' => 'required|integer|min:0',
            'claim' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{16,64}$/'],
        ]);

        $result = WhiteboardCollab::claimBootstrap($page, (int) $validated['epoch'], $validated['claim']);

        return response()->json($result + ['reset' => $result['status'] === 'stale'], $result['status'] === 'stale' ? 409 : 200);
    }

    private function abortUnlessEditable(Request $request, Page $page): void
    {
        abort_unless(CollabConfig::enabled($request) && WhiteboardCollab::access($page, $request->user(), true) === 'edit', 403, 'Live drawing is not available for this page.');
    }
}
