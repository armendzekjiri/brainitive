<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Models\Project;
use App\Models\Workspace;
use App\Support\Collab\CollabConfig;
use App\Support\Collab\PageCollab;
use App\Support\Collab\StateVector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Live co-editing of documents from the browser (BRAI-54): connection session (token), saving the
 * live document's HTML as the draft, and the one-time load of the draft into an empty Y document.
 * The realtime server talks to CollabInternalController instead.
 */
class PageCollabController extends Controller
{
    /** Server URL, document name and a short-lived token for (re)connecting. */
    public function session(Request $request, Workspace $workspace, Project $project, Page $page): JsonResponse
    {
        $this->authorize('view', $page);
        abort_unless(CollabConfig::enabled($request), 404);

        $user = $request->user();
        $access = PageCollab::access($page, $user);
        abort_if($access === null, 403, 'Live editing is not available for this page.');

        return response()->json(PageCollab::session($page, $user, $access) + [
            'user' => ['id' => $user->id, 'name' => $user->name],
        ]);
    }

    /** The HTML (+ title, icon) of the live document, saved as the draft. */
    public function save(Request $request, Workspace $workspace, Project $project, Page $page): JsonResponse
    {
        $this->authorize('update', $page);
        abort_unless(CollabConfig::enabled($request) && PageCollab::access($page, $request->user()) === 'edit', 403, 'Live editing is not available for this page.');

        $validated = $request->validate([
            'epoch' => 'required|integer|min:0',
            'content' => 'required|string|max:10000000',
            'name' => 'required|string|max:255',
            'icon' => 'nullable|string|max:2000000',
            'vector' => 'present|array',
            'baseline' => 'boolean',
        ]);

        $content = json_decode($validated['content'], true);
        $vector = StateVector::normalize($validated['vector']);
        if (! is_array($content) || ($content['type'] ?? null) !== 'lexical' || ! is_string($content['html'] ?? null) || $vector === null) {
            return response()->json(['message' => 'Invalid document state.'], 422);
        }

        $result = PageCollab::save($page, $request->user(), (int) $validated['epoch'], [
            'content' => ['type' => 'lexical', 'html' => $content['html']],
            'name' => $validated['name'],
            'icon' => $validated['icon'] ?? null,
        ], $vector, $request->boolean('baseline'));

        if ($result['status'] === 'stale') {
            return response()->json(['message' => 'This draft was replaced while you were editing.', 'reset' => true, 'epoch' => $result['epoch']], 409);
        }

        return response()->json(['status' => $result['status']] + ($result['page']->fresh() ?? $page)->editorState());
    }

    /** First sync of an empty live document: one browser gets the draft to load into it. */
    public function bootstrap(Request $request, Workspace $workspace, Project $project, Page $page): JsonResponse
    {
        $this->authorize('update', $page);
        abort_unless(CollabConfig::enabled($request) && PageCollab::access($page, $request->user()) === 'edit', 403, 'Live editing is not available for this page.');

        $validated = $request->validate([
            'epoch' => 'required|integer|min:0',
            'claim' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{16,64}$/'],
        ]);

        $result = PageCollab::claimBootstrap($page, (int) $validated['epoch'], $validated['claim']);

        return response()->json($result + ['reset' => $result['status'] === 'stale'], $result['status'] === 'stale' ? 409 : 200);
    }
}
