<?php

namespace App\Support\Feedback;

use App\Models\FeedbackPost;
use App\Models\FeedbackSubscription;
use App\Models\User;
use App\Notifications\FeedbackStatusChangedNotification;
use App\Notifications\FeedbackSubmittedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Who hears about feedback (BRAI-60), sent after the surrounding transaction commits:
 * - a new post → everyone who may moderate it: project and workspace admins (never the author's own account);
 * - a public status change → every current follower, once per post and status (feedback_status_mails).
 */
final class FeedbackNotifier
{
    public static function submitted(FeedbackPost $post): void
    {
        DB::afterCommit(function () use ($post) {
            $post->loadMissing('portal.project.workspace', 'author');
            $project = $post->portal->project;
            $authorUserId = $post->author?->user_id;

            // everyone who may moderate (ProjectPolicy::update): project admins and workspace admins
            $recipients = $project->admins()->get()->merge($project->workspace->admins()->get())
                ->unique('id')
                ->filter(fn (User $user) => $user->id !== $authorUserId && $user->hasProjectAccess($project) && $user->can('update', $project))
                ->values();

            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new FeedbackSubmittedNotification($post));
            }
        });
    }

    public static function statusChanged(FeedbackPost $post, string $status, ?string $note): void
    {
        DB::afterCommit(function () use ($post, $status, $note) {
            $post->loadMissing('portal');
            $followers = FeedbackSubscription::with('identity')->where('feedback_post_id', $post->id)->get();

            foreach ($followers as $subscription) {
                $identity = $subscription->identity;
                if (! $identity?->isVerified()) {
                    continue;
                }

                $claimed = DB::table('feedback_status_mails')->insertOrIgnore([
                    'feedback_post_id' => $post->id,
                    'portal_identity_id' => $identity->id,
                    'status' => $status,
                    'created_at' => now(),
                ]) === 1;

                if ($claimed) {
                    $identity->notify(new FeedbackStatusChangedNotification($post, $post->portal, $status, $note));
                }
            }
        });
    }
}
