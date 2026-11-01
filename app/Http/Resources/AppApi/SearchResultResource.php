<?php

namespace App\Http\Resources\AppApi;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One search hit (SearchPresenter, the same as the web's search page): what it is, where it lives
 * and its web link. `snippet_html` only ever contains `<mark>` around the matched words (everything
 * else is escaped); `snippet` is the same as plain text.
 *
 * @property-read array<string, mixed> $resource
 */
class SearchResultResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $hit = $this->resource;
        $project = is_array($hit['project'] ?? null) ? $hit['project'] : null;

        return [
            // "<type>:<id>", unique in a result list
            'id' => (string) $hit['id'],
            // item | comment | page | ticket | plan | task | snippet | bookmark | capture | attachment | ticket_attachment
            'type' => (string) $hit['type'],
            // the filter group (type=…): issues, docs, comments, tickets, tasks, plans, snippets, bookmarks, captures, files
            'group' => (string) $hit['group'],
            // "Issue", "Doc", "Comment" …
            'label' => (string) $hit['label'],
            'source_id' => (int) $hit['source_id'],
            // the issue / ticket of a comment or file
            'container_id' => isset($hit['container_id']) ? (int) $hit['container_id'] : null,
            'key' => isset($hit['key']) ? (string) $hit['key'] : null,
            'title' => (string) $hit['title'],
            'snippet' => html_entity_decode(strip_tags((string) ($hit['snippet_html'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'snippet_html' => (string) ($hit['snippet_html'] ?? ''),
            'url' => (string) $hit['url'],
            // a bookmark's own link
            'external_url' => isset($hit['external_url']) ? (string) $hit['external_url'] : null,
            // where it lives, e.g. "Website · Main Board"
            'context' => isset($hit['context']) ? (string) $hit['context'] : null,
            'project' => $project === null ? null : ['id' => (int) $project['id'], 'name' => (string) $project['name']],
            // the workspace's name
            'workspace' => isset($hit['workspace']) ? (string) $hit['workspace'] : null,
            // open | done, for issues, tickets, tasks, captures
            'status' => isset($hit['status']) ? (string) $hit['status'] : null,
            'is_draft' => (bool) ($hit['is_draft'] ?? false),
            'updated_at' => isset($hit['updated_at']) ? CarbonImmutable::parse((string) $hit['updated_at'])->utc()->toIso8601ZuluString() : null,
        ];
    }
}
