<?php

namespace App\Support\Templates;

/**
 * Removes references to people and to objects of the source project from captured content
 * (BRAI-72): mentions become plain text, inline diagram previews (files stored per page)
 * are dropped, and whiteboard elements lose links to issues and app pages.
 */
class ContentSanitizer
{
    /**
     * Issue descriptions and doc HTML: mention markup goes, the visible "@Name" text stays.
     */
    public static function html(?string $html): ?string
    {
        if ($html === null || $html === '') {
            return $html;
        }

        // data-mention-user-id / data-mention-* attributes (Lexical + Tiptap mention nodes)
        $html = preg_replace('/\s+data-(?:mention|lexical-mention)[\w-]*="[^"]*"/i', '', $html) ?? $html;
        // Previews of inline diagrams point at files of the source page
        $html = preg_replace('/<img\b[^>]*\bsrc="[^"]*excalidraw-embeds\/[^"]*"[^>]*>/i', '', $html) ?? $html;
        $html = preg_replace('/\s+data-lexical-excalidraw[\w-]*="[^"]*"/i', '', $html) ?? $html;
        // Links into the app (issues, docs, boards of the source project) keep their text only
        $html = preg_replace('/\s+href="(?:https?:\/\/[^"\/]+)?\/workspaces\/[^"]*"/i', '', $html) ?? $html;

        return $html;
    }

    /**
     * A page's live content in any of the stored formats.
     */
    public static function pageContent(mixed $content): ?array
    {
        if (! is_array($content)) {
            return null;
        }

        $type = $content['type'] ?? null;

        if ($type === 'excalidraw' || (isset($content['elements']) && isset($content['appState']))) {
            $content['elements'] = array_map([self::class, 'whiteboardElement'], is_array($content['elements'] ?? null) ? $content['elements'] : []);
            // A PNG of the source board as last saved
            unset($content['previewImageUrl']);

            return $content;
        }

        unset($content['excalidrawEmbeds']);
        if (isset($content['html']) && is_string($content['html'])) {
            $content['html'] = self::html($content['html']);
        }

        return self::stripMentionNodes($content);
    }

    /**
     * JSON editor content (task descriptions): mention nodes become text.
     */
    public static function json(array $content): array
    {
        return self::stripMentionNodes($content);
    }

    /**
     * Issue cards keep their look but stop pointing at an issue of the source project.
     */
    private static function whiteboardElement(mixed $element): mixed
    {
        if (! is_array($element)) {
            return $element;
        }
        if (($element['customData']['brainitive'] ?? null) === 'issue') {
            unset($element['customData']);
        }
        if (isset($element['link']) && is_string($element['link']) && str_contains($element['link'], '/workspaces/')) {
            $element['link'] = null;
        }

        return $element;
    }

    /**
     * JSON editor formats (legacy Tiptap / Lexical state): a mention node becomes its text.
     */
    private static function stripMentionNodes(array $node): array
    {
        if (($node['type'] ?? null) === 'mention') {
            $label = $node['attrs']['label'] ?? $node['text'] ?? $node['mentionName'] ?? '';

            return ['type' => 'text', 'text' => $label !== '' && ! str_starts_with((string) $label, '@') ? '@'.$label : (string) $label];
        }
        unset($node['userId'], $node['mentionUserId']);

        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = array_is_list($value)
                    ? array_map(fn ($child) => is_array($child) ? self::stripMentionNodes($child) : $child, $value)
                    : self::stripMentionNodes($value);
            }
        }

        return $node;
    }
}
