<?php

namespace App\Support\ApiKeys;

/**
 * Where a resource of the App API lives, for the scope check of a personal API key: its workspace, and its
 * project and board when it has them. `workspaceWide` marks workspace-level content (snippets, bookmarks,
 * workspace members, insights) that only keys covering whole workspaces reach. `label` names the place in a
 * 403 ("project ToolExpert") — only ever shown to a user who can open it.
 */
final class ApiKeyLocation
{
    public function __construct(
        public readonly int $workspaceId,
        public readonly ?int $projectId = null,
        public readonly ?int $boardId = null,
        public readonly bool $workspaceWide = false,
    ) {}
}
