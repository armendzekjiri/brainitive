<?php

namespace App\Support\Imports\Notion;

/**
 * One entry of a Notion export's tree (BRAI-66): a Markdown page, a CSV database, or a plain folder.
 * `key` is the Notion id (32 hex) when the name has one, else a stable hash of the path; it is the
 * ImportMappings source id.
 */
final class NotionNode
{
    /** Markdown file (relative to the extraction root) */
    public ?string $markdown = null;

    /** CSV of a database (the `_all.csv` variant when both exist) */
    public ?string $csv = null;

    /** The folder holding this entry's subpages and files */
    public ?string $directory = null;

    /** The folder the entry itself lives in ('' = root) */
    public string $home = '';

    public ?string $parent = null;

    /** @var list<string> child keys, in display order */
    public array $children = [];

    public function __construct(public readonly string $key, public string $title) {}

    /** page | database | folder */
    public function kind(): string
    {
        return match (true) {
            $this->csv !== null => 'database',
            $this->markdown !== null => 'page',
            default => 'folder',
        };
    }

    /** The folder relative links in this entry's own content start from. */
    public function baseDirectory(): string
    {
        $file = $this->markdown ?? $this->csv;

        return $file === null ? ($this->directory ?? '') : NotionArchive::dirname($file);
    }
}
