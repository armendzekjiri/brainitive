<?php

namespace App\Support\Feedback;

use Illuminate\Support\Str;

/**
 * Public feedback text is plain text (BRAI-60): no HTML is stored, control characters are removed,
 * runs of blank lines collapse. React renders it as text; mails pass it through CatalogNotification::plain.
 */
final class FeedbackText
{
    public static function line(?string $text, int $max): string
    {
        return Str::limit(trim(preg_replace('/\s+/u', ' ', self::strip($text))), $max, '');
    }

    public static function block(?string $text, int $max): ?string
    {
        $text = str_replace(["\r\n", "\r"], "\n", self::strip($text));
        $text = preg_replace('/[ \t]+\n/u', "\n", $text);
        $text = trim(preg_replace("/\n{3,}/", "\n\n", $text));

        return $text === '' ? null : Str::limit($text, $max, '');
    }

    private static function strip(?string $text): string
    {
        $text = html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5);

        // control characters except newline and tab
        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
    }
}
