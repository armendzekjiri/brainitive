<?php

namespace App\Support\Imports\Archive;

use App\Support\SafeHtml;
use App\Support\Templates\ContentSanitizer;
use Closure;

/**
 * Rich content in and out of a project archive (BRAI-81).
 *
 * Export: mentions become plain text (people of this instance mean nothing elsewhere), files
 * embedded from this instance's public storage are replaced by "brainitive-media:<ref>"
 * placeholders (the writer bundles them), whiteboard issue cards point at issue refs.
 *
 * Restore: placeholders become the restored files' URLs, issue refs become the new issue ids, and
 * every HTML string goes through SafeHtml (the archive is an untrusted upload).
 */
final class ArchiveContent
{
    /** Files of this instance that content embeds (editor uploads, inline diagram previews). */
    private const MEDIA = '#(?:(?:https?:)?//[^\s"\'<>()/\\\\]+)?/storage/((?:uploads/(?:images|documents)|excalidraw-embeds/\d+)/[A-Za-z0-9][A-Za-z0-9._-]{0,200})#';

    private const PLACEHOLDER = '#'.ProjectArchive::MEDIA_SCHEME.'(m\d{1,6})#';

    // ── export ───────────────────────────────────────────────────────────────

    /**
     * @param  Closure(string): ?string  $media  public-disk path → media ref (null: leave the URL)
     */
    public static function exportHtml(?string $html, Closure $media): ?string
    {
        if ($html === null || $html === '') {
            return $html;
        }
        // Lexical + Tiptap mention markup goes, the visible "@Name" stays
        $html = preg_replace('/\s+data-(?:mention|lexical-mention)[\w-]*="[^"]*"/i', '', $html) ?? $html;

        return self::replaceMedia($html, $media);
    }

    /**
     * A page's live content (Lexical HTML / JSON editors / Excalidraw).
     *
     * @param  Closure(string): ?string  $media
     * @param  array<int, string>  $itemRefs  issue id → ref (issues in the archive)
     */
    public static function exportPage(mixed $content, Closure $media, array $itemRefs): ?array
    {
        if (! is_array($content)) {
            return null;
        }
        if (self::isWhiteboard($content)) {
            unset($content['previewImageUrl']); // a PNG of the board as last saved; redrawn on the next save
            $content['elements'] = array_map(function ($element) use ($itemRefs) {
                if (! is_array($element)) {
                    return $element;
                }
                if (($element['customData']['brainitive'] ?? null) === 'issue') {
                    $ref = $itemRefs[(int) ($element['customData']['itemId'] ?? 0)] ?? null;
                    if ($ref === null) {
                        unset($element['customData']);
                    } else {
                        unset($element['customData']['itemId']);
                        $element['customData']['itemRef'] = $ref;
                    }
                }
                $element['link'] = self::externalLink($element['link'] ?? null);

                return $element;
            }, is_array($content['elements'] ?? null) ? $content['elements'] : []);

            return $content;
        }

        $content = ContentSanitizer::json($content); // mention nodes → text
        if (isset($content['html']) && is_string($content['html'])) {
            $content['html'] = self::exportHtml($content['html'], $media);
        }

        return self::mapStrings($content, fn (string $s) => self::replaceMedia($s, $media));
    }

    /** Plain JSON editor content (task descriptions). */
    public static function exportJson(mixed $content, Closure $media): ?array
    {
        if (! is_array($content)) {
            return null;
        }

        return self::mapStrings(ContentSanitizer::json($content), fn (string $s) => self::replaceMedia($s, $media));
    }

    // ── restore ──────────────────────────────────────────────────────────────

    /**
     * @param  array<string, string>  $mediaUrls  media ref → URL of the restored file
     */
    public static function restoreHtml(mixed $html, array $mediaUrls): ?string
    {
        if (! is_string($html) || $html === '') {
            return null;
        }

        return SafeHtml::clean(self::fillMedia($html, $mediaUrls));
    }

    /**
     * @param  array<string, string>  $mediaUrls
     * @param  array<string, int>  $itemIds  issue ref → new issue id
     */
    public static function restorePage(mixed $content, array $mediaUrls, array $itemIds): ?array
    {
        if (! is_array($content)) {
            return null;
        }
        if (self::isWhiteboard($content)) {
            unset($content['previewImageUrl']);
            $content['type'] = 'excalidraw';
            $content['elements'] = array_values(array_map(function ($element) use ($itemIds) {
                if (! is_array($element)) {
                    return $element;
                }
                if (($element['customData']['brainitive'] ?? null) === 'issue') {
                    $id = $itemIds[(string) ($element['customData']['itemRef'] ?? '')] ?? null;
                    if ($id === null) {
                        unset($element['customData']);
                    } else {
                        unset($element['customData']['itemRef']);
                        $element['customData']['itemId'] = $id;
                    }
                }
                $element['link'] = self::externalLink($element['link'] ?? null);

                return $element;
            }, is_array($content['elements'] ?? null) ? $content['elements'] : []));

            return $content;
        }

        $content = self::mapStrings($content, fn (string $s) => self::fillMedia($s, $mediaUrls));
        if (array_key_exists('html', $content)) {
            $content['html'] = is_string($content['html']) ? (string) SafeHtml::clean($content['html']) : '';
        }

        return $content;
    }

    /** @param  array<string, string>  $mediaUrls */
    public static function restoreJson(mixed $content, array $mediaUrls): ?array
    {
        return is_array($content) ? self::mapStrings($content, fn (string $s) => self::fillMedia($s, $mediaUrls)) : null;
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    public static function isWhiteboard(array $content): bool
    {
        return ($content['type'] ?? null) === 'excalidraw' || (isset($content['elements']) && isset($content['appState']));
    }

    /** Only absolute http(s) links survive on whiteboard elements (app links point at the old project). */
    private static function externalLink(mixed $link): ?string
    {
        return is_string($link) && preg_match('#^https?://#i', $link) && ! str_contains($link, '/workspaces/') ? $link : null;
    }

    private static function replaceMedia(string $text, Closure $media): string
    {
        if (! str_contains($text, '/storage/')) {
            return $text;
        }

        return preg_replace_callback(self::MEDIA, function (array $m) use ($media) {
            $ref = $media($m[1]);

            return $ref === null ? $m[0] : ProjectArchive::MEDIA_SCHEME.$ref;
        }, $text) ?? $text;
    }

    /** @param  array<string, string>  $mediaUrls */
    private static function fillMedia(string $text, array $mediaUrls): string
    {
        if (! str_contains($text, ProjectArchive::MEDIA_SCHEME)) {
            return $text;
        }

        return preg_replace_callback(self::PLACEHOLDER, fn (array $m) => $mediaUrls[$m[1]] ?? '', $text) ?? $text;
    }

    /** Apply $fn to every string value, recursively. */
    private static function mapStrings(array $value, Closure $fn): array
    {
        foreach ($value as $key => $child) {
            if (is_string($child)) {
                $value[$key] = $fn($child);
            } elseif (is_array($child)) {
                $value[$key] = self::mapStrings($child, $fn);
            }
        }

        return $value;
    }
}
