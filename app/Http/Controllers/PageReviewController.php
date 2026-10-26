<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Models\PageComment;
use App\Models\PageReview;
use App\Models\Project;
use App\Models\Workspace;
use App\Support\PageReviews\PageReviews;
use App\Support\PageReviews\QuoteAnchor;
use App\Support\PageReviews\ReviewPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Internal document review (BRAI-80): request a review of a page draft, review its snapshot
 * (decisions + inline comments), and the per-page "Review required before publish" switch.
 * Publishing itself stays in PageController (gated by ReviewGate).
 */
class PageReviewController extends Controller
{
    /** People who can be asked: everyone who can open the project, except you. */
    public function candidates(Request $request, Workspace $workspace, Project $project, Page $page): JsonResponse
    {
        $this->authorize('request', [PageReview::class, $page]);

        $users = $project->accessibleUsers()->where('users.id', '!=', $request->user()->id)
            ->orderBy('name')->get(['users.id', 'users.name', 'users.email', 'users.avatar']);

        return response()->json([
            'users' => $users->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $user->avatar_display,
            ])->values(),
        ]);
    }

    public function store(Request $request, Workspace $workspace, Project $project, Page $page): RedirectResponse
    {
        $this->authorize('request', [PageReview::class, $page]);

        $allowed = $project->accessibleUsers()->where('users.id', '!=', $request->user()->id)->pluck('users.id')->all();
        $validated = $request->validate([
            'reviewer_ids' => 'required|array|min:1|max:20',
            'reviewer_ids.*' => ['integer', 'distinct', Rule::in($allowed)],
            'message' => 'nullable|string|max:2000',
        ], [
            'reviewer_ids.required' => 'Pick at least one reviewer.',
            'reviewer_ids.*.in' => 'Reviewers must be members of this project (and not you).',
        ]);

        $review = PageReviews::request($page, $request->user(), array_map('intval', $validated['reviewer_ids']), $validated['message'] ?? null);

        return redirect()->route('pages.reviews.show', [$workspace->slug, $project->slug, $page->uuid, $review->uuid])
            ->with('success', 'Review requested.');
    }

    public function show(Request $request, Workspace $workspace, Project $project, Page $page, string $review): Response
    {
        $review = $this->findReview($page, $review);
        $this->authorize('view', $review);

        $user = $request->user();
        $canUpdate = $user->can('update', $page);
        PageReviews::recompute($review, $page);
        $mine = $review->reviewers->firstWhere('user_id', $user->id);

        return Inertia::render('Pages/Review', [
            'workspace' => $workspace,
            'project' => $project,
            'page' => [
                'uuid' => $page->uuid,
                'status' => $page->status,
                'review_required' => (bool) $page->review_required,
                // the live page is only linked for people who may open it
                'can_open' => $user->can('view', $page),
            ],
            'review' => ReviewPresenter::summary($review, $page) + [
                'snapshot_html' => $review->snapshotHtml(),
                'my_decision' => $mine ? ['decision' => $mine->decision, 'comment' => $mine->comment] : null,
            ],
            'threads' => ReviewPresenter::threads($review, $page, $user, $canUpdate),
            'can' => [
                'decide' => $user->can('decide', $review),
                'comment' => $user->can('comment', $review),
                'cancel' => $user->can('cancel', $review),
                'request' => $user->can('request', [PageReview::class, $page]),
            ],
        ]);
    }

    public function decide(Request $request, Workspace $workspace, Project $project, Page $page, string $review): RedirectResponse
    {
        $review = $this->findReview($page, $review);
        $this->authorize('decide', $review);

        $validated = $request->validate([
            'decision' => 'required|in:approved,changes_requested',
            'comment' => 'nullable|string|max:5000',
        ]);

        PageReviews::decide($review, $request->user(), $validated['decision'], $validated['comment'] ?? null);

        return back()->with('success', $validated['decision'] === 'approved' ? 'Approved.' : 'Changes requested.');
    }

    public function cancel(Workspace $workspace, Project $project, Page $page, string $review): RedirectResponse
    {
        $review = $this->findReview($page, $review);
        $this->authorize('cancel', $review);

        PageReviews::cancel($review);

        return back()->with('success', 'Review cancelled.');
    }

    public function storeComment(Request $request, Workspace $workspace, Project $project, Page $page, string $review): RedirectResponse
    {
        $review = $this->findReview($page, $review);

        $validated = $request->validate([
            'body' => 'required|string|max:5000',
            'parent' => 'nullable|uuid',
            'quote' => 'nullable|string|max:1000',
            'prefix' => 'nullable|string|max:'.(QuoteAnchor::CONTEXT * 2),
            'suffix' => 'nullable|string|max:'.(QuoteAnchor::CONTEXT * 2),
        ]);

        $parent = null;
        if (! empty($validated['parent'])) {
            $parent = $this->findThread($page, $validated['parent']);
            $this->authorize('view', $parent->review);
        } else {
            $this->authorize('comment', $review);
        }

        if (! $parent && ! empty($validated['quote']) && QuoteAnchor::isOutdated($review->snapshotHtml(), $validated['quote'], $validated['prefix'] ?? null, $validated['suffix'] ?? null)) {
            return back()->withErrors(['quote' => 'The selected text isn\'t in this version of the page.']);
        }

        PageReviews::comment($review, $request->user(), [
            'body' => $validated['body'],
            'parent' => $parent,
            'quote' => $validated['quote'] ?? null,
            'prefix' => $validated['prefix'] ?? null,
            'suffix' => $validated['suffix'] ?? null,
        ]);

        return back()->with('success', $parent ? 'Reply added.' : 'Comment added.');
    }

    /** Resolve / reopen a thread. */
    public function updateThread(Request $request, Workspace $workspace, Project $project, Page $page, string $thread): RedirectResponse
    {
        $thread = $this->findThread($page, $thread);
        $this->authorize('view', $thread->review);

        $validated = $request->validate(['resolved' => 'required|boolean']);
        PageReviews::resolve($thread, $request->user(), (bool) $validated['resolved']);

        return back()->with('success', $validated['resolved'] ? 'Thread resolved.' : 'Thread reopened.');
    }

    /** "Review required before publish" (project admins). */
    public function settings(Request $request, Workspace $workspace, Project $project, Page $page): RedirectResponse
    {
        $this->authorize('manage', [PageReview::class, $page]);

        $validated = $request->validate(['review_required' => 'required|boolean']);
        $page->forceFill(['review_required' => (bool) $validated['review_required']])->saveQuietly();

        return back()->with('success', $validated['review_required']
            ? 'Publishing this page now needs an approved review.'
            : 'Publishing this page no longer needs a review.');
    }

    private function findReview(Page $page, string $uuid): PageReview
    {
        abort_unless(Str::isUuid($uuid), 404);
        $review = PageReview::where('page_id', $page->id)->where('uuid', $uuid)->firstOrFail();
        $review->setRelation('page', $page);

        return $review;
    }

    private function findThread(Page $page, string $uuid): PageComment
    {
        abort_unless(Str::isUuid($uuid), 404);
        $thread = PageComment::where('page_id', $page->id)->where('uuid', $uuid)->whereNull('parent_id')->firstOrFail();
        $thread->review->setRelation('page', $page);

        return $thread;
    }
}
