<?php

namespace App\Http\Resources\AppApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A GitHub branch, commit, pull request or deployment linked to an issue (ItemDevelopment::for), read-only.
 *
 * @property-read array{id: int, repository: string|null, identifier: string|null, title: string|null, url: string|null, state: string|null, author: string|null, occurred_at: string|null, primary?: bool} $resource
 */
class DevLinkResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $link = $this->resource;

        return [
            'id' => (int) $link['id'],
            // owner/name
            'repository' => self::text($link['repository'] ?? null),
            // branch name, commit sha, PR number, environment
            'identifier' => self::text($link['identifier'] ?? null),
            'title' => self::text($link['title'] ?? null),
            'url' => self::text($link['url'] ?? null),
            // e.g. open | merged | closed | draft, success | failure
            'state' => self::text($link['state'] ?? null),
            'author' => self::text($link['author'] ?? null),
            'occurred_at' => self::text($link['occurred_at'] ?? null),
            // pull requests: the one the board shows first
            'primary' => (bool) ($link['primary'] ?? false),
        ];
    }

    private static function text(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
