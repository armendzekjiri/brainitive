<?php

namespace App\Http\Controllers\AppApi;

use App\Http\Controllers\Controller;
use App\Support\Polls\PollVotes;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * App API v1: votes for polls inside docs and issue descriptions — the web's poll endpoints
 * (Support\Polls\PollVotes), wrapped in `{data}`. Anyone who can view the doc / issue can vote.
 */
#[Group('Docs', weight: 230)]
class PollController extends Controller
{
    /**
     * A poll's results.
     *
     * `{data: {counts: {option_uid: n}, total, my_vote}}`. A doc / issue the user can't view → 404.
     */
    public function show(Request $request, string $scopeType, string $scopeId, string $pollId): JsonResponse
    {
        $scope = PollVotes::scope($request->user(), $scopeType, $scopeId);
        abort_unless($scope !== null, 404);

        return response()->json(['data' => PollVotes::results($request->user(), $scope, $scopeType, $pollId)]);
    }

    /**
     * Vote, change or remove the vote.
     *
     * `{option_uid: string|null}` (null removes the vote) → the results. An option or poll that isn't in
     * the saved doc / issue → 422.
     */
    public function vote(Request $request, string $scopeType, string $scopeId, string $pollId): JsonResponse
    {
        $scope = PollVotes::scope($request->user(), $scopeType, $scopeId);
        abort_unless($scope !== null, 404);
        $validated = $request->validate(PollVotes::rules());
        PollVotes::vote($scope, $request->user(), $scopeType, $pollId, $validated['option_uid'] ?? null);

        return response()->json(['data' => PollVotes::results($request->user(), $scope, $scopeType, $pollId)]);
    }
}
