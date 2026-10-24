<?php

namespace App\Support\Captures;

use App\Models\Capture;
use Illuminate\Support\Str;

/**
 * The fields a capture becomes for each destination type — the convert dialog's preview, which
 * the user edits before converting. Text is Markdown for issues, tasks and docs (code blocks and
 * links survive the conversion); snippets keep the code verbatim.
 */
class CaptureFields
{
    private const TITLE_LIMIT = 120;

    /**
     * @return array<string, mixed>
     */
    public static function defaults(Capture $capture, string $type): array
    {
        [$title, $body] = self::split($capture->text);
        $body = self::withUrl($body, $capture);

        return match ($type) {
            'issue' => ['title' => $title, 'description' => $body],
            'task' => ['title' => $title, 'notes' => $body],
            'page' => ['title' => $title, 'markdown' => $body],
            'bookmark' => $capture->url
                ? ['type' => 'link', 'title' => $title, 'url' => $capture->url, 'content' => self::split($capture->text)[1]]
                : ['type' => 'note', 'title' => $title, 'url' => null, 'content' => $capture->text],
            'snippet' => self::snippet($capture),
        };
    }

    /**
     * Title = the first line without Markdown markers; body = the rest. When the first line is
     * too long for a title, the title is shortened and the body keeps the whole text.
     *
     * @return array{0: string, 1: string}
     */
    public static function split(string $text): array
    {
        $lines = preg_split('/\R/u', trim($text)) ?: [];
        $first = array_shift($lines) ?? '';

        if (str_starts_with(ltrim($first), '```')) {
            return ['Untitled capture', trim($text)];
        }

        $title = trim((string) preg_replace('/^\s*(#{1,6}\s+|[-*+]\s+(\[[ xX]\]\s+)?|>\s?|\d+[.)]\s+)/u', '', $first));
        $body = trim(implode("\n", $lines));

        if ($title === '') {
            return ['Untitled capture', trim($text)];
        }
        if (mb_strlen($title) > self::TITLE_LIMIT) {
            return [Str::limit($title, self::TITLE_LIMIT), trim($text)];
        }

        return [$title, $body];
    }

    /** The capture's URL as a Markdown link, unless the text already contains it. */
    private static function withUrl(string $body, Capture $capture): string
    {
        if (! $capture->url || str_contains($capture->text, $capture->url)) {
            return $body;
        }

        $link = '['.$capture->url.']('.$capture->url.')';

        return $body === '' ? $link : $body."\n\n".$link;
    }

    /**
     * The first fenced code block (with its language) or the whole text, verbatim.
     *
     * @return array{title: string, content: string, language: string, description: ?string}
     */
    private static function snippet(Capture $capture): array
    {
        $text = $capture->text;
        $language = 'plaintext';
        $content = $text;
        $rest = $text;

        if (preg_match('/^```[ \t]*([\w+#-]*)[ \t]*\R(.*?)\R```[ \t]*$/msu', $text, $m)) {
            $content = $m[2];
            $candidate = strtolower($m[1]);
            if ($candidate !== '' && preg_match('/^[a-z0-9+#-]+$/', $candidate)) {
                $language = $candidate;
            }
            $rest = trim(str_replace($m[0], '', $text));
        }

        $title = $rest !== '' && $rest !== $text ? self::split($rest)[0] : self::split($text)[0];

        return [
            'title' => $title,
            'content' => $content,
            'language' => $language,
            'description' => $capture->url ? 'Source: '.$capture->url : null,
        ];
    }
}
