<?php

namespace App\Support\Validation;

use App\Rules\EditorDocument;
use App\Rules\JsonSize;

/**
 * The one place for maximum lengths and shapes of user content (Codex web review D / J, v1.49.0). Generous on
 * purpose — they stop abuse (a 500 MB description, a 100 000-option field), not real work. Web Form Requests, the
 * App API, MCP tools, the CLI and offline sync all use these constants, so a value valid in one is valid in all.
 *
 * Titles and names keep the limits the web already had (255; tasks 500). Page bodies keep the live editor's
 * existing 10 MB (`pages.collab.save`), which other page writers now share.
 */
final class ContentLimits
{
    /** Issue descriptions, snippet bodies, task descriptions (characters of Markdown / HTML / JSON). */
    public const BODY = 1_000_000;

    /** Page bodies (docs): the live editor's existing limit, now also for drafts, publish and the APIs. */
    public const PAGE_BODY = 10_000_000;

    /** Legacy whiteboard scenes (`diagram_data`): the live whiteboard's existing 50 MB. */
    public const DIAGRAM = 50 * 1024 * 1024;

    /** Issue comments (web, App API, MCP, sync). */
    public const COMMENT = 100_000;

    /** Ticket descriptions (agent, public form, edits): the public form's existing limit. */
    public const TICKET_BODY = 200_000;

    /** Ticket replies (web, App API, the public portal): the existing public / App API limit. */
    public const TICKET_REPLY = 50_000;

    /** A custom ticket field: text / rich text answers (the public form's existing limits). */
    public const FIELD_TEXT = 10_000;

    public const FIELD_RICH_TEXT = 200_000;

    /** Choices of a select / radio / checkboxes field, and the length of one. */
    public const FIELD_OPTIONS = 200;

    public const FIELD_OPTION = 255;

    /** Ids in one reorder request (columns, fields, tickets, pages). */
    public const REORDER_IDS = 2_000;

    /** Nesting of an editor document (task descriptions). */
    public const DOCUMENT_DEPTH = 50;

    /** @return list<string> */
    public static function body(): array
    {
        return ['string', 'max:'.self::BODY];
    }

    /** @return list<string> */
    public static function comment(): array
    {
        return ['string', 'max:'.self::COMMENT];
    }

    /**
     * A page's content as the web editor sends it (an editor document, or its JSON string).
     *
     * @return list<mixed>
     */
    public static function pageContent(): array
    {
        return [new JsonSize(self::PAGE_BODY)];
    }

    /**
     * A task description: the rich-text format other descriptions use (`{type: lexical, html}`), or a legacy
     * TipTap document (`{type: doc, content: [...]}`) — never arbitrary JSON.
     *
     * @return list<mixed>
     */
    public static function taskDescription(): array
    {
        return ['nullable', 'array', new EditorDocument(self::BODY, self::DOCUMENT_DEPTH)];
    }

    /** Serialized size of any value in bytes (what JSON columns store). */
    public static function size(mixed $value): int
    {
        if ($value === null) {
            return 0;
        }

        return is_string($value) ? strlen($value) : strlen((string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
