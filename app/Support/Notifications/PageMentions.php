<?php

namespace App\Support\Notifications;

use App\Models\Page;
use App\Models\User;
use App\Notifications\MentionNotification;
use App\Support\Boards\ItemMentions;
use App\Support\Imports\ImportScope;

/**
 * Page @mentions are notified when the page is SAVED (Page::saveDraft), not when the mention is typed:
 * only people who are new compared with the content before the save are told, so autosaves and
 * re-saves never notify twice and a mention deleted before the autosave tells nobody.
 */
class PageMentions
{
    public static function notify(Page $page, User $actor, mixed $before, mixed $after): void
    {
        if (ImportScope::active()) {
            return; // bulk imports notify nobody per record (v1.41.0)
        }
        $new = array_diff(ItemMentions::userIds(self::text($after)), ItemMentions::userIds(self::text($before)), [$actor->id]);
        $project = $page->project;
        if ($new === [] || ! $project) {
            return;
        }

        $url = route('pages.show', [$project->workspace->slug, $project->slug, $page->uuid]);
        foreach (User::whereIn('id', $new)->get() as $user) {
            if ($user->hasProjectAccess($project)) {
                $user->notify(new MentionNotification('page', (string) $page->name, $url, $actor, $page));
            }
        }
    }

    /** Lexical JSON ("userId": 5) plus every string inside it (HTML bodies keep data-mention-user-id="5"). */
    private static function text(mixed $content): ?string
    {
        if ($content === null || is_string($content)) {
            return $content;
        }

        $strings = [];
        if (is_array($content)) {
            array_walk_recursive($content, function ($value) use (&$strings) {
                if (is_string($value) && str_contains($value, 'mention')) {
                    $strings[] = $value;
                }
            });
        }

        return (json_encode($content) ?: '').' '.implode(' ', $strings);
    }
}
