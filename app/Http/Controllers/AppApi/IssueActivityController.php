<?php

namespace App\Http\Controllers\AppApi;

use App\Http\Controllers\AppApi\Concerns\CreatesOnce;
use App\Http\Controllers\AppApi\Concerns\ResolvesVisible;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppApi\ListActivityRequest;
use App\Http\Requests\AppApi\ListRequest;
use App\Http\Requests\AppApi\StoreAttachmentRequest;
use App\Http\Requests\AppApi\StoreCommentRequest;
use App\Http\Resources\AppApi\ActivityCollection;
use App\Http\Resources\AppApi\AttachmentCollection;
use App\Http\Resources\AppApi\AttachmentResource;
use App\Http\Resources\AppApi\CommentCollection;
use App\Http\Resources\AppApi\CommentResource;
use App\Http\Resources\AppApi\WatchersResource;
use App\Models\Attachment;
use App\Models\Comment;
use App\Support\Boards\IssueMarkdown;
use App\Support\Boards\ItemAttachments;
use App\Support\Boards\ItemComments;
use App\Support\Entitlements\LimitReached;
use App\Support\Files\FileRoom;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * App API v1 — an issue's comments, watchers, attachments and history (BRAI-405). The issue dialog's rules:
 * everybody who can open the issue reads and (un)watches it themselves; the people who may edit it comment
 * and add / remove files (ItemPolicy::update). Same code (ItemComments: history, mentions, watcher
 * notifications, webhooks; ItemAttachments), so open issue views on the web update live.
 */
#[Group('Issues', weight: 210)]
class IssueActivityController extends Controller
{
    use CreatesOnce;
    use ResolvesVisible;

    /**
     * The comments of an issue, oldest first; replies carry `parent_id`.
     *
     * @throws NotFoundHttpException the issue doesn't exist or the user can't open it (404)
     */
    public function comments(ListRequest $request, string $item): CommentCollection
    {
        $item = $this->visibleItem($request, $item);
        $since = $request->updatedSince();

        $page = $item->comments()->getQuery()
            ->with('user')
            ->when($since, fn ($q) => $q->where('updated_at', '>=', $since))
            ->reorder()
            ->orderBy('created_at')
            ->orderBy('id')
            ->cursorPaginate($request->limit(), ['*'], 'cursor', $request->cursor());

        return new CommentCollection($page);
    }

    /**
     * Comment on an issue (or reply to one of its comments).
     *
     * `body` is Markdown; a mention `@[Name](user:<id>)` notifies that project member, the watchers, assignee
     * and reporter get "commented on", as on the web. Needs `can.comment` of the issue (403). With
     * `request_id` a retry answers 200 with the comment the first call posted.
     *
     * @throws NotFoundHttpException the issue doesn't exist or the user can't open it (404)
     */
    public function storeComment(StoreCommentRequest $request): JsonResponse
    {
        $item = $request->issue();
        $input = $request->safe()->only(['body', 'parent_id']);
        $html = (string) IssueMarkdown::toHtml($input['body'], $item->board->project);

        [$comment, $replayed] = $this->createOnce($request, 'items.comments.store', Comment::class, ['item' => $item->id] + $input,
            fn () => ItemComments::add($item, $request->user(), $html, isset($input['parent_id']) ? (int) $input['parent_id'] : null),
            fn (Comment $comment) => $comment->item !== null && $request->user()->can('view', $comment->item));

        $comment->load('user');
        if ($replayed) {
            return (new CommentResource($comment))->response();
        }

        return (new CommentResource($comment))->response()->setStatusCode(201);
    }

    /**
     * Who watches the issue, and whether the user does.
     *
     * @throws NotFoundHttpException the issue doesn't exist or the user can't open it (404)
     */
    public function watchers(Request $request, string $item): WatchersResource
    {
        return new WatchersResource($this->visibleItem($request, $item)->load('watchers'));
    }

    /**
     * Watch the issue (comments and status changes notify the user); answers the watchers.
     *
     * Anybody who can open the issue. Watching again changes nothing.
     *
     * @throws NotFoundHttpException the issue doesn't exist or the user can't open it (404)
     */
    public function watch(Request $request, string $item): WatchersResource
    {
        $item = $this->visibleItem($request, $item);
        $item->addWatcher($request->user());

        return new WatchersResource($item->load('watchers'));
    }

    /**
     * Stop watching the issue; answers the watchers (a part of the issue, so 200, not 204).
     *
     * @throws NotFoundHttpException the issue doesn't exist or the user can't open it (404)
     */
    public function unwatch(Request $request, string $item): WatchersResource
    {
        $item = $this->visibleItem($request, $item);
        $item->removeWatcher($request->user());

        return new WatchersResource($item->load('watchers'));
    }

    /**
     * The files on an issue, newest first.
     *
     * @throws NotFoundHttpException the issue doesn't exist or the user can't open it (404)
     */
    public function attachments(ListRequest $request, string $item): AttachmentCollection
    {
        $item = $this->visibleItem($request, $item);
        $since = $request->updatedSince();

        $page = Attachment::query()
            ->where('item_id', $item->id)
            ->with('user')
            ->when($since, fn ($q) => $q->where('updated_at', '>=', $since))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate($request->limit(), ['*'], 'cursor', $request->cursor());

        return new AttachmentCollection($page);
    }

    /**
     * Add a file to an issue (multipart `file`, ≤ 10 MB).
     *
     * Needs `can.update` of the issue (403). With `request_id` a retry answers 200 with the file the first
     * call stored.
     *
     * @throws NotFoundHttpException the issue doesn't exist or the user can't open it (404)
     * @throws LimitReached the workspace's file storage is full (402 limit_reached)
     */
    public function storeAttachment(StoreAttachmentRequest $request): JsonResponse
    {
        $item = $request->issue();
        $file = $request->file('file');
        $payload = ['item' => $item->id, 'name' => $file->getClientOriginalName(), 'size' => $file->getSize(), 'hash' => hash_file('sha256', $file->getRealPath())];

        [$attachment, $replayed] = $this->createOnce($request, 'items.attachments.store', Attachment::class, $payload,
            function () use ($item, $request, $file) {
                // the plan's file storage, as the web's `file.room` (BRAI-416); a replay stores nothing
                FileRoom::ensure($item->board->project->workspace, $file);

                return ItemAttachments::add($item, $request->user(), $file);
            },
            fn (Attachment $attachment) => $attachment->item !== null && $request->user()->can('view', $attachment->item));

        $attachment->load('user');
        if ($replayed) {
            return (new AttachmentResource($attachment))->response();
        }

        return (new AttachmentResource($attachment))->response()->setStatusCode(201);
    }

    /**
     * Remove a file from an issue.
     *
     * Needs `can.update` of the issue (403).
     *
     * @throws NotFoundHttpException the issue or the file doesn't exist, or the user can't open it (404)
     */
    public function destroyAttachment(Request $request, string $item, string $attachment): Response
    {
        $item = $this->visibleItem($request, $item);
        $file = preg_match('/^[1-9][0-9]{0,17}$/', $attachment) ? Attachment::where('item_id', $item->id)->find((int) $attachment) : null;
        abort_unless($file instanceof Attachment, 404);
        $this->authorize('update', $item);

        ItemAttachments::remove($item, $file);

        return response()->noContent();
    }

    /**
     * The history of an issue, newest first (what the issue dialog's History tab shows).
     *
     * @throws NotFoundHttpException the issue doesn't exist or the user can't open it (404)
     */
    public function activity(ListActivityRequest $request, string $item): ActivityCollection
    {
        $item = $this->visibleItem($request, $item);

        $page = $item->activities()
            ->with('user')
            ->cursorPaginate($request->limit(), ['*'], 'cursor', $request->cursor());

        return new ActivityCollection($page);
    }
}
