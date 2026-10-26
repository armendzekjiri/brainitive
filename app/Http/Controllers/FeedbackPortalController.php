<?php

namespace App\Http\Controllers;

use App\Models\FeedbackPortal;
use App\Models\FeedbackPost;
use App\Models\PortalIdentity;
use App\Support\Feedback\FeedbackNotifier;
use App\Support\Feedback\FeedbackPresenter;
use App\Support\Feedback\FeedbackText;
use App\Support\Feedback\FeedbackVotes;
use App\Support\Portal\PortalAuth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public feedback portal (v1.42.0, BRAI-60): /feedback/{slug}. Anyone reads approved posts of an
 * enabled portal; signed-in identities (portal.identity) submit, vote and follow. Everything public
 * goes through FeedbackPresenter. A disabled portal, a deleted project, a pending or rejected post
 * (except a pending one to its author) are 404.
 */
class FeedbackPortalController extends Controller
{
    public const PER_PAGE = 20;

    /** submissions per identity in SUBMIT_WINDOW seconds (the route also throttles per IP) */
    public const SUBMITS_PER_IDENTITY = 5;

    public const SUBMIT_WINDOW = 600;

    public function index(Request $request, string $portalSlug): Response
    {
        $portal = FeedbackPortal::findPublic($portalSlug);
        $filters = $request->validate([
            'sort' => ['nullable', Rule::in(['top', 'new'])],
            'status' => ['nullable', Rule::in(FeedbackPost::STATUSES)],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $sort = $filters['sort'] ?? 'top';
        $search = trim($filters['q'] ?? '');
        $viewer = PortalAuth::current($request);

        $query = $portal->posts()->published()->with('author:id,name')
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($search !== '', fn (Builder $q) => $this->search($q, $search, true));
        $sort === 'new'
            ? $query->orderByDesc('created_at')->orderByDesc('id')
            : $query->orderByDesc('votes_count')->orderByDesc('id');

        $page = $query->paginate(self::PER_PAGE)->withQueryString();

        $counts = $portal->posts()->published()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($n) => (int) $n);

        $mine = $viewer
            ? $portal->posts()->where('moderation', 'pending')->where('author_identity_id', $viewer->id)->whereNull('merged_into_id')->latest('id')->limit(10)->get()
            : collect();

        return Inertia::render('Feedback/Index', [
            'portal' => FeedbackPresenter::portal($portal),
            'posts' => FeedbackPresenter::posts($portal, collect($page->items()), $viewer),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'prev_url' => $page->previousPageUrl(),
                'next_url' => $page->nextPageUrl(),
            ],
            'pending' => FeedbackPresenter::posts($portal, $mine, $viewer),
            'filters' => ['sort' => $sort, 'status' => $filters['status'] ?? null, 'q' => $search],
            'counts' => (object) $counts->all(),
            'statuses' => FeedbackPost::STATUS_LABELS,
            'identity' => $viewer?->toPortalArray(),
        ]);
    }

    public function roadmap(Request $request, string $portalSlug): Response
    {
        $portal = FeedbackPortal::findPublic($portalSlug);
        $viewer = PortalAuth::current($request);

        $columns = collect(FeedbackPost::ROADMAP)->map(function (string $status) use ($portal, $viewer) {
            $posts = $portal->posts()->published()->where('status', $status)->with('author:id,name')
                ->orderByDesc('votes_count')->orderByDesc('id')->limit(50)->get();

            return [
                'status' => $status,
                'label' => FeedbackPost::STATUS_LABELS[$status],
                'posts' => FeedbackPresenter::posts($portal, $posts, $viewer),
            ];
        })->values();

        return Inertia::render('Feedback/Roadmap', [
            'portal' => FeedbackPresenter::portal($portal),
            'columns' => $columns,
            'identity' => $viewer?->toPortalArray(),
        ]);
    }

    /** "Similar ideas" while typing a new one (JSON; read-only session). */
    public function similar(Request $request, string $portalSlug): JsonResponse
    {
        $portal = FeedbackPortal::findPublic($portalSlug);
        $q = trim((string) ($request->validate(['q' => ['nullable', 'string', 'max:160']])['q'] ?? ''));
        if (mb_strlen($q) < 3) {
            return response()->json(['posts' => []]);
        }

        $posts = $this->search(FeedbackPost::query()->where('feedback_portal_id', $portal->id)->published(), $q)
            ->orderByDesc('votes_count')->orderByDesc('id')->limit(5)->get();

        return response()->json(['posts' => $posts->map(fn (FeedbackPost $post) => [
            'number' => $post->number,
            'title' => $post->title,
            'status_label' => FeedbackPost::STATUS_LABELS[$post->status] ?? $post->status,
            'votes_count' => $post->votes_count,
            'url' => route('feedback.post', [$portal->slug, $post->number]),
        ])->values()]);
    }

    public function show(Request $request, string $portalSlug, int $number): Response|RedirectResponse
    {
        $portal = FeedbackPortal::findPublic($portalSlug);
        $post = $portal->posts()->where('number', $number)->with('author:id,name')->firstOrFail();

        if ($post->merged_into_id !== null) {
            $target = $post->mergeTarget();
            abort_unless($target?->isPublished(), 404);

            return redirect()->route('feedback.post', [$portal->slug, $target->number], 301);
        }

        $viewer = PortalAuth::current($request);
        $this->ensureVisible($post, $viewer);

        $hasVoted = $viewer && DB::table('feedback_votes')->where(['feedback_post_id' => $post->id, 'portal_identity_id' => $viewer->id])->exists();
        $isFollowing = $viewer && DB::table('feedback_subscriptions')->where(['feedback_post_id' => $post->id, 'portal_identity_id' => $viewer->id])->exists();

        return Inertia::render('Feedback/Show', [
            'portal' => FeedbackPresenter::portal($portal),
            'post' => FeedbackPresenter::post($portal, $post, $viewer, $hasVoted, $isFollowing),
            'statuses' => FeedbackPost::STATUS_LABELS,
            'identity' => $viewer?->toPortalArray(),
        ]);
    }

    public function store(Request $request, string $portalSlug): RedirectResponse
    {
        $portal = FeedbackPortal::findPublic($portalSlug);
        $identity = $this->identity($request);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'body' => ['nullable', 'string', 'max:5000'],
        ]);
        $title = FeedbackText::line($data['title'], 160);
        if (mb_strlen($title) < 4) {
            throw ValidationException::withMessages(['title' => 'Give your idea a title of at least 4 characters.']);
        }

        $key = 'feedback-submit:'.$identity->id;
        if (RateLimiter::tooManyAttempts($key, self::SUBMITS_PER_IDENTITY)) {
            throw ValidationException::withMessages(['title' => 'You shared a lot of ideas just now. Please try again in a few minutes.']);
        }
        RateLimiter::hit($key, self::SUBMIT_WINDOW);

        $post = DB::transaction(function () use ($portal, $identity, $title, $data) {
            $post = $portal->posts()->create([
                'number' => $portal->nextNumber(),
                'title' => $title,
                'body' => FeedbackText::block($data['body'] ?? null, 5000),
                'author_identity_id' => $identity->id,
                'moderation' => 'pending',
                'status' => 'open',
            ]);
            FeedbackVotes::follow($post, $identity); // authors follow their own idea
            FeedbackNotifier::submitted($post);

            return $post;
        });

        return redirect()->route('feedback.post', [$portal->slug, $post->number])
            ->with('success', 'Thanks! Your idea is waiting for review and will show up once it is approved.');
    }

    public function vote(Request $request, string $portalSlug, int $number): RedirectResponse
    {
        [, $post] = $this->published($portalSlug, $number);
        FeedbackVotes::vote($post, $this->identity($request));

        return back();
    }

    public function unvote(Request $request, string $portalSlug, int $number): RedirectResponse
    {
        [, $post] = $this->published($portalSlug, $number);
        FeedbackVotes::unvote($post, $this->identity($request));

        return back();
    }

    public function follow(Request $request, string $portalSlug, int $number): RedirectResponse
    {
        $portal = FeedbackPortal::findPublic($portalSlug);
        $post = $portal->posts()->where('number', $number)->whereNull('merged_into_id')->firstOrFail();
        $identity = $this->identity($request);
        $this->ensureVisible($post, $identity);
        FeedbackVotes::follow($post, $identity);

        return back();
    }

    public function unfollow(Request $request, string $portalSlug, int $number): RedirectResponse
    {
        $portal = FeedbackPortal::findPublic($portalSlug);
        $post = $portal->posts()->where('number', $number)->whereNull('merged_into_id')->firstOrFail();
        FeedbackVotes::unfollow($post, $this->identity($request));

        return back();
    }

    /** The signed link in a status mail: a confirm page (mail scanners must not unfollow anyone). */
    public function unsubscribeShow(Request $request, string $portalSlug, int $number): Response
    {
        [$portal, $post, $identity] = $this->fromSignedLink($request, $portalSlug, $number);
        $following = $identity && DB::table('feedback_subscriptions')->where(['feedback_post_id' => $post->id, 'portal_identity_id' => $identity->id])->exists();

        return Inertia::render('Feedback/Unsubscribe', [
            'portal' => FeedbackPresenter::portal($portal),
            'post' => ['number' => $post->number, 'title' => $post->title, 'url' => route('feedback.post', [$portal->slug, $post->number])],
            'done' => ! $following,
            'action' => $request->fullUrl(),
            'identity' => PortalAuth::shared($request),
        ]);
    }

    public function unsubscribe(Request $request, string $portalSlug, int $number): RedirectResponse
    {
        [, $post, $identity] = $this->fromSignedLink($request, $portalSlug, $number);
        if ($identity) {
            FeedbackVotes::unfollow($post, $identity);
        }

        return redirect()->to($request->fullUrl());
    }

    /** @return array{0: FeedbackPortal, 1: FeedbackPost, 2: ?PortalIdentity} */
    private function fromSignedLink(Request $request, string $portalSlug, int $number): array
    {
        // no "enabled" check: an unsubscribe link keeps working when the portal is switched off
        $portal = FeedbackPortal::where('slug', $portalSlug)->firstOrFail();
        $post = $portal->posts()->where('number', $number)->firstOrFail();
        $post = $post->mergeTarget() ?? $post; // followers moved with a merge
        $identity = PortalIdentity::where('uuid', (string) $request->query('identity'))->first();

        return [$portal, $post, $identity];
    }

    /** @return array{0: FeedbackPortal, 1: FeedbackPost} */
    private function published(string $portalSlug, int $number): array
    {
        $portal = FeedbackPortal::findPublic($portalSlug);
        $post = $portal->posts()->published()->where('number', $number)->firstOrFail();

        return [$portal, $post];
    }

    private function identity(Request $request): PortalIdentity
    {
        return $request->attributes->get('portalIdentity');
    }

    /** Approved posts for everyone; a pending one only for its author. */
    private function ensureVisible(FeedbackPost $post, ?PortalIdentity $viewer): void
    {
        if ($post->moderation === 'approved') {
            return;
        }

        abort_unless($post->moderation === 'pending' && $viewer && $post->author_identity_id === $viewer->id, 404);
    }

    /**
     * Case-insensitive match of the query's words (≥ 3 characters) in title or body: all of them
     * for the search box, any of them for "similar ideas".
     */
    private function search(Builder $query, string $search, bool $allWords = false): Builder
    {
        $words = collect(preg_split('/\s+/u', mb_strtolower($search)))
            ->map(fn ($w) => trim($w, " \t\n\r\0\x0B.,;:!?\"'()[]{}"))
            ->filter(fn ($w) => mb_strlen($w) >= 3)
            ->unique()->take(6)->values();
        if ($words->isEmpty()) {
            $words = collect([$search]);
        }

        return $query->where(function (Builder $q) use ($words, $allWords) {
            foreach ($words as $word) {
                $like = '%'.addcslashes($word, '%_\\').'%';
                $match = fn (Builder $w) => $w->whereLike('title', $like)->orWhereLike('body', $like);
                $allWords ? $q->where($match) : $q->orWhere($match);
            }
        });
    }
}
