<?php

namespace App\Support\Boards;

use App\Models\Item;
use App\Models\User;
use App\Notifications\MentionNotification;

/**
 * Notify people @mentioned in an issue's description or comment (HTML or Lexical JSON).
 * Only people who can open the project are notified; never the author themselves.
 */
class ItemMentions
{
    public static function notify(?string $content, Item $item, User $actor): void
    {
        if (empty($content)) {
            return;
        }

        // 1. Regular HTML format: data-mention-user-id="123"
        preg_match_all('/data-mention-user-id="(\d+)"/', $content, $htmlMatches);

        // 2. Lexical JSON / escaped format: "userId":123 or \"userId\":123 or "userId\":\"123"
        preg_match_all('/"userId"(?:\\\\)?"?\s*:\s*(?:\\\\)?"?(\d+)/', $content, $jsonMatches);

        // 3. Fallback escaped HTML: data-mention-user-id\":\"123\"
        preg_match_all('/data-mention-user-id(?:\\\\)?"\s*:\s*(?:\\\\)?"?(\d+)/', $content, $escapedMatches);

        $userIds = array_unique(array_merge(
            $htmlMatches[1] ?? [],
            $jsonMatches[1] ?? [],
            $escapedMatches[1] ?? []
        ));

        if (empty($userIds)) {
            return;
        }

        $workspace = $item->board->project->workspace;
        $project = $item->board->project;
        $board = $item->board;

        $itemUrl = route('boards.items.show', [$workspace->slug, $project->slug, $board->slug, $item->id]);

        foreach ($userIds as $userId) {
            // Don't notify the person who made the mention
            if ((int) $userId === $actor->id) {
                continue;
            }

            $user = User::find($userId);
            if ($user && $user->hasProjectAccess($project)) {
                $user->notify(new MentionNotification(
                    contextType: 'issue',
                    contextTitle: $item->key.' - '.$item->title,
                    contextUrl: $itemUrl,
                    actor: $actor
                ));
            }
        }
    }
}
