<?php

namespace App\Http\Controllers;

use App\Support\Navigation\RecentDestinations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The command palette keeps "recent" destinations in the browser as bare {type, id} pairs.
 * This turns them into titles and links — only for what the user may still open, so a
 * revoked project or a private page never shows up (not even as a count). The resolving is
 * shared with the App API (RecentDestinations, BRAI-391).
 */
class CommandPaletteController extends Controller
{
    public const TYPES = RecentDestinations::TYPES;

    public const MAX_RECENT = RecentDestinations::MAX;

    public function recent(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entries' => 'present|array|max:'.self::MAX_RECENT,
            'entries.*.type' => 'required|in:'.implode(',', self::TYPES),
            'entries.*.id' => 'required|integer|min:1',
        ]);

        return response()->json([
            'entries' => RecentDestinations::resolve($request->user(), $validated['entries']),
        ]);
    }
}
