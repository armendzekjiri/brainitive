<?php

namespace App\Support\Notifications;

use App\Models\Comment;
use App\Models\Item;
use App\Models\User;
use App\Notifications\ItemCommentedNotification;
use App\Support\Imports\ImportScope;

/**
 * item_commented: a new comment (or reply) tells the issue's watchers, its assignee and its reporter.
 * Never the author, never someone who can't open the project, and never twice: people @mentioned in
 * the comment already get the mention notification instead.
 */
class ItemCommentNotifications
{
    /**
     * @param  list<int>  $mentionedIds  users @mentioned in this comment (ItemMentions::notify's result)
     */
    public static function notify(Item $item, Comment $comment, User $author, array $mentionedIds = []): void
    {
        if (ImportScope::active()) {
            return; // bulk imports notify nobody per record (v1.41.0)
        }
        $project = $item->board?->project;
        if (! $project) {
            return;
        }

        $ids = $item->watchers()->pluck('users.id')
            ->push($item->assigned_to, $item->reporter_id)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->reject(fn (int $id) => $id === $author->id || in_array($id, $mentionedIds, true));

        if ($ids->isEmpty()) {
            return;
        }

        User::whereIn('id', $ids->all())->get()
            ->filter(fn (User $user) => $user->hasProjectAccess($project))
            ->each(fn (User $user) => $user->notify(new ItemCommentedNotification($item, $comment, $author)));
    }
}
