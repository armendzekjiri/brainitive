<?php

namespace App\Support\Imports\Notion;

use App\Support\Imports\ImportFailed;
use App\Support\Imports\SafeZip;
use Illuminate\Support\Facades\File;

/**
 * An unpacked Notion "Markdown & CSV" export (BRAI-66) and its page tree.
 *
 * Notion names every page `Title <32 hex id>.md`; a page's subpages and files sit in a folder of
 * the same name, databases are `Title <id>.csv` (plus `_all.csv` with every row) and their rows are
 * pages in the database's folder. Big exports come as a ZIP holding `Export-…-Part-N.zip` files,
 * which are unpacked into the same tree (one level, only via SafeZip).
 *
 * The archive is unpacked once per run under the run's private folder (preview), and the file list
 * is cached next to it, so run() reuses it (or unpacks again after the files were cleaned up).
 */
final class NotionArchive
{
    public const LIMITS = ['max_entries' => 5000, 'max_total_bytes' => 512 * 1024 * 1024];

    public const MAX_ENTRIES = 2000;

    public const MAX_MARKDOWN_BYTES = 256 * 1024;

    public const MAX_CSV_BYTES = 5 * 1024 * 1024;

    /** @var array<string, NotionNode> */
    public array $nodes = [];

    /** @var list<string> top-level keys, in display order */
    public array $roots = [];

    /** @var array<string, string> files that are neither pages nor databases (images, attachments), NFC path → path */
    public array $assets = [];

    /** @var array<string, string> page / database file → node key */
    private array $byPath = [];

    /** @var array<string, string> folder → node key */
    private array $byDirectory = [];

    /** @var array<string, string> */
    private array $bodies = [];

    private function __construct(public readonly string $root) {}

    /**
     * @param  string  $zipPath  the upload
     * @param  string  $workDir  the run's private folder
     *
     * @throws ImportFailed
     */
    public static function open(string $zipPath, string $workDir): self
    {
        $root = $workDir.'/notion';
        $index = $workDir.'/notion-files.json';
        $files = is_file($index) && is_dir($root) ? json_decode((string) file_get_contents($index), true) : null;

        if (! is_array($files)) {
            File::deleteDirectory($root);
            $files = self::unpackNested($root, SafeZip::extract($zipPath, $root, self::LIMITS));
            file_put_contents($index, json_encode($files));
        }

        $archive = new self($root);
        $archive->build($files);

        return $archive;
    }

    /** `Title <id>` → [title, id|null]. Unicode titles stay as they are. */
    public static function parseName(string $name): array
    {
        if (preg_match('/^(.*?)\s*([0-9a-f]{32})$/i', $name, $m)) {
            return [trim($m[1]) !== '' ? trim($m[1]) : 'Untitled', strtolower($m[2])];
        }

        return [trim($name) !== '' ? trim($name) : 'Untitled', null];
    }

    public static function dirname(string $path): string
    {
        $dir = dirname($path);

        return $dir === '.' ? '' : $dir;
    }

    public function path(string $relative): string
    {
        return $this->root.'/'.$relative;
    }

    /** The Markdown of a page without Notion's leading "# Title" line. */
    public function body(NotionNode $node): string
    {
        if ($node->markdown === null) {
            return '';
        }
        if (isset($this->bodies[$node->key])) {
            return $this->bodies[$node->key];
        }

        $text = (string) file_get_contents($this->path($node->markdown));
        if (str_starts_with($text, "\u{FEFF}")) {
            $text = substr($text, 3);
        }
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }
        $text = preg_replace('/\A\s*#[ \t]+[^\n]*\n?/u', '', $text, 1) ?? $text;

        return $this->bodies[$node->key] = $text;
    }

    public function isEmpty(NotionNode $node): bool
    {
        return $node->kind() === 'page' && trim($this->body($node)) === '';
    }

    /**
     * Rows of a database CSV (header first). Cells are plain strings; a missing CSV gives [].
     *
     * @return list<list<string>>
     */
    public function rows(NotionNode $node): array
    {
        if ($node->csv === null) {
            return [];
        }
        $handle = fopen($this->path($node->csv), 'rb');
        if ($handle === false) {
            return [];
        }
        $rows = [];
        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            if ($row === [null]) {
                continue; // blank line
            }
            $rows[] = array_map(fn ($cell) => (string) $cell, $row);
        }
        fclose($handle);
        if (isset($rows[0][0]) && str_starts_with($rows[0][0], "\u{FEFF}")) {
            $rows[0][0] = substr($rows[0][0], 3);
        }

        return $rows;
    }

    /**
     * Where a link or image source in $fromDirectory points.
     *
     * @return array{type: 'node', key: string}|array{type: 'asset', path: string}|array{type: 'missing', path: string}|array{type: 'external'}
     */
    public function resolve(string $fromDirectory, string $href): array
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, '/')) {
            return ['type' => 'external'];
        }
        if (preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', $href)) {
            // a link to another page of the same export, written as a notion.so URL
            if (preg_match('#^https?://([a-z0-9-]+\.)*notion\.(so|site)/.*?([0-9a-f]{32})(?:[?\#].*)?$#i', $href, $m) && isset($this->nodes[strtolower($m[3])])) {
                return ['type' => 'node', 'key' => strtolower($m[3])];
            }

            return ['type' => 'external'];
        }

        $path = rawurldecode((string) preg_replace('/[?#].*$/s', '', $href));
        $normalized = self::normalize(($fromDirectory === '' ? '' : $fromDirectory.'/').$path);
        if ($normalized === null) {
            return ['type' => 'missing', 'path' => $path];
        }
        $normalized = self::nfc($normalized);
        if (isset($this->byPath[$normalized])) {
            return ['type' => 'node', 'key' => $this->byPath[$normalized]];
        }
        if (isset($this->assets[$normalized])) {
            return ['type' => 'asset', 'path' => $this->assets[$normalized]];
        }
        if (isset($this->byDirectory[$normalized])) {
            return ['type' => 'node', 'key' => $this->byDirectory[$normalized]];
        }
        // the path changed (long names get shortened) but the id still identifies the page
        if (preg_match('/([0-9a-f]{32})(?:_all)?\.(md|csv)$/i', $normalized, $m) && isset($this->nodes[strtolower($m[1])])) {
            return ['type' => 'node', 'key' => strtolower($m[1])];
        }

        return ['type' => 'missing', 'path' => $normalized];
    }

    /** Depth-first order: a parent always comes before its children. */
    public function ordered(): array
    {
        $out = [];
        $walk = function (array $keys) use (&$walk, &$out) {
            foreach ($keys as $key) {
                $out[] = $this->nodes[$key];
                $walk($this->nodes[$key]->children);
            }
        };
        $walk($this->roots);

        return $out;
    }

    /** Zip tools and file systems differ in Unicode normalization (é vs e + ◌́); compare paths in NFC. */
    private static function nfc(string $path): string
    {
        return class_exists(\Normalizer::class) ? (\Normalizer::normalize($path, \Normalizer::FORM_C) ?: $path) : $path;
    }

    /** "a/./b/../c" → "a/c"; null when it leaves the archive. */
    private static function normalize(string $path): ?string
    {
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($parts === []) {
                    return null;
                }
                array_pop($parts);

                continue;
            }
            $parts[] = $part;
        }

        return $parts === [] ? null : implode('/', $parts);
    }

    /**
     * Notion's large exports: the outer ZIP holds only `…-Part-N.zip` files. Unpack those into the
     * same tree (one level; a ZIP inside a page folder is an attachment and stays a file).
     *
     * @param  list<string>  $files
     * @return list<string>
     */
    private static function unpackNested(string $root, array $files): array
    {
        $zips = array_values(array_filter($files, fn ($f) => ! str_contains($f, '/') && str_ends_with(strtolower($f), '.zip')));
        $documents = array_filter($files, fn ($f) => preg_match('/\.(md|csv)$/i', $f));
        if ($zips === [] || $documents !== []) {
            return $files;
        }

        $files = array_values(array_diff($files, $zips));
        $used = 0;
        foreach ($files as $file) {
            $used += (int) filesize($root.'/'.$file);
        }
        foreach ($zips as $zip) {
            $remainingEntries = self::LIMITS['max_entries'] - count($files);
            if ($remainingEntries <= 0) {
                throw new ImportFailed('The archive has more than '.self::LIMITS['max_entries'].' files.');
            }
            $nested = SafeZip::extract($root.'/'.$zip, $root, [
                'max_entries' => $remainingEntries,
                'max_total_bytes' => max(1, self::LIMITS['max_total_bytes'] - $used),
            ]);
            foreach ($nested as $file) {
                $used += (int) filesize($root.'/'.$file);
            }
            unlink($root.'/'.$zip);
            $files = [...$files, ...$nested];
        }

        return $files;
    }

    /** @param list<string> $files */
    private function build(array $files): void
    {
        foreach ($files as $file) {
            $base = basename($file);
            if (str_starts_with($file, '__MACOSX/') || str_starts_with($base, '._') || $base === '.DS_Store') {
                continue;
            }
            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $stem = pathinfo($base, PATHINFO_FILENAME);
            if ($extension === 'md') {
                $this->addDocument($file, $stem, false);
            } elseif ($extension === 'csv' && self::parseName(preg_replace('/_all$/', '', $stem))[1] !== null) {
                $this->addDocument($file, preg_replace('/_all$/', '', $stem), true);
            } else {
                $this->assets[self::nfc($file)] = $file;
            }
        }

        if ($this->nodes === []) {
            throw new ImportFailed('This ZIP doesn’t look like a Notion export: it has no Markdown pages or CSV databases. In Notion, choose Export → "Markdown & CSV".');
        }

        // folders: every folder above a page or database
        $directories = [];
        foreach ($this->nodes as $node) {
            $dir = $node->home;
            while ($dir !== '' && ! isset($directories[$dir])) {
                $directories[$dir] = true;
                $dir = self::dirname($dir);
            }
        }
        ksort($directories, SORT_STRING); // parents before children
        foreach (array_keys($directories) as $dir) {
            [$title, $id] = self::parseName(basename($dir));
            $home = self::dirname($dir);
            if ($id !== null && isset($this->nodes[$id]) && $this->nodes[$id]->home === $home && $this->nodes[$id]->directory === null) {
                $this->nodes[$id]->directory = $dir;
                $this->byDirectory[self::nfc($dir)] = $id;

                continue;
            }
            if (preg_match('/^Export-[0-9a-f-]{8,}(-Part-\d+)?$/i', basename($dir)) && $home === '') {
                continue; // the export's own wrapper folder: not part of the tree
            }
            $key = $id !== null && ! isset($this->nodes[$id]) ? $id : 'd'.substr(sha1($dir), 0, 32);
            $folder = new NotionNode($key, $title);
            $folder->directory = $dir;
            $folder->home = $home;
            $this->nodes[$key] = $folder;
            $this->byDirectory[self::nfc($dir)] = $key;
        }

        if (count($this->nodes) > self::MAX_ENTRIES) {
            throw new ImportFailed('The export has more than '.self::MAX_ENTRIES.' pages, databases and folders. Export a smaller part of the workspace.');
        }

        foreach ($this->nodes as $node) {
            if ($node->markdown !== null && filesize($this->path($node->markdown)) > self::MAX_MARKDOWN_BYTES) {
                throw new ImportFailed('“'.$node->title.'” has more than '.intdiv(self::MAX_MARKDOWN_BYTES, 1024).' KB of text. Split it into smaller pages in Notion and export again.');
            }
            if ($node->csv !== null && filesize($this->path($node->csv)) > self::MAX_CSV_BYTES) {
                throw new ImportFailed('The database “'.$node->title.'” is larger than '.intdiv(self::MAX_CSV_BYTES, 1024 * 1024).' MB.');
            }
        }

        // parents
        foreach ($this->nodes as $node) {
            $dir = $node->home;
            while ($dir !== '' && ! isset($this->byDirectory[self::nfc($dir)])) {
                $dir = self::dirname($dir); // a skipped wrapper folder
            }
            $parent = $dir === '' ? null : $this->byDirectory[self::nfc($dir)];
            $node->parent = $parent === $node->key ? null : $parent;
            if ($node->parent === null) {
                $this->roots[] = $node->key;
            } else {
                $this->nodes[$node->parent]->children[] = $node->key;
            }
        }

        // order: as linked in the parent's page (Notion lists subpages there), then by title
        $this->roots = $this->sorted($this->roots, []);
        foreach ($this->nodes as $node) {
            if ($node->children !== []) {
                $node->children = $this->sorted($node->children, $this->linkOrder($node));
            }
        }
    }

    private function addDocument(string $file, string $stem, bool $database): void
    {
        [$title, $id] = self::parseName($stem);
        $key = $id ?? 'p'.substr(sha1($file), 0, 32);
        $node = $this->nodes[$key] ?? null;
        $home = self::dirname($file);
        if ($node !== null && $node->home !== $home) {
            $key = 'p'.substr(sha1($file), 0, 32); // the same id twice in different places: keep both
            $node = null;
        }
        $node ??= $this->nodes[$key] = new NotionNode($key, mb_substr($title, 0, 255));
        $node->home = $home;
        if ($database) {
            if ($node->csv === null || str_ends_with(pathinfo($file, PATHINFO_FILENAME), '_all')) {
                $node->csv = $file;
            }
        } else {
            $node->markdown ??= $file;
        }
        $this->byPath[self::nfc($file)] = $key;
    }

    /** @return array<string, int> child key → position of its first link in the page */
    private function linkOrder(NotionNode $node): array
    {
        if ($node->csv !== null) {
            // a database's row pages: in the order of the rows (first column = title)
            $byTitle = [];
            foreach ($node->children as $key) {
                $byTitle[mb_strtolower($this->nodes[$key]->title)] ??= $key;
            }
            $order = [];
            foreach (array_slice($this->rows($node), 1) as $row) {
                $key = $byTitle[mb_strtolower(trim($row[0] ?? ''))] ?? null;
                if ($key !== null && ! isset($order[$key])) {
                    $order[$key] = count($order);
                }
            }

            return $order;
        }
        if ($node->markdown === null) {
            return [];
        }
        preg_match_all('/\]\(([^)\s]+)\)|href="([^"]+)"/', $this->body($node), $matches, PREG_SET_ORDER);
        $order = [];
        foreach ($matches as $match) {
            $target = $this->resolve($node->baseDirectory(), $match[1] !== '' ? $match[1] : ($match[2] ?? ''));
            if ($target['type'] === 'node' && ! isset($order[$target['key']])) {
                $order[$target['key']] = count($order);
            }
        }

        return $order;
    }

    /**
     * @param  list<string>  $keys
     * @param  array<string, int>  $order
     * @return list<string>
     */
    private function sorted(array $keys, array $order): array
    {
        usort($keys, fn (string $a, string $b) => [$order[$a] ?? PHP_INT_MAX, mb_strtolower($this->nodes[$a]->title), $a]
            <=> [$order[$b] ?? PHP_INT_MAX, mb_strtolower($this->nodes[$b]->title), $b]);

        return $keys;
    }
}
