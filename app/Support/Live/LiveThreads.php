<?php

namespace App\Support\Live;

use App\Models\Comment;
use App\Models\TicketReply;

/**
 * Conversations that update live (BRAI-151): issue comments on `item.{id}`, helpdesk ticket replies
 * on `ticket.{id}`. Called from the models' events, so every path (board UI, MCP, portal, inbound
 * mail, imports) publishes. Ids only — open views refetch through their permission-checked endpoint.
 */
final class LiveThreads
{
    /** @param 'created'|'updated'|'deleted' $action */
    public static function comment(Comment $comment, string $action): void
    {
        if (! $comment->item_id) {
            return;
        }
        Live::publish("item.{$comment->item_id}", "comment.{$action}", [
            'id' => (int) $comment->item_id,
            'comment_id' => (int) $comment->id,
            'parent_id' => $comment->parent_id === null ? null : (int) $comment->parent_id,
        ]);
    }

    /** @param 'created'|'updated'|'deleted' $action */
    public static function ticketReply(TicketReply $reply, string $action): void
    {
        if (! $reply->ticket_id) {
            return;
        }
        Live::publish("ticket.{$reply->ticket_id}", "reply.{$action}", [
            'id' => (int) $reply->ticket_id,
            'reply_id' => (int) $reply->id,
        ]);
    }
}
