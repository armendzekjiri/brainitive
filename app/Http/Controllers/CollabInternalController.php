<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Models\PageCollabState;
use App\Models\User;
use App\Support\Collab\CollabConfig;
use App\Support\Collab\CollabDocument;
use App\Support\Collab\PageCollab;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Internal API for the realtime server (BRAI-54), behind VerifyCollabSecret: load / store a live
 * document's Yjs state and re-check who may still be connected. Stale epoch → 409 (the server closes
 * the document; browsers reopen on the new draft), page gone → 404.
 */
class CollabInternalController extends Controller
{
    public function show(string $document): JsonResponse
    {
        [$page, $epoch] = $this->resolve($document);
        $state = PageCollabState::where('page_id', $page->id)->first(['id', 'epoch', 'state']);

        if (($state->epoch ?? 0) !== $epoch) {
            return response()->json(['message' => 'Stale document.', 'epoch' => $state->epoch ?? 0], 409);
        }

        return response()->json(['state' => $state?->state]);
    }

    public function store(Request $request, string $document): JsonResponse|Response
    {
        [$page, $epoch] = $this->resolve($document);
        $validated = $request->validate(['state' => 'required|string']);

        $bytes = base64_decode($validated['state'], true);
        if ($bytes === false || $bytes === '') {
            return response()->json(['message' => 'Invalid state.'], 422);
        }
        if (strlen($bytes) > CollabConfig::maxStateBytes()) {
            return response()->json(['message' => 'Document too large.'], 413);
        }

        PageCollab::ensureState($page);
        $stored = DB::transaction(function () use ($page, $epoch, $validated, $bytes) {
            $state = PageCollabState::where('page_id', $page->id)->lockForUpdate()->firstOrFail(['id', 'epoch']);
            if ($state->epoch !== $epoch) {
                return false;
            }
            $state->update(['state' => $validated['state'], 'state_bytes' => strlen($bytes)]);

            return true;
        });

        return $stored ? response()->noContent() : response()->json(['message' => 'Stale document.'], 409);
    }

    /** Who of the connected users may still view / edit (checked every minute by the server). */
    public function access(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'document' => 'required|string|max:100',
            'user_ids' => 'present|array|max:500',
            'user_ids.*' => 'integer|min:1',
        ]);
        [$page, $epoch] = $this->resolve($validated['document']);

        $users = User::whereIn('id', array_unique($validated['user_ids']))->get()->keyBy('id');
        $access = [];
        foreach (array_unique($validated['user_ids']) as $id) {
            $access[(string) $id] = isset($users[$id]) ? PageCollab::access($page, $users[$id]) : null;
        }

        return response()->json([
            'current' => (PageCollab::epoch($page) ?? 0) === $epoch,
            'users' => (object) $access,
        ]);
    }

    /**
     * @return array{0: Page, 1: int}
     */
    private function resolve(string $document): array
    {
        $parsed = CollabDocument::parse($document);
        abort_if($parsed === null, 404);
        $page = Page::with('project.workspace')->where('uuid', $parsed['uuid'])->first();
        abort_if($page === null || ! PageCollab::supports($page), 404);

        return [$page, $parsed['epoch']];
    }
}
