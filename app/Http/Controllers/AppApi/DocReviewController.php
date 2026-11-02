<?php

namespace App\Http\Controllers\AppApi;

use App\Http\Controllers\AppApi\Concerns\CreatesOnce;
use App\Http\Controllers\AppApi\Concerns\ResolvesPageReviews;
use App\Http\Controllers\AppApi\Concerns\ResolvesVisible;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppApi\ListRequest;
use App\Http\Requests\AppApi\ResolveThreadRequest;
use App\Http\Requests\AppApi\ReviewDecisionRequest;
use App\Http\Requests\AppApi\StoreReviewCommentRequest;
use App\Http\Resources\AppApi\PageLinksResource;
use App\Http\Resources\AppApi\PageReviewDetailResource;
use App\Http\Resources\AppApi\PageReviewResource;
use App\Http\Resources\AppApi\PageThreadResource;
use App\Http\Resources\AppApi\PageVersionCollection;
use App\Http\Resources\AppApi\PageVersionDetailResource;
use App\Models\Page;
use App\Models\PageComment;
use App\Models\PageReview;
use App\Models\User;
use App\Support\Boards\IssueMarkdown;
use App\Support\Mcp\ContentLinkFormatter;
use App\Support\PageReviews\PageReviews;
use App\Support\PageReviews\QuoteAnchor;
use App\Support\PageReviews\ReviewPresenter;
use App\Support\SafeHtml;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * App API v1 — a doc's history, reviews with their inline comment threads, and its links / backlinks (BRAI-413).
 * The web's code: PageVersion (history), Support\PageReviews\PageReviews + ReviewPresenter + PageReviewPolicy
 * (BRAI-80: reviewers see the review's snapshot, not an unpublished draft), ContentLinkFormatter (the MCP's
 * related content / referenced by). Requesting / cancelling reviews, "review required", restoring versions: web only.
 */
#[Group('Docs', weight: 230)]
class DocReviewController extends Controller
{
    use CreatesOnce;
    use ResolvesPageReviews;
    use ResolvesVisible;

    /**
     * A page's published versions, newest first.
     *
     * Every publish (and every 30-minute checkpoint of a whiteboard) is a version. Restoring one: web only.
     *
     * @throws NotFoundHttpException the page doesn't exist or the user can't see it (404)
     */
    public function versions(ListRequest $request, string $uuid): PageVersionCollection
    {
        $page = $this->visiblePage($request, $uuid);

        return new PageVersionCollection($page->versions()->getQuery()
            ->with('author')
            ->select(['id', 'page_id', 'version', 'name', 'icon', 'message', 'created_by', 'created_at'])
            ->when($request->updatedSince(), fn ($q, $since) => $q->where('created_at', '>=', $since))
            ->reorder()->orderByDesc('version')->orderByDesc('id')
            ->cursorPaginate($request->limit(), ['*'], 'cursor', $request->cursor()));
    }

    /**
     * One version with its text (docs: Markdown + sanitized HTML).
     *
     * @throws NotFoundHttpException the page or version doesn't exist or the user can't see it (404)
     */
    public function version(Request $request, string $uuid, int $version): PageVersionDetailResource
    {
        $page = $this->visiblePage($request, $uuid);

        return new PageVersionDetailResource($page->versions()->with('author')->where('version', $version)->firstOrFail());
    }

    /**
     * The reviews of a doc the user may see, newest first (not paginated).
     *
     * People who can edit the doc see all of them; a reviewer the ones they were asked for (even on a draft they
     * can't open otherwise). Nobody else → 404.
     *
     * @throws NotFoundHttpException the page doesn't exist or the user may see none of it (404)
     */
    public function reviews(Request $request, string $uuid): AnonymousResourceCollection
    {
        $page = $this->reviewedPage($request, $uuid);
        $user = $request->user();
        $reviews = PageReview::where('page_id', $page->id)->with('reviewers')->orderByDesc('id')->get()
            ->each(fn (PageReview $review) => $review->setRelation('page', $page))
            ->filter(fn (PageReview $review) => $user->can('view', $review));
        abort_if($reviews->isEmpty() && ! $user->can('view', $page), 404);

        return PageReviewResource::collection($reviews->map(function (PageReview $review) use ($page) {
            PageReviews::recompute($review, $page);

            return ReviewPresenter::summary($review, $page);
        })->values());
    }

    /**
     * One review: the reviewed snapshot (Markdown + sanitized HTML), the reviewers' decisions, the threads and
     * what the user may do.
     *
     * @throws NotFoundHttpException the page or review doesn't exist or the user can't see it (404)
     */
    public function review(Request $request, string $uuid, string $review): PageReviewDetailResource
    {
        $page = $this->reviewedPage($request, $uuid);

        return new PageReviewDetailResource(self::reviewDetail($this->visibleReview($request, $page, $review), $page, $request->user()));
    }

    /**
     * Approve or request changes.
     *
     * An active reviewer of an open review (403 otherwise). An approval of the current draft lets a
     * "review required" doc publish; the author is notified.
     *
     * @throws NotFoundHttpException the page or review doesn't exist or the user can't see it (404)
     */
    public function decide(ReviewDecisionRequest $request): PageReviewDetailResource
    {
        $review = $request->review();
        PageReviews::decide($review, $request->user(), (string) $request->validated('decision'), $request->validated('comment'));

        return new PageReviewDetailResource(self::reviewDetail($review->fresh(['reviewers'])->setRelation('page', $request->page()), $request->page(), $request->user()));
    }

    /**
     * Comment on a review: a new thread (optionally on a passage: `quote` + `prefix` / `suffix` of the snapshot's
     * text), or a reply (`parent_uuid`).
     *
     * New threads need an open review the user may see (403 otherwise); replies any review they may see. A quote
     * that isn't in the snapshot → 422 `quote`. Answers 201 with the thread. With `request_id` a retry answers 200.
     *
     * @throws NotFoundHttpException the page, review or thread doesn't exist or the user can't see it (404)
     */
    public function storeComment(StoreReviewCommentRequest $request): JsonResponse
    {
        $page = $request->page();
        $review = $request->review();
        $parent = $request->parentThread();
        $data = $request->validated();

        if ($parent === null && ! empty($data['quote']) && QuoteAnchor::isOutdated($review->snapshotHtml(), $data['quote'], $data['prefix'] ?? null, $data['suffix'] ?? null)) {
            throw ValidationException::withMessages(['quote' => 'The selected text isn\'t in this version of the page.']);
        }

        [$comment, $replayed] = $this->createOnce($request, 'pages.reviews.comments.store', PageComment::class, ['review' => $review->id] + $request->safe()->except(['request_id']),
            fn () => PageReviews::comment($review, $request->user(), [
                'body' => (string) $data['body'],
                'parent' => $parent,
                'quote' => $data['quote'] ?? null,
                'prefix' => $data['prefix'] ?? null,
                'suffix' => $data['suffix'] ?? null,
            ]),
            // as visibleThread(): a project the user can open and a review they may see
            fn (PageComment $comment) => $comment->page?->project !== null && $comment->review !== null
                && $request->user()->hasProjectAccess($comment->page->project) && $request->user()->can('view', $comment->review));

        $thread = self::thread($comment->parent_id === null ? $comment : $comment->parent()->firstOrFail(), $review, $page, $request->user());
        if ($replayed) {
            return (new PageThreadResource($thread))->response();
        }

        return (new PageThreadResource($thread))->response()->setStatusCode(201);
    }

    /**
     * Resolve or reopen a thread.
     *
     * Anyone who may see the thread's review, as on the web.
     *
     * @throws NotFoundHttpException the page or thread doesn't exist or the user can't see it (404)
     */
    public function resolveThread(ResolveThreadRequest $request): PageThreadResource
    {
        $thread = $request->thread();
        PageReviews::resolve($thread, $request->user(), $request->boolean('resolved'));
        $review = $thread->review;

        return new PageThreadResource(self::thread($thread, $review, $review->page, $request->user()));
    }

    /**
     * A page's links: what it links to (`related`) and its backlinks (`referenced_by`).
     *
     * Backlinks are other content of the project that links here: docs' smart links (published text), issues,
     * plan rows, whiteboard cards, and links people added. Only content the user can open is listed. Adding /
     * removing links: web (or the MCP) for now.
     *
     * @throws NotFoundHttpException the page doesn't exist or the user can't see it (404)
     */
    public function links(Request $request, string $uuid): PageLinksResource
    {
        $page = $this->visiblePage($request, $uuid);
        $links = ContentLinkFormatter::references($page->project->workspace, $page->project, $request->user(), $page);

        return new PageLinksResource(['related' => $links['related'], 'referenced_by' => $links['referenced_by']]);
    }

    /** @return array<string, mixed> what PageReviewDetailResource reads */
    private static function reviewDetail(PageReview $review, Page $page, User $user): array
    {
        PageReviews::recompute($review, $page);
        $mine = $review->reviewers->firstWhere('user_id', $user->id);
        $html = $review->snapshotHtml();

        return ReviewPresenter::summary($review, $page) + [
            'snapshot_markdown' => IssueMarkdown::toMarkdown($html) ?? '',
            'snapshot_html' => (string) SafeHtml::clean($html),
            'my_decision' => $mine ? ['decision' => $mine->decision, 'comment' => $mine->comment] : null,
            'threads' => ReviewPresenter::threads($review, $page, $user, $user->can('update', $page)),
            'can' => [
                'decide' => $user->can('decide', $review),
                'comment' => $user->can('comment', $review),
                'open_page' => $user->can('view', $page),
            ],
        ];
    }

    /** @return array<string, mixed> one thread as ReviewPresenter::threads builds it */
    private static function thread(PageComment $root, PageReview $review, Page $page, User $user): array
    {
        $threads = ReviewPresenter::threads($review, $page, $user, $user->can('update', $page));

        return collect($threads)->firstWhere('uuid', $root->uuid) ?? abort(404);
    }
}
