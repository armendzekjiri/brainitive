<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\Page;
use App\Support\Polls\PollVotes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Votes for polls in rich text (the logic: Support\Polls\PollVotes, shared with the App API).
 */
class PollVoteController extends Controller
{
    public function show(Request $request, string $scopeType, string $scopeId, string $pollId): JsonResponse
    {
        $this->authorizeScope($request, $scopeType, $scopeId);

        return response()->json(PollVotes::results($request->user(), $scopeType, $scopeId, $pollId));
    }

    public function vote(Request $request, string $scopeType, string $scopeId, string $pollId): JsonResponse
    {
        $scope = $this->authorizeScope($request, $scopeType, $scopeId);
        $validated = $request->validate(PollVotes::rules());
        PollVotes::vote($scope, $request->user(), $scopeType, $scopeId, $pollId, $validated['option_uid'] ?? null);

        return response()->json(PollVotes::results($request->user(), $scopeType, $scopeId, $pollId));
    }

    private function authorizeScope(Request $request, string $scopeType, string $scopeId): Page|Item
    {
        $model = PollVotes::scope($request->user(), $scopeType, $scopeId);
        abort_unless($model !== null, 404);

        return $model;
    }
}
