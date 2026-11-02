<?php

namespace App\Http\Controllers;

use App\Support\Live\LiveChannels;
use App\Support\Live\LiveConfig;
use App\Support\Live\LiveToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Live channels (BRAI-151): a short-lived token for the channels this user may join, checked with
 * the existing policies (LiveChannels). Channels they may not join come back in `denied` (no 403:
 * one token covers a whole page's channels). 404 while live channels are off.
 */
class LiveTokenController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless(LiveConfig::enabled($request), 404);

        $validated = $request->validate([
            'channels' => 'required|array|min:1|max:'.LiveChannels::MAX_PER_TOKEN,
            'channels.*' => 'required|string|max:100',
        ]);

        return response()->json(LiveToken::grant($request->user(), $validated['channels']));
    }
}
