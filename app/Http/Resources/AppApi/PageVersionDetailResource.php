<?php

namespace App\Http\Resources\AppApi;

use App\Models\PageVersion;
use App\Support\Boards\IssueMarkdown;
use App\Support\Mcp\PageContentConverter;
use App\Support\SafeHtml;
use Illuminate\Http\Request;

/**
 * One version with its text (a doc's: Markdown + sanitized HTML; null for a whiteboard's scene).
 *
 * @mixin PageVersion
 */
class PageVersionDetailResource extends PageVersionResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $content = $this->content;
        $doc = is_array($content) && in_array($content['type'] ?? null, ['lexical', 'doc'], true);
        $html = $doc ? PageContentConverter::pageContentToHtml($content) : null;

        return [
            ...parent::toArray($request),
            'markdown' => $html === null ? null : (IssueMarkdown::toMarkdown($html) ?? ''),
            'html' => $html === null ? null : (string) SafeHtml::clean($html),
        ];
    }
}
