<?php

namespace App\Support\Feedback;

use App\Models\FeedbackPortal;
use App\Models\FeedbackPost;
use App\Models\PortalIdentity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The ONLY shapes the public feedback pages get (BRAI-60). Allowlisted fields: never the linked
 * card, internal ids, moderation data, author emails or anything from the board.
 */
final class FeedbackPresenter
{
    /** @return array{slug: string, title: string, intro: ?string, url: string, roadmap_url: string} */
    public static function portal(FeedbackPortal $portal): array
    {
        return [
            'slug' => $portal->slug,
            'title' => $portal->title,
            'intro' => $portal->intro,
            'url' => route('feedback.portal', $portal->slug),
            'roadmap_url' => route('feedback.roadmap', $portal->slug),
        ];
    }

    /**
     * @param  Collection<int, FeedbackPost>  $posts
     * @return list<array<string, mixed>>
     */
    public static function posts(FeedbackPortal $portal, Collection $posts, ?PortalIdentity $viewer, bool $withBody = false): array
    {
        $ids = $posts->pluck('id')->all();
        $voted = $viewer && $ids ? DB::table('feedback_votes')->where('portal_identity_id', $viewer->id)->whereIn('feedback_post_id', $ids)->pluck('feedback_post_id')->flip() : collect();
        $following = $viewer && $ids ? DB::table('feedback_subscriptions')->where('portal_identity_id', $viewer->id)->whereIn('feedback_post_id', $ids)->pluck('feedback_post_id')->flip() : collect();

        return $posts->map(fn (FeedbackPost $post) => self::post($portal, $post, $viewer, $voted->has($post->id), $following->has($post->id), $withBody))->values()->all();
    }

    /** @return array<string, mixed> */
    public static function post(FeedbackPortal $portal, FeedbackPost $post, ?PortalIdentity $viewer, bool $hasVoted, bool $isFollowing, bool $withBody = true): array
    {
        return [
            'number' => $post->number,
            'title' => $post->title,
            'body' => $withBody ? $post->body : null,
            'excerpt' => $withBody ? null : Str::limit((string) preg_replace('/\s+/u', ' ', (string) $post->body), 160),
            'status' => $post->status,
            'status_label' => FeedbackPost::STATUS_LABELS[$post->status] ?? $post->status,
            'status_note' => $post->status_note,
            'votes_count' => $post->votes_count,
            'author_name' => $post->author?->name ?: null,
            'created_at' => $post->created_at?->toIso8601String(),
            'url' => route('feedback.post', [$portal->slug, $post->number]),
            'is_pending' => $post->moderation === 'pending',
            'is_mine' => $viewer !== null && $post->author_identity_id === $viewer->id,
            'has_voted' => $hasVoted,
            'is_following' => $isFollowing,
        ];
    }
}
