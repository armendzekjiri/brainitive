<?php

namespace App\Support\Polls;

use App\Models\Item;
use App\Models\Page;
use App\Models\PollVote;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Votes for polls in rich text (docs, issue descriptions), shared by the web (PollVoteController)
 * and the App API (AppApi\PollController). Anyone who can view the doc / issue can vote — voting
 * doesn't edit the document; one vote per person, changeable or removable.
 */
class PollVotes
{
    /** The doc / issue the poll lives in, or null when it doesn't exist or the user can't view it. */
    public static function scope(User $user, string $scopeType, string $scopeId): Page|Item|null
    {
        $model = match ($scopeType) {
            // a malformed uuid would be a PostgreSQL error (500), not the promised 404
            'page' => Str::isUuid($scopeId) ? Page::where('uuid', $scopeId)->first() : null,
            'item' => ctype_digit($scopeId) ? Item::find((int) $scopeId) : null,
            default => null,
        };

        return $model && $user->can('view', $model) ? $model : null;
    }

    /** The scope's own id as votes are stored ("007" / an upper-case uuid → the record's id). */
    public static function scopeId(Page|Item $scope): string
    {
        return $scope instanceof Page ? (string) $scope->uuid : (string) $scope->id;
    }

    /** Set (or with null remove) the user's vote; only polls and options of the saved doc / issue. */
    public static function vote(Page|Item $scope, User $user, string $scopeType, string $pollId, ?string $optionUid): void
    {
        $scopeId = self::scopeId($scope);
        // no made-up vote rows (Codex web review E)
        if ($optionUid !== null && $optionUid !== '' && ! PollDocuments::allows($scope, $user, $pollId, $optionUid)) {
            throw ValidationException::withMessages(['option_uid' => 'This poll or option doesn’t exist (any more). Reload the page.']);
        }

        $key = ['scope_type' => $scopeType, 'scope_id' => $scopeId, 'poll_id' => $pollId, 'user_id' => $user->id];
        if ($optionUid === null || $optionUid === '') {
            PollVote::where($key)->delete();
        } else {
            PollVote::updateOrCreate($key, ['option_uid' => $optionUid]);
        }
    }

    /**
     * @return array{counts: object, total: int, my_vote: ?string}
     */
    public static function results(User $user, Page|Item $scope, string $scopeType, string $pollId): array
    {
        $scopeId = self::scopeId($scope);
        $votes = PollVote::where(['scope_type' => $scopeType, 'scope_id' => $scopeId, 'poll_id' => $pollId]);
        $counts = (clone $votes)->selectRaw('option_uid, count(*) as total')->groupBy('option_uid')->pluck('total', 'option_uid')->map(fn ($n) => (int) $n);

        return [
            'counts' => (object) $counts->all(),
            'total' => (int) $counts->sum(),
            'my_vote' => (clone $votes)->where('user_id', $user->id)->value('option_uid'),
        ];
    }

    /** Rules for the vote body, shared by both controllers. */
    public static function rules(): array
    {
        return ['option_uid' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/']];
    }
}
