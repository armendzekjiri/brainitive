<?php

namespace App\Http\Controllers\Extension;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Board;
use App\Models\Bookmark;
use App\Models\Capture;
use App\Models\Item;
use App\Models\Project;
use App\Models\SnippetCollection;
use App\Models\TaskList;
use App\Support\Captures\ContentCapture;
use App\Support\Mcp\ResourceLocator;
use App\Support\Uploads;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Write endpoints for the browser extension. Same policies as the app; the create behaviour is
 * shared with the capture inbox (`ContentCapture`). Every response carries a `location`.
 */
class CaptureController extends Controller
{
    /**
     * `bookmarkGroup`, not `bookmark`: that parameter has a slug binding in AppServiceProvider.
     */
    public function bookmarkItem(Request $request, Bookmark $bookmarkGroup): JsonResponse
    {
        $item = $this->capture($request, 'bookmark', $bookmarkGroup);

        return response()->json([
            'created' => true,
            'item_id' => $item->id,
            'location' => ResourceLocator::locate($item),
        ], 201);
    }

    /**
     * New issue (Markdown description), optionally with a screenshot attached.
     */
    public function issue(Request $request, Board $board): JsonResponse
    {
        ContentCapture::authorize($request->user(), 'issue', $board);
        $request->validate(['screenshot' => 'nullable|image|mimes:png,jpg,jpeg,webp|max:10240']);

        try {
            $item = $this->capture($request, 'issue', $board);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($request->hasFile('screenshot')) {
            $this->attachScreenshot($item, $request->file('screenshot'), $request->user()->id);
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
        $page = $this->capture($request, 'page', $project);

        return response()->json([
            'created' => true,
            'page_uuid' => $page->uuid,
            'status' => $page->status,
            'location' => ResourceLocator::locate($page),
        ], 201);
    }

    public function task(Request $request, TaskList $taskList): JsonResponse
    {
        $item = $this->capture($request, 'task', $taskList);

        return response()->json([
            'created' => true,
            'task_id' => $item->id,
            'location' => ResourceLocator::locate($item),
        ], 201);
    }

    /**
     * Selected code / text from a page into a snippet collection (same rule as the app:
     * workspace editors and admins).
     */
    public function snippet(Request $request, SnippetCollection $snippetCollection): JsonResponse
    {
        $snippet = $this->capture($request, 'snippet', $snippetCollection);

        return response()->json([
            'created' => true,
            'snippet_slug' => $snippet->slug,
            'location' => ResourceLocator::locate($snippet),
        ], 201);
    }

    /**
     * "Save to inbox" (BRAI-70): an idea without a destination, organized later in the app.
     * Create only — an extension token can never read the inbox.
     */
    public function inbox(Request $request): JsonResponse
    {
        $validated = $request->validate(Capture::rules());

        $capture = Capture::create([
            'user_id' => $request->user()->id,
            'source' => ($validated['source'] ?? null) ?: 'Browser extension',
        ] + $validated);

        return response()->json([
            'created' => true,
            'capture_id' => $capture->uuid,
            'location' => [
                'type' => 'capture',
                'title' => Str::limit(strtok($capture->text, "\n") ?: $capture->text, 80),
                'workspace' => '',
                'project' => '',
                'breadcrumb' => 'Captures',
                'url' => route('captures.index'),
            ],
        ], 201);
    }

    private function capture(Request $request, string $type, Model $destination): Model
    {
        ContentCapture::authorize($request->user(), $type, $destination);
        $validated = $request->validate(ContentCapture::rules($type, $destination));

        return ContentCapture::create($request->user(), $type, $destination, $validated);
    }

    private function attachScreenshot(Item $item, UploadedFile $file, int $userId): void
    {
        $original = 'screenshot-'.now()->format('Y-m-d-His').'.'.Uploads::imageExtension($file);
        $filename = Str::uuid().'.'.Uploads::imageExtension($file);
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
