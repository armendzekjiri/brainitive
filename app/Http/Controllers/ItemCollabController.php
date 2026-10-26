<?php

namespace App\Http\Controllers;

use App\Models\Board;
use App\Models\Item;
use App\Models\Project;
use App\Models\Workspace;
use App\Support\Collab\CollabConfig;
use App\Support\Collab\ItemCollab;
use App\Support\Collab\StateVector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Live issue descriptions from the browser (BRAI-151): connection session (token), saving the live
 * document's HTML as the description, and the one-time load of the description into an empty Y
 * document. Same protocol as PageCollabController; the realtime server talks to CollabInternalController.
 */
class ItemCollabController extends Controller
{
    /** Server URL, document name and a short-lived token (edit = ItemPolicy update, else read-only). */
    public function session(Request $request, Workspace $workspace, Project $project, Board $board, Item $item): JsonResponse
    {
        $this->authorize('view', $item);
        abort_unless(CollabConfig::enabled($request), 404);

        $user = $request->user();
        $access = ItemCollab::access($item, $user);
        abort_if($access === null, 403, 'Live editing is not available for this issue.');

        return response()->json(ItemCollab::session($item, $user, $access) + [
            'user' => ['id' => $user->id, 'name' => $user->name],
        ]);
    }

    /** The HTML of the live document, saved as the description through the normal item update. */
    public function save(Request $request, Workspace $workspace, Project $project, Board $board, Item $item): JsonResponse
    {
        $this->authorize('update', $item);
        abort_unless(CollabConfig::enabled($request) && ItemCollab::access($item, $request->user()) === 'edit', 403, 'Live editing is not available for this issue.');

        $validated = $request->validate([
            'epoch' => 'required|integer|min:0',
            'content' => 'required|string|max:10000000',
            'vector' => 'present|array',
            'baseline' => 'boolean',
        ]);

        $content = json_decode($validated['content'], true);
        $vector = StateVector::normalize($validated['vector']);
        if (! is_array($content) || ($content['type'] ?? null) !== 'lexical' || ! is_string($content['html'] ?? null) || $vector === null) {
            return response()->json(['message' => 'Invalid document state.'], 422);
        }

        $result = ItemCollab::save($item, $request->user(), (int) $validated['epoch'], $content['html'], $vector, $request->boolean('baseline'));

        if ($result['status'] === 'stale') {
            return response()->json(['message' => 'This description was replaced while you were editing.', 'reset' => true, 'epoch' => $result['epoch']], 409);
        }

        return response()->json(['status' => $result['status'], 'description' => ($result['item']->fresh() ?? $item)->description]);
    }

    /** First sync of an empty live document: one browser gets the description to load into it. */
    public function bootstrap(Request $request, Workspace $workspace, Project $project, Board $board, Item $item): JsonResponse
    {
        $this->authorize('update', $item);
        abort_unless(CollabConfig::enabled($request) && ItemCollab::access($item, $request->user()) === 'edit', 403, 'Live editing is not available for this issue.');

        $validated = $request->validate([
            'epoch' => 'required|integer|min:0',
            'claim' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{16,64}$/'],
        ]);

        $result = ItemCollab::claimBootstrap($item, (int) $validated['epoch'], $validated['claim']);

        return response()->json($result + ['reset' => $result['status'] === 'stale'], $result['status'] === 'stale' ? 409 : 200);
    }
}
