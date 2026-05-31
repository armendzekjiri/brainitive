<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\CreateBoardItemTool;
use App\Mcp\Tools\CreateBookmarkItemTool;
use App\Mcp\Tools\CreatePageTool;
use App\Mcp\Tools\CreateSnippetTool;
use App\Mcp\Tools\CreateTaskTool;
use App\Mcp\Tools\FetchTool;
use App\Mcp\Tools\GetBoardTool;
use App\Mcp\Tools\GetPageTool;
use App\Mcp\Tools\GetProjectTool;
use App\Mcp\Tools\GetTaskListTool;
use App\Mcp\Tools\ListBoardsTool;
use App\Mcp\Tools\ListBookmarksTool;
use App\Mcp\Tools\ListPagesTool;
use App\Mcp\Tools\ListProjectsTool;
use App\Mcp\Tools\ListSnippetsTool;
use App\Mcp\Tools\ListTaskListsTool;
use App\Mcp\Tools\ListWorkspacesTool;
use App\Mcp\Tools\MoveBoardItemTool;
use App\Mcp\Tools\SearchTool;
use App\Mcp\Tools\UpdatePageTool;
use App\Mcp\Tools\UpdateTaskTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Brainitive')]
#[Version('1.0.0')]
#[Instructions(<<<'TXT'
Brainitive is a workspace for notes, tasks and projects. Content is organised
as a hierarchy: WORKSPACE → PROJECT → content (pages/documents, boards, task
lists), plus workspace-level snippets and bookmarks.

How to navigate:
1. Call list_workspaces to see the workspaces the user belongs to (use the slug).
2. Call list_projects with a workspace slug to see its projects.
3. Use get_project, list_pages, list_boards, list_task_lists, list_snippets or
   list_bookmarks to explore, then the matching get_/read tool for full content.
   Use the `search` tool for a fast cross-content lookup.

Identifiers: workspaces/projects/boards/task lists/snippet collections/bookmarks
are addressed by their `slug`; pages by their `uuid`; tasks and board items by
numeric id (always read the parent first to get current ids).

Writing: page bodies are Markdown (converted automatically). Every create/update
tool returns a `location` object (workspace, project, breadcrumb, url) — always
tell the user WHERE the content was created and share the url. You can only see
and change what the signed-in user has access to.
TXT)]
class BrainitiveServer extends Server
{
    protected array $tools = [
        // Navigation (read-only)
        ListWorkspacesTool::class,
        ListProjectsTool::class,
        GetProjectTool::class,
        // Cross-content search (ChatGPT deep-research contract)
        SearchTool::class,
        FetchTool::class,
        // Pages / documents
        ListPagesTool::class,
        GetPageTool::class,
        CreatePageTool::class,
        UpdatePageTool::class,
        // Task lists
        ListTaskListsTool::class,
        GetTaskListTool::class,
        CreateTaskTool::class,
        UpdateTaskTool::class,
        // Boards
        ListBoardsTool::class,
        GetBoardTool::class,
        CreateBoardItemTool::class,
        MoveBoardItemTool::class,
        // Snippets
        ListSnippetsTool::class,
        CreateSnippetTool::class,
        // Bookmarks
        ListBookmarksTool::class,
        CreateBookmarkItemTool::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];
}
