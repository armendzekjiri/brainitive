<?php

namespace App\Support\Boards;

use App\Models\ActivityLog;
use App\Models\Comment;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Add a comment to an issue with the same side effects everywhere (board UI, MCP): history
 * entry and mention notifications, exactly once.
 */
class ItemComments
{
    public static function add(Item $item, User $author, string $html, ?int $parentId = null): Comment
    {
        $comment = $item->comments()->create([
            'user_id' => $author->id,
            'body' => $html,
            'parent_id' => $parentId,
        ]);

        ActivityLog::log($item, 'commented', null, [
            'comment_id' => $comment->id,
            'reply' => $comment->parent_id !== null,
            'excerpt' => Str::limit(trim(strip_tags($html)), 120),
        ]);

        ItemMentions::notify($html, $item, $author);

        return $comment;
    }
}
