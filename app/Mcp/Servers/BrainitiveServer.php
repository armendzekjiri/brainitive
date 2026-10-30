<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\AddItemCommentTool;
use App\Mcp\Tools\AddProjectMemberTool;
use App\Mcp\Tools\CaptureToInboxTool;
use App\Mcp\Tools\CompleteSprintTool;
use App\Mcp\Tools\CreateBoardColumnTool;
use App\Mcp\Tools\CreateBoardItemsTool;
use App\Mcp\Tools\CreateBoardItemTool;
use App\Mcp\Tools\CreateBoardTool;
use App\Mcp\Tools\CreateBookmarkItemTool;
use App\Mcp\Tools\CreateBookmarkTool;
use App\Mcp\Tools\CreateCalendarEventTool;
use App\Mcp\Tools\CreateGoogleTaskTool;
use App\Mcp\Tools\CreateItemFieldTool;
use App\Mcp\Tools\CreateItemTypeTool;
use App\Mcp\Tools\CreateLabelTool;
use App\Mcp\Tools\CreatePageTool;
use App\Mcp\Tools\CreatePriorityTool;
use App\Mcp\Tools\CreateProjectTool;
use App\Mcp\Tools\CreateSnippetCollectionTool;
use App\Mcp\Tools\CreateSnippetTool;
use App\Mcp\Tools\CreateSprintTool;
use App\Mcp\Tools\CreateTaskListTool;
use App\Mcp\Tools\CreateTaskTool;
use App\Mcp\Tools\CreateWorkspaceTool;
use App\Mcp\Tools\DeleteBoardColumnTool;
use App\Mcp\Tools\DeleteBoardItemTool;
use App\Mcp\Tools\DeleteCalendarEventTool;
use App\Mcp\Tools\DeleteLabelTool;
use App\Mcp\Tools\FetchTool;
use App\Mcp\Tools\GetBoardItemTool;
use App\Mcp\Tools\GetBoardTool;
use App\Mcp\Tools\GetPageTool;
use App\Mcp\Tools\GetPlanTool;
use App\Mcp\Tools\GetProjectConfigTool;
use App\Mcp\Tools\GetProjectTool;
use App\Mcp\Tools\GetSprintTool;
use App\Mcp\Tools\GetTaskListTool;
use App\Mcp\Tools\ListBoardsTool;
use App\Mcp\Tools\ListBookmarksTool;
use App\Mcp\Tools\ListCalendarConnectionsTool;
use App\Mcp\Tools\ListCalendarEventsTool;
use App\Mcp\Tools\ListGoogleTasksTool;
use App\Mcp\Tools\ListItemCommentsTool;
use App\Mcp\Tools\ListPagesTool;
use App\Mcp\Tools\ListPlansTool;
use App\Mcp\Tools\ListProjectMembersTool;
use App\Mcp\Tools\ListProjectsTool;
use App\Mcp\Tools\ListSnippetsTool;
use App\Mcp\Tools\ListSprintsTool;
use App\Mcp\Tools\ListTaskListsTool;
use App\Mcp\Tools\ListWorkspacesTool;
use App\Mcp\Tools\MoveBoardItemTool;
use App\Mcp\Tools\MoveItemsToSprintTool;
use App\Mcp\Tools\ReorderBoardColumnsTool;
use App\Mcp\Tools\SearchTool;
use App\Mcp\Tools\StartSprintTool;
use App\Mcp\Tools\UpdateBoardColumnTool;
use App\Mcp\Tools\UpdateBoardItemTool;
use App\Mcp\Tools\UpdateBoardTool;
use App\Mcp\Tools\UpdateCalendarEventTool;
use App\Mcp\Tools\UpdateGoogleTaskTool;
use App\Mcp\Tools\UpdateItemFieldTool;
use App\Mcp\Tools\UpdateItemTypeTool;
use App\Mcp\Tools\UpdateLabelTool;
use App\Mcp\Tools\UpdatePageTool;
use App\Mcp\Tools\UpdatePlanTool;
use App\Mcp\Tools\UpdatePriorityTool;
use App\Mcp\Tools\UpdateProjectTool;
use App\Mcp\Tools\UpdateSnippetTool;
use App\Mcp\Tools\UpdateSprintTool;
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

Scrum sprints: list_sprints / get_sprint (sprint_id 0 = backlog) to read; create_sprint,
update_sprint, move_items_to_sprint, start_sprint (explicit dates) and complete_sprint
(explicit move_open_to: 0 = backlog or a planned sprint) to plan — the same rules as the
board's backlog page. Confirm with the user before starting or completing a sprint.

Gantt plans: list_plans / get_plan to read (rows, bars, dependencies, linked issues);
update_plan applies a list of operations against expected_revision — on a revision
conflict nothing changes: read the plan again before trying anything else.

Setting up or migrating a project (e.g. from a Jira / Trello export the user gives you):
1. create_workspace if needed, then create_project (key + columns = the statuses, in order,
   last one is_done) — or get_project_config on an existing project to see what is there.
2. Create what the cards will reference: create_label, create_item_type (Epic, Story, Bug…),
   create_priority only when the five built-in ones don't match, create_item_field for every
   extra column of the export (select fields take their options), add_project_member for the
   people. All of these are safe to call again with the same name.
3. Show the user the mapping (source status → column, type → type, field → field, assignee →
   member) and get a go-ahead.
4. create_board_items in batches of up to 100 with a fresh request_id per batch; pass the
   source key as external_key and use parent_key / parent_external_key for sub-tasks. One bad
   name fails the batch with the valid values and nothing is created: fix and retry with a new
   request_id. A retried request_id returns the stored result, so a timeout never duplicates.
5. Finish with get_project_config and the board url for the user.
Column changes: create_board_column, update_board_column, reorder_board_columns,
delete_board_column (empty columns only).

Card comments: list_item_comments reads a card's discussion; add_item_comment posts
an update as the signed-in user without touching the description. Write tools that take
a request_id are safe to retry: reuse the same request_id only for the same write.

Writing: page bodies are Markdown (converted automatically). Every create/update
tool returns a `location` object (workspace, project, breadcrumb, url) — always
tell the user WHERE the content was created and share the url. You can only see
and change what the signed-in user has access to.

Calendar / planning the week: the user can connect calendars (Google Calendar
and other providers) and Google Tasks (personal, not workspace-scoped). Call
list_calendar_connections first to get connection_ids, then list_calendar_events
to read their schedule and create_calendar_event / update_calendar_event to add
or move time blocks (only on calendars with writable = true). Use the Google
Tasks tools (list/create/update) for to-dos. Always pass the user's IANA
timezone (e.g. Europe/Zurich) on writes so times land correctly, and confirm
before deleting. If list_calendar_connections is empty, tell the user to
connect a calendar under Calendar in Brainitive.
TXT)]
class BrainitiveServer extends Server
{
    // Return every tool in a single tools/list page. Some MCP clients (e.g.
    // ChatGPT connectors) don't follow the `nextCursor` pagination, so a tool
    // on page 2 (default page size 15) would be invisible to them — which made
    // write tools like create_board_item look "missing". Lift the limits well
    // above the tool count so the whole catalogue is returned at once.
    public int $defaultPaginationLength = 200;

    public int $maxPaginationLength = 200;

    protected array $tools = [
        // Navigation (read-only)
        ListWorkspacesTool::class,
        ListProjectsTool::class,
        GetProjectTool::class,
        GetProjectConfigTool::class,
        ListProjectMembersTool::class,
        // Setting up a workspace / project (v1.47.0): structure first, then cards in bulk
        CreateWorkspaceTool::class,
        CreateProjectTool::class,
        UpdateProjectTool::class,
        AddProjectMemberTool::class,
        CreateBoardTool::class,
        UpdateBoardTool::class,
        CreateBoardColumnTool::class,
        UpdateBoardColumnTool::class,
        DeleteBoardColumnTool::class,
        ReorderBoardColumnsTool::class,
        CreateLabelTool::class,
        UpdateLabelTool::class,
        DeleteLabelTool::class,
        CreateItemTypeTool::class,
        UpdateItemTypeTool::class,
        CreatePriorityTool::class,
        UpdatePriorityTool::class,
        CreateItemFieldTool::class,
        UpdateItemFieldTool::class,
        CreateTaskListTool::class,
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
        GetBoardItemTool::class,
        CreateBoardItemTool::class,
        CreateBoardItemsTool::class,
        UpdateBoardItemTool::class,
        MoveBoardItemTool::class,
        DeleteBoardItemTool::class,
        ListItemCommentsTool::class,
        AddItemCommentTool::class,
        ListSprintsTool::class,
        GetSprintTool::class,
        CreateSprintTool::class,
        UpdateSprintTool::class,
        MoveItemsToSprintTool::class,
        StartSprintTool::class,
        CompleteSprintTool::class,
        ListPlansTool::class,
        GetPlanTool::class,
        UpdatePlanTool::class,
        // Snippets
        ListSnippetsTool::class,
        CreateSnippetCollectionTool::class,
        CreateSnippetTool::class,
        UpdateSnippetTool::class,
        // Bookmarks
        ListBookmarksTool::class,
        CreateBookmarkTool::class,
        CreateBookmarkItemTool::class,
        // Personal capture inbox (quick notes to sort later)
        CaptureToInboxTool::class,
        // Calendars (any provider) + Google Tasks (personal; for planning the week)
        ListCalendarConnectionsTool::class,
        ListCalendarEventsTool::class,
        CreateCalendarEventTool::class,
        UpdateCalendarEventTool::class,
        DeleteCalendarEventTool::class,
        ListGoogleTasksTool::class,
        CreateGoogleTaskTool::class,
        UpdateGoogleTaskTool::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];
}
