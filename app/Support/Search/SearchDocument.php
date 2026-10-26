<?php

namespace App\Support\Search;

use Carbon\CarbonInterface;

/**
 * One indexable record, as every search engine receives it (BRAI-55). Visibility travels with the
 * document — project, workspace, owner, draft author — so the engine can filter in the query.
 */
final class SearchDocument
{
    public function __construct(
        public readonly string $type,
        public readonly int $sourceId,
        public readonly string $title,
        public readonly ?int $projectId = null,
        public readonly ?int $workspaceId = null,
        public readonly ?int $containerId = null,
        public readonly ?int $ownerId = null,
        public readonly ?int $authorId = null,
        public readonly bool $isDraft = false,
        public readonly ?string $subtype = null,
        public readonly ?string $key = null,
        public readonly ?string $keywords = null,
        public readonly ?string $body = null,
        public readonly ?string $status = null,
        public readonly ?int $assigneeId = null,
        public readonly ?int $itemTypeId = null,
        public readonly ?CarbonInterface $updatedAt = null,
    ) {}

    /** @return array<string, mixed> the `search_entries` row */
    public function toRow(CarbonInterface $now): array
    {
        return [
            'type' => $this->type,
            'source_id' => $this->sourceId,
            'project_id' => $this->projectId,
            'workspace_id' => $this->workspaceId,
            'container_id' => $this->containerId,
            'owner_id' => $this->ownerId,
            'author_id' => $this->authorId,
            'is_draft' => $this->isDraft,
            'subtype' => $this->subtype,
            'key' => $this->key === null ? null : mb_substr($this->key, 0, 64),
            'title' => SearchText::clip($this->title, SearchText::TITLE_LIMIT),
            'keywords' => $this->keywords === null ? null : SearchText::clip($this->keywords, SearchText::KEYWORDS_LIMIT),
            'body' => $this->body === null ? null : SearchText::clip($this->body, SearchText::BODY_LIMIT),
            'status' => $this->status,
            'assignee_id' => $this->assigneeId,
            'item_type_id' => $this->itemTypeId,
            'source_updated_at' => $this->updatedAt,
            'indexed_at' => $now,
        ];
    }
}
