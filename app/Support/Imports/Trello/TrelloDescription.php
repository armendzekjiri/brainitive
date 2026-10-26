<?php

namespace App\Support\Imports\Trello;

use App\Support\Mcp\PageContentConverter;
use App\Support\SafeHtml;

/**
 * The issue description for a Trello card (BRAI-65): the card's Markdown → HTML (sanitized),
 * then the checklists as Lexical check lists, attachment links when the user asked for them,
 * and the other Trello members as text. Item descriptions store HTML directly (ItemDetail).
 *
 * The checklist and link blocks are built here from escaped text, after SafeHtml: the sanitizer
 * would strip the `aria-checked` state the issue view and the editor read.
 */
final class TrelloDescription
{
    /**
     * @param  list<array{id: string, name: string, items: list<array{id: string, name: string, complete: bool}>}>  $checklists  rendered as check lists ([] when they become subtasks)
     * @param  list<array{name: string, url: string}>  $attachments  rendered as links ([] when not wanted)
     * @param  list<string>  $otherMembers  Trello members of the card who aren't the assignee
     */
    public static function html(string $markdown, array $checklists = [], array $attachments = [], array $otherMembers = [], bool $hasAssignee = false): ?string
    {
        $parts = [];
        $body = trim($markdown) === '' ? '' : (string) SafeHtml::clean(PageContentConverter::markdownToHtml(self::normalize($markdown)));
        if ($body !== '') {
            $parts[] = $body;
        }

        foreach ($checklists as $checklist) {
            $items = '';
            foreach ($checklist['items'] as $i => $item) {
                $items .= $item['complete']
                    ? '<li role="checkbox" tabindex="-1" aria-checked="true" value="'.($i + 1).'" class="lexical-listitem-checked line-through text-muted-foreground relative list-none pl-6">'.self::esc($item['name']).'</li>'
                    : '<li role="checkbox" tabindex="-1" aria-checked="false" value="'.($i + 1).'" class="lexical-listitem-unchecked relative list-none pl-6">'.self::esc($item['name']).'</li>';
            }
            $parts[] = '<p><strong>'.self::esc($checklist['name']).'</strong></p>'.($items !== '' ? '<ul __lexicallisttype="check">'.$items.'</ul>' : '');
        }

        $links = array_values(array_filter($attachments, fn ($a) => $a['url'] !== ''));
        if ($links !== []) {
            $parts[] = '<p><strong>Attachments (in Trello)</strong></p><ul>'.implode('', array_map(
                fn ($a) => '<li><a href="'.self::esc($a['url']).'" rel="noopener noreferrer">'.self::esc($a['name']).'</a></li>',
                $links,
            )).'</ul>';
        }

        if ($otherMembers !== []) {
            $parts[] = '<p>'.($hasAssignee ? 'Also on this card in Trello: ' : 'On this card in Trello: ').self::esc(implode(', ', $otherMembers)).'</p>';
        }

        return $parts === [] ? null : implode('', $parts);
    }

    /** The few Trello Markdown habits the shared converter doesn't know. */
    private static function normalize(string $markdown): string
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        // task-list syntax inside a description ("- [x] done") → bullets with a mark
        $markdown = (string) preg_replace(['/^(\s*[-*+])\s+\[[xX]\]\s+/m', '/^(\s*[-*+])\s+\[ \]\s+/m'], ['$1 ☑ ', '$1 ☐ '], $markdown);
        // bare URLs (Trello links them when it shows the card) → Markdown links
        $markdown = (string) preg_replace('#(?<![(\[<"=])\bhttps?://[^\s()<>\[\]"]+#i', '[$0]($0)', $markdown);

        return $markdown;
    }

    private static function esc(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
