<?php

namespace App\Support\Boards;

use App\Models\Project;
use App\Models\User;
use App\Support\Mcp\PageContentConverter;

/**
 * Issue descriptions and comments as Markdown for the apps (App API v1, BRAI-404 / 405). The web stores
 * the editor's HTML; mentions are `<span class="mention" data-mention-user-id="12">@Ann</span>`
 * (ItemMentions reads them). In Markdown a mention is written `@[Ann](user:12)`: on the way in it becomes
 * the editor's mention when user 12 can open the project (else plain `@Ann`), on the way out every
 * mention span becomes that syntax again, so the apps can render and edit them.
 */
final class IssueMarkdown
{
    private const IN = '/@\[([^\]\n]{1,100})\]\(user:([1-9][0-9]{0,17})\)/';

    private const OUT = '/<span\b[^>]*\bdata-mention-user-id="(\d+)"[^>]*>(.*?)<\/span>/s';

    /** Markdown (with `@[Name](user:<id>)` mentions) → the editor's HTML; null for an empty text. */
    public static function toHtml(?string $markdown, Project $project): ?string
    {
        $markdown = trim((string) $markdown);
        if ($markdown === '') {
            return null;
        }

        // mentions → placeholders the Markdown converter leaves alone, then the editor's spans
        $mentions = [];
        $markdown = preg_replace_callback(self::IN, function (array $m) use (&$mentions) {
            $token = 'BRAIMENTION'.count($mentions).'X';
            $mentions[$token] = ['name' => trim($m[1]), 'id' => (int) $m[2]];

            return $token;
        }, $markdown) ?? $markdown;

        $html = PageContentConverter::markdownToHtml($markdown);
        if ($mentions === []) {
            return $html;
        }

        $users = User::whereIn('id', array_column($mentions, 'id'))->get()->keyBy('id');
        foreach ($mentions as $token => $mention) {
            $user = $users->get($mention['id']);
            $replacement = $user instanceof User && $user->hasProjectAccess($project)
                ? '<span class="mention" data-mention-user-id="'.$user->id.'">@'.e($user->name).'</span>'
                : '@'.e($mention['name']);
            $html = str_replace($token, $replacement, $html);
        }

        return $html;
    }

    /** The stored HTML → Markdown with `@[Name](user:<id>)` mentions; null when empty. */
    public static function toMarkdown(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        $mentions = [];
        $html = preg_replace_callback(self::OUT, function (array $m) use (&$mentions) {
            $token = 'BRAIMENTION'.count($mentions).'X';
            $name = ltrim(trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5)), '@');
            $mentions[$token] = '@['.str_replace([']', "\n"], ['', ' '], $name).'](user:'.$m[1].')';

            return $token;
        }, $html) ?? $html;

        $markdown = trim(PageContentConverter::htmlToMarkdown($html));

        return $markdown === '' ? null : strtr($markdown, $mentions);
    }
}
