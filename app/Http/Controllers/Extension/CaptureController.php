<?php

namespace App\Http\Controllers\Extension;

use App\Http\Controllers\Controller;
use App\Jobs\FetchLinkMetadataJob;
use App\Models\ActivityLog;
use App\Models\Board;
use App\Models\Bookmark;
use App\Models\Item;
use App\Models\Page;
use App\Models\Project;
use App\Models\TaskList;
use App\Support\Boards\ProjectIssues;
use App\Support\Mcp\PageContentConverter;
use App\Support\Mcp\ResourceLocator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Write endpoints for the browser extension. Same policies as the app; Markdown from the
 * extension is converted to the editors' formats here. Every response carries a `location`.
 */
class CaptureController extends Controller
{
    /**
     * `bookmarkGroup`, not `bookmark`: that parameter has a slug binding in AppServiceProvider.
     */
    public function bookmarkItem(Request $request, Bookmark $bookmarkGroup): JsonResponse
    {
        $bookmark = $bookmarkGroup;
        $this->authorize('update', $bookmark);

        $validated = $request->validate([
            'type' => 'required|in:link,note',
            'title' => 'nullable|string|max:500',
            'url' => 'nullable|required_if:type,link|url:http,https|max:2048',
            'content' => 'nullable|string|max:10000',
        ]);

        $item = $bookmark->items()->create([
            'type' => $validated['type'],
            'title' => $validated['title'] ?? null,
            'url' => $validated['url'] ?? null,
            'content' => $validated['content'] ?? null,
            'position' => ($bookmark->items()->max('position') ?? 0) + 1,
            'created_by' => $request->user()->id,
        ]);

        if ($item->type === 'link' && $item->url) {
            FetchLinkMetadataJob::dispatch($item->id);
        }

        return response()->json([
            'created' => true,
            'item_id' => $item->id,
            'location' => ResourceLocator::locate($item),
        ], 201);
    }

    /**
     * New issue (Markdown description), optionally with a screenshot attached. Lands in
     * the chosen column — on Scrum boards in the backlog, like issues from plans and
     * whiteboards.
     */
    public function issue(Request $request, Board $board): JsonResponse
    {
        $this->authorize('createItems', $board);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:50000',
            'column_id' => ['nullable', 'integer', Rule::exists('board_columns', 'id')->where('board_id', $board->id)],
            'assign_to_me' => 'nullable|boolean',
            'screenshot' => 'nullable|image|mimes:png,jpg,jpeg,webp|max:10240',
        ]);

        $user = $request->user();
        $project = $board->project;

        try {
            $item = ProjectIssues::create($board, $project, $user, [[
                'title' => $validated['title'],
                'description' => ! empty($validated['description'])
                    ? PageContentConverter::markdownToHtml($validated['description'])
                    : null,
                'assigned_to' => $request->boolean('assign_to_me') ? $user->id : null,
            ]], isset($validated['column_id']) ? $board->columns()->find($validated['column_id']) : null)->first();
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // ProjectIssues loads a slim board (no project_id); the locator needs the full one.
        $item->setRelation('board', $board);

        if ($request->hasFile('screenshot')) {
            $this->attachScreenshot($item, $request->file('screenshot'), $user->id);
        }

        return response()->json([
            'created' => true,
            'item_id' => $item->id,
            'item_key' => $item->key,
            'location' => ResourceLocator::locate($item),
        ], 201);
    }

    /**
     * Clip to a doc. A draft only the author sees unless `publish` is set (pages workflow).
     */
    public function page(Request $request, Project $project): JsonResponse
    {
        abort_unless($request->user()->hasProjectAccess($project), 404);
        $this->authorize('create', [Page::class, $project]);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'markdown' => 'nullable|string|max:500000',
            'publish' => 'nullable|boolean',
        ]);

        $page = $project->allPages()->create([
            'name' => $validated['title'],
            'type' => 'page',
            'icon' => '🔖',
            'created_by' => $request->user()->id,
            'content' => ! empty($validated['markdown'])
                ? PageContentConverter::htmlToPageContent(PageContentConverter::markdownToHtml($validated['markdown']))
                : ['type' => 'lexical', 'html' => ''],
        ]);

        if ($request->boolean('publish')) {
            $page->publish($request->user());
        }

        return response()->json([
            'created' => true,
            'page_uuid' => $page->uuid,
            'status' => $page->status,
            'location' => ResourceLocator::locate($page),
        ], 201);
    }

    public function task(Request $request, TaskList $taskList): JsonResponse
    {
        $this->authorize('manageItems', $taskList);

        $validated = $request->validate([
            'title' => 'required|string|max:500',
            'notes' => 'nullable|string|max:10000',
            'due_date' => 'nullable|date_format:Y-m-d',
            'assign_to_me' => 'nullable|boolean',
        ]);

        $item = $taskList->items()->create([
            'title' => $validated['title'],
            'description' => ! empty($validated['notes'])
                ? ['type' => 'lexical', 'html' => PageContentConverter::markdownToHtml($validated['notes'])]
                : null,
            'status' => 'todo',
            'due_date' => $validated['due_date'] ?? null,
            'assignee_id' => $request->boolean('assign_to_me') ? $request->user()->id : null,
            'position' => ($taskList->items()->max('position') ?? 0) + 1,
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'created' => true,
            'task_id' => $item->id,
            'location' => ResourceLocator::locate($item),
        ], 201);
    }

    private function attachScreenshot(Item $item, UploadedFile $file, int $userId): void
    {
        $original = 'screenshot-'.now()->format('Y-m-d-His').'.'.($file->extension() ?: 'png');
        $filename = Str::uuid().'_'.$original;
        $path = $file->storeAs('attachments/'.$item->id, $filename, 'public');

        $item->attachments()->create([
            'user_id' => $userId,
            'filename' => $filename,
            'original_filename' => $original,
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
        ]);

        ActivityLog::log($item, 'attachment_added', null, ['name' => $original]);
    }
}
