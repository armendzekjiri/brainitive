<?php

namespace App\Support\PageReviews;

use App\Models\PageComment;
use App\Models\PageReview;
use App\Models\User;
use App\Notifications\ReviewCommentedNotification;
use App\Notifications\ReviewDecidedNotification;
use App\Notifications\ReviewRequestedNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Gate;

/**
 * Who hears about a review (BRAI-80). Never the actor, never twice for one event, and only people who
 * can still open the review (PageReviewPolicy::view).
 */
final class ReviewNotifications
{
    /** review_requested: each reviewer, once (a review's reviewers are fixed when it's requested). */
    public static function requested(PageReview $review, User $actor): void
    {
        $ids = $review->reviewers()->whereNotNull('user_id')->pluck('user_id')->all();
        self::send($review, $ids, $actor, fn () => new ReviewRequestedNotification($review, $actor));
    }

    /** review_decided: the person who asked for the review. */
    public static function decided(PageReview $review, string $decision, ?string $comment, User $actor): void
    {
        self::send($review, array_filter([$review->requested_by]), $actor, fn () => new ReviewDecidedNotification($review, $decision, $comment, $actor));
    }

    /** review_commented: everyone in the thread plus the review's requester. */
    public static function commented(PageComment $comment, User $actor): void
    {
        $root = $comment->parent_id ? $comment->parent : $comment;
        $review = $root->review;
        $ids = PageComment::where(fn ($q) => $q->where('id', $root->id)->orWhere('parent_id', $root->id))->whereNotNull('user_id')->pluck('user_id')
            ->push($review->requested_by)
            ->all();

        self::send($review, $ids, $actor, fn () => new ReviewCommentedNotification($review, $comment, $actor));
    }

    /**
     * @param  array<int, int|null>  $userIds
     * @param  callable(): Notification  $make
     */
    private static function send(PageReview $review, array $userIds, User $actor, callable $make): void
    {
        $ids = array_values(array_unique(array_filter($userIds, fn ($id) => $id !== null && (int) $id !== $actor->id)));
        if ($ids === []) {
            return;
        }

        foreach (User::whereIn('id', $ids)->get() as $user) {
            if (Gate::forUser($user)->allows('view', $review)) {
                $user->notify($make());
            }
        }
    }
}
