<?php

namespace App\Http\Resources\AppApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * A cursor-paginated list of the App API v1: `{data: [...], next_cursor}` instead of Laravel's
 * `links` / `meta` (docs/ai/API.md → Lists). Each list has a subclass that sets `$collects`, so the
 * OpenAPI spec knows the item type. Build it from `$query->cursorPaginate(...)` ordered by a unique key.
 */
abstract class CursorCollection extends ResourceCollection
{
    /**
     * @param  array<string, mixed>  $paginated
     * @param  array<string, mixed>  $default
     * @return array{next_cursor: string|null}
     */
    public function paginationInformation(Request $request, array $paginated, array $default): array
    {
        // pass it back as ?cursor= for the next page; null on the last page
        return ['next_cursor' => $paginated['next_cursor'] ?? null];
    }
}
