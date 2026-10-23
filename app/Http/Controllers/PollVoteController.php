<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\Page;
use App\Models\PollVote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Votes for polls in rich text. Anyone who can view the doc / issue can vote (voting
 * doesn't edit the document); one vote per person, changeable or removable.
 */
class PollVoteController extends Controller
{
    public function show(Request $request, string $scopeType, string $scopeId, string $pollId): JsonResponse
    {
        $this->authorizeScope($request, $scopeType, $scopeId);

        return response()->json($this->results($request, $scopeType, $scopeId, $pollId));
    }

    public function vote(Request $request, string $scopeType, string $scopeId, string $pollId): JsonResponse
    {
        $this->authorizeScope($request, $scopeType, $scopeId);
        $validated = $request->validate(['option_uid' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/']]);

        $key = ['scope_type' => $scopeType, 'scope_id' => $scopeId, 'poll_id' => $pollId, 'user_id' => $request->user()->id];
        if (empty($validated['option_uid'])) {
            PollVote::where($key)->delete();
        } else {
            PollVote::updateOrCreate($key, ['option_uid' => $validated['option_uid']]);
        }

        return response()->json($this->results($request, $scopeType, $scopeId, $pollId));
    }

    /**
     * @return array{counts: array<string, int>, total: int, my_vote: ?string}
     */
    private function results(Request $request, string $scopeType, string $scopeId, string $pollId): array
    {
        $votes = PollVote::where(['scope_type' => $scopeType, 'scope_id' => $scopeId, 'poll_id' => $pollId]);
        $counts = (clone $votes)->selectRaw('option_uid, count(*) as total')->groupBy('option_uid')->pluck('total', 'option_uid')->map(fn ($n) => (int) $n);

        return [
            'counts' => (object) $counts->all(),
            'total' => (int) $counts->sum(),
            'my_vote' => (clone $votes)->where('user_id', $request->user()->id)->value('option_uid'),
        ];
    }

    private function authorizeScope(Request $request, string $scopeType, string $scopeId): void
    {
        $model = match ($scopeType) {
            'page' => Page::where('uuid', $scopeId)->first(),
            'item' => ctype_digit($scopeId) ? Item::find((int) $scopeId) : null,
            default => null,
        };

        abort_unless($model && $request->user()->can('view', $model), 404);
    }
}
