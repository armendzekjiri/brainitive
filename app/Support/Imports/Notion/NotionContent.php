<?php

namespace App\Support\Imports\Notion;

use App\Support\Imports\Shared\MarkdownHtml;
use App\Support\SafeHtml;
use Closure;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Turns one Notion page or database into sanitized editor HTML (BRAI-66). The same code runs in the
 * preview (links and files resolved to placeholders, problems counted) and in the import (links to
 * the created pages, files stored like editor uploads), so the preview reports exactly what the
 * import will do.
 *
 *  - Markdown → MarkdownHtml (GitHub-flavoured, SafeHtml).
 *  - CSV database → a readable table plus a note: databases come over as tables.
 *  - Links between pages of the export → the new page URLs ($pageUrl); links to files in the archive
 *    → stored files ($asset); links to files that aren't in the archive → plain text, reported.
 *  - External links stay links; external images stay as they are. Nothing is ever fetched.
 */
final class NotionContent
{
    public const MAX_TABLE_ROWS = 1000;

    public const MAX_TABLE_COLUMNS = 40;

    /**
     * @param  Closure(string $key): ?string  $pageUrl  URL of the page created for a node, or null
     * @param  Closure(string $path, bool $image): ?string  $asset  URL of a stored file, or null (not allowed)
     * @param  Closure(string $kind, string $page, string $target): void  $problem  kind: broken | unsupported
     */
    public function __construct(
        private readonly NotionArchive $archive,
        private readonly Closure $pageUrl,
        private readonly Closure $asset,
        private readonly Closure $problem,
    ) {}

    public function html(NotionNode $node): string
    {
        $html = '';
        if ($node->csv !== null) {
            $html .= $this->table($node);
        }
        if ($node->markdown !== null) {
            $html .= MarkdownHtml::toHtml($this->archive->body($node), lineBreaks: true);
        }

        return $html === '' ? '' : $this->rewrite($node, $html);
    }

    private function table(NotionNode $node): string
    {
        $rows = $this->archive->rows($node);
        $header = array_slice(array_shift($rows) ?? [], 0, self::MAX_TABLE_COLUMNS);
        $total = count($rows);

        // the title column links to the row's page when the export has one
        $rowPages = [];
        foreach ($node->children as $key) {
            $child = $this->archive->nodes[$key];
            if ($child->markdown !== null) {
                $rowPages[mb_strtolower($child->title)] ??= $child->markdown;
            }
        }
        $base = $node->baseDirectory();

        $note = '<p><em>Imported from a Notion database. Databases come over as a table: views, filters, formulas and relations aren’t kept'
            .($total > self::MAX_TABLE_ROWS ? ', and this table shows the first '.self::MAX_TABLE_ROWS.' of '.$total.' rows' : '')
            .'.</em></p>';

        $out = '<table><thead><tr>';
        foreach ($header as $cell) {
            $out .= '<th>'.MarkdownHtml::e($cell).'</th>';
        }
        $out .= '</tr></thead><tbody>';
        foreach (array_slice($rows, 0, self::MAX_TABLE_ROWS) as $row) {
            $out .= '<tr>';
            foreach (array_keys($header) as $i) {
                $cell = $row[$i] ?? '';
                $page = $i === 0 ? ($rowPages[mb_strtolower(trim($cell))] ?? null) : null;
                $content = MarkdownHtml::e($cell);
                if ($page !== null) {
                    $content = '<a href="'.MarkdownHtml::e(self::relativeHref($base, $page)).'">'.$content.'</a>';
                }
                $out .= '<td>'.str_replace(["\r\n", "\n", "\r"], '<br>', $content).'</td>';
            }
            $out .= '</tr>';
        }
        $out .= '</tbody></table>';
        $csvUrl = ($this->asset)($node->csv, false); // our own URL (or "#" in the preview): left alone below
        $csv = $csvUrl === null ? '' : '<p>Original data: <a href="'.MarkdownHtml::e($csvUrl).'">'.MarkdownHtml::e($node->title).'.csv</a></p>';

        return (string) SafeHtml::clean($note.$out.$csv);
    }

    private function rewrite(NotionNode $node, string $html): string
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div id="__notion">'.$html.'</div>', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($dom);
        $from = $node->baseDirectory();

        foreach (iterator_to_array($xpath->query('//a[@href]')) as $link) {
            /** @var DOMElement $link */
            $href = $link->getAttribute('href');
            $target = $this->archive->resolve($from, $href);
            $url = match ($target['type']) {
                'node' => ($this->pageUrl)($target['key']),
                'asset' => ($this->asset)($target['path'], false),
                default => null,
            };
            if ($target['type'] === 'external') {
                continue;
            }
            if ($url !== null) {
                $link->setAttribute('href', $url);

                continue;
            }
            if ($target['type'] === 'missing') {
                ($this->problem)('broken', $node->title, rawurldecode($href));
            } elseif ($target['type'] === 'asset') {
                ($this->problem)('unsupported', $node->title, basename($target['path']));
            }
            // a page that wasn't imported (skipped) or a file we can't keep: keep the words, drop the link
            self::unwrap($link);
        }

        foreach (iterator_to_array($xpath->query('//img[@src]')) as $image) {
            /** @var DOMElement $image */
            $src = $image->getAttribute('src');
            $target = $this->archive->resolve($from, $src);
            if ($target['type'] === 'external') {
                continue;
            }
            $url = $target['type'] === 'asset' ? ($this->asset)($target['path'], true) : null;
            if ($url !== null) {
                $image->setAttribute('src', $url);

                continue;
            }
            $name = $target['type'] === 'node' ? $this->archive->nodes[$target['key']]->title : basename(rawurldecode($src));
            ($this->problem)($target['type'] === 'asset' ? 'unsupported' : 'broken', $node->title, $name);
            $alt = trim($image->getAttribute('alt'));
            $image->parentNode?->replaceChild($dom->createTextNode('['.($alt !== '' ? $alt : $name).']'), $image);
        }

        $out = '';
        foreach ($dom->getElementById('__notion')?->childNodes ?? [] as $child) {
            $out .= $dom->saveHTML($child);
        }

        return $out;
    }

    private static function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;
        if ($parent === null) {
            return;
        }
        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }
        $parent->removeChild($element);
    }

    /** A relative, URL-encoded href from $base to $path (both relative to the archive root). */
    private static function relativeHref(string $base, string $path): string
    {
        $relative = $base !== '' && str_starts_with($path, $base.'/') ? substr($path, strlen($base) + 1) : $path;
        if ($base !== '' && ! str_starts_with($path, $base.'/')) {
            $relative = str_repeat('../', substr_count($base, '/') + 1).$path;
        }

        return implode('/', array_map(fn ($part) => $part === '..' ? $part : rawurlencode($part), explode('/', $relative)));
    }
}
