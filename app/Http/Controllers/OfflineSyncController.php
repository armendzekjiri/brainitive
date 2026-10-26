<?php

namespace App\Http\Controllers;

use App\Support\Offline\OfflineSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Offline mode (BRAI-82). `shell` is the page the service worker serves when a navigation fails
 * offline (public, without a session and without shared props, so the cached copy holds no
 * account data). `store` applies one mutation from the device's outbox (same policies as the app).
 */
class OfflineSyncController extends Controller
{
    public function shell(): Response
    {
        // No shared props (account, workspaces, counters): the page runs from IndexedDB.
        Inertia::flushShared();

        return Inertia::render('Offline/Shell');
    }

    public function store(Request $request): JsonResponse
    {
        $mutation = $request->validate([
            'id' => 'required|uuid',
            'type' => ['required', 'string', Rule::in(array_keys(OfflineSync::TYPES))],
            'target' => 'nullable|array',
            'target.id' => 'nullable|integer',
            'target.ref' => 'nullable|uuid',
            'base' => 'nullable|array',
            'base.updated_at' => 'nullable|string|max:40',
            'base.fields' => 'nullable|array|max:10',
            'data' => 'present|array|max:20',
        ]);

        $outcome = OfflineSync::handle($request->user(), $mutation + ['data' => []]);

        return response()->json($outcome->body, $outcome->status);
    }
}
