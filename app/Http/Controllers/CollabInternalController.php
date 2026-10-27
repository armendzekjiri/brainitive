<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\ItemCollabState;
use App\Models\Page;
use App\Models\PageCollabState;
use App\Models\User;
use App\Support\Collab\CollabConfig;
use App\Support\Collab\CollabDocument;
use App\Support\Collab\ItemCollab;
use App\Support\Collab\PageCollab;
use App\Support\Collab\WhiteboardCollab;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Internal API for the realtime server (BRAI-54; whiteboards BRAI-153), behind VerifyCollabSecret: load / store a live
 * document's Yjs state and re-check who may still be connected. Stale epoch → 409 (the server closes
 * the document; browsers reopen on the new draft), page gone → 404. Issue descriptions (`item.<id>.<epoch>`,
 * BRAI-151) have their own state table and access rule (ItemCollab).
 */
class CollabInternalController extends Controller
{
    public function show(string $document): JsonResponse
    {
        if ($item = $this->resolveItem($document)) {
            return $this->showItem(...$item);
        }
        [$page, $epoch] = $this->resolve($document);
        $state = PageCollabState::where('page_id', $page->id)->first(['id', 'epoch', 'state']);

        if (($state->epoch ?? 0) !== $epoch) {
            return response()->json(['message' => 'Stale document.', 'epoch' => $state->epoch ?? 0], 409);
        }

        return response()->json(['state' => $state?->state]);
    }

    public function store(Request $request, string $document): JsonResponse|Response
    {
        $item = $this->resolveItem($document);
        [$page, $epoch] = $item === null ? $this->resolve($document) : [null, $item[1]];
        $validated = $request->validate(['state' => 'required|string']);

        $bytes = base64_decode($validated['state'], true);
        if ($bytes === false || $bytes === '') {
            return response()->json(['message' => 'Invalid state.'], 422);
        }
        if (strlen($bytes) > CollabConfig::maxStateBytes()) {
            return response()->json(['message' => 'Document too large.'], 413);
        }
        if ($item !== null) {
            return $this->storeItem($item[0], $epoch, $validated['state'], strlen($bytes));
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
        if ($item = $this->resolveItem($validated['document'])) {
            return $this->itemAccess($item[0], $item[1], $validated['user_ids']);
        }
        [$page, $epoch, $kind] = $this->resolve($validated['document']);

        $users = User::whereIn('id', array_unique($validated['user_ids']))->get()->keyBy('id');
        $access = [];
        foreach (array_unique($validated['user_ids']) as $id) {
            $access[(string) $id] = isset($users[$id]) ? $this->accessOf($page, $kind, $users[$id]) : null;
        }

        return response()->json([
            'current' => (PageCollab::epoch($page) ?? 0) === $epoch,
            'users' => (object) $access,
        ]);
    }

    /**
     * The page behind a document name; 404 when it's gone or no longer of that kind (a whiteboard never
     * opens as a document's Y doc and the other way round — BRAI-153).
     *
     * @return array{0: Page, 1: int, 2: string}
     */
    private function resolve(string $document): array
    {
        $parsed = CollabDocument::parse($document);
        abort_if($parsed === null, 404);
        $page = Page::with('project.workspace')->where('uuid', $parsed['uuid'])->first();
        $supported = $page !== null && ($parsed['kind'] === CollabDocument::WHITEBOARD ? WhiteboardCollab::supports($page) : PageCollab::supports($page));
        abort_unless($supported, 404);

        return [$page, $parsed['epoch'], $parsed['kind']];
    }

    /** @return 'edit'|'view'|null */
    private function accessOf(Page $page, string $kind, User $user): ?string
    {
        return $kind === CollabDocument::WHITEBOARD ? WhiteboardCollab::access($page, $user) : PageCollab::access($page, $user);
    }

    // ── Issue descriptions (BRAI-151) ────────────────────────────────────────────────────────────

    /**
     * The issue behind an `item.<id>.<epoch>` name; null for any other name, 404 when it's gone.
     *
     * @return array{0: Item, 1: int}|null
     */
    private function resolveItem(string $document): ?array
    {
        $parsed = CollabDocument::parseItem($document);
        if ($parsed === null) {
            return null;
        }
        $item = Item::with('board.project.workspace')->find($parsed['id']);
        abort_unless($item !== null && ItemCollab::supports($item), 404);

        return [$item, $parsed['epoch']];
    }

    private function showItem(Item $item, int $epoch): JsonResponse
    {
        $state = ItemCollabState::where('item_id', $item->id)->first(['id', 'epoch', 'state']);
        if (($state->epoch ?? 0) !== $epoch) {
            return response()->json(['message' => 'Stale document.', 'epoch' => $state->epoch ?? 0], 409);
        }

        return response()->json(['state' => $state?->state]);
    }

    private function storeItem(Item $item, int $epoch, string $state, int $bytes): JsonResponse|Response
    {
        ItemCollab::ensureState($item);
        $stored = DB::transaction(function () use ($item, $epoch, $state, $bytes) {
            $row = ItemCollabState::where('item_id', $item->id)->lockForUpdate()->firstOrFail(['id', 'epoch']);
            if ($row->epoch !== $epoch) {
                return false;
            }
            $row->update(['state' => $state, 'state_bytes' => $bytes]);

            return true;
        });

        return $stored ? response()->noContent() : response()->json(['message' => 'Stale document.'], 409);
    }

    /**
     * @param  array<int, int>  $userIds
     */
    private function itemAccess(Item $item, int $epoch, array $userIds): JsonResponse
    {
        $ids = array_unique($userIds);
        $users = User::whereIn('id', $ids)->get()->keyBy('id');
        $access = [];
        foreach ($ids as $id) {
            $access[(string) $id] = isset($users[$id]) ? ItemCollab::access($item, $users[$id]) : null;
        }

        return response()->json([
            'current' => (ItemCollab::epoch($item) ?? 0) === $epoch,
            'users' => (object) $access,
        ]);
    }
}
