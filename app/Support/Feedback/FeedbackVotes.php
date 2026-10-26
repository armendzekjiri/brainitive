<?php

namespace App\Support\Feedback;

use App\Models\FeedbackPost;
use App\Models\PortalIdentity;
use Illuminate\Support\Facades\DB;

/**
 * Votes and follows on feedback posts (BRAI-60). One vote per identity + post (unique index);
 * `votes_count` changes in the same transaction, only when a row was really added or removed, so
 * double clicks, retries and parallel requests count once. The post row is locked first: votes
 * and a merge of the same post serialize instead of deadlocking.
 */
final class FeedbackVotes
{
    /** @return bool true when the vote was new */
    public static function vote(FeedbackPost $post, PortalIdentity $identity): bool
    {
        return DB::transaction(function () use ($post, $identity) {
            $locked = FeedbackPost::whereKey($post->id)->lockForUpdate()->first();
            if (! $locked?->isPublished()) {
                return false;
            }

            $added = DB::table('feedback_votes')->insertOrIgnore([
                'feedback_post_id' => $locked->id,
                'portal_identity_id' => $identity->id,
                'created_at' => now(),
            ]) === 1;

            if ($added) {
                FeedbackPost::whereKey($locked->id)->increment('votes_count');
            }

            return $added;
        });
    }

    /** @return bool true when a vote was removed */
    public static function unvote(FeedbackPost $post, PortalIdentity $identity): bool
    {
        return DB::transaction(function () use ($post, $identity) {
            $locked = FeedbackPost::whereKey($post->id)->lockForUpdate()->first();
            if (! $locked) {
                return false;
            }

            $removed = DB::table('feedback_votes')
                ->where('feedback_post_id', $locked->id)
                ->where('portal_identity_id', $identity->id)
                ->delete() > 0;

            if ($removed) {
                FeedbackPost::whereKey($locked->id)->where('votes_count', '>', 0)->decrement('votes_count');
            }

            return $removed;
        });
    }

    public static function follow(FeedbackPost $post, PortalIdentity $identity): bool
    {
        return DB::table('feedback_subscriptions')->insertOrIgnore([
            'feedback_post_id' => $post->id,
            'portal_identity_id' => $identity->id,
            'created_at' => now(),
        ]) === 1;
    }

    public static function unfollow(FeedbackPost $post, PortalIdentity $identity): bool
    {
        return DB::table('feedback_subscriptions')
            ->where('feedback_post_id', $post->id)
            ->where('portal_identity_id', $identity->id)
            ->delete() > 0;
    }
}
