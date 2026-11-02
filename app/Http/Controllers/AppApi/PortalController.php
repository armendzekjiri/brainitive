<?php

namespace App\Http\Controllers\AppApi;

use App\Http\Controllers\AppApi\Concerns\ResolvesVisible;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppApi\ListFeedbackPostsRequest;
use App\Http\Requests\AppApi\ModerateFeedbackPostRequest;
use App\Http\Requests\AppApi\UpdateFeedbackPostRequest;
use App\Http\Resources\AppApi\ClientDeliverableDetailResource;
use App\Http\Resources\AppApi\ClientDeliverableResource;
use App\Http\Resources\AppApi\ClientPortalResource;
use App\Http\Resources\AppApi\FeedbackPortalResource;
use App\Http\Resources\AppApi\FeedbackPostCollection;
use App\Http\Resources\AppApi\FeedbackPostResource;
use App\Models\ClientDeliverable;
use App\Models\ClientPortal;
use App\Models\FeedbackPortal;
use App\Models\FeedbackPost;
use App\Support\ClientPortal\Deliverables;
use App\Support\Feedback\FeedbackModeration;
use App\Support\Feedback\FeedbackText;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * App API v1 — the team side of feedback portals and client portals (BRAI-422): moderating ideas and setting
 * their public status (project admins, as the web's Feedback page: FeedbackModeration), and reading what was
 * shared with clients and how they decided (ClientPortalPolicy, Deliverables). Portal users (voters, clients)
 * are not workspace members: their side is public and never in the App API. Settings, merging, cards, clients,
 * publishing and share links: web only.
 */
#[Group('Portals', weight: 310)]
class PortalController extends Controller
{
    use ResolvesVisible;

    /**
     * The project's feedback portal with the number of ideas per moderation tab.
     *
     * Project admins (and workspace admins) only, as the web (403 for other members).
     *
     * @throws NotFoundHttpException the project doesn't exist, the user can't open it, or it has no feedback portal (404)
     * @throws AccessDeniedHttpException not an admin of the project (403)
     */
    public function feedbackPortal(Request $request, string $project): FeedbackPortalResource
    {
        $project = $this->visibleProject($request, $project);
        $this->authorize('update', $project);
        $portal = FeedbackPortal::where('project_id', $project->id)->first();
        abort_unless($portal instanceof FeedbackPortal, 404, 'This project has no feedback portal.');
        $portal->setRelation('project', $project)->setAttribute('moderation_counts', FeedbackModeration::counts($portal));

        return new FeedbackPortalResource($portal);
    }

    /**
     * The ideas of one moderation tab.
     *
     * `pending` (oldest first), `published` (most votes first, default), `rejected`, `merged` (newest first).
     * Cursor-paginated. Project admins only (403).
     *
     * @throws NotFoundHttpException the portal doesn't exist or the user can't open its project (404)
     * @throws AccessDeniedHttpException not an admin of the project (403)
     */
    public function feedbackPosts(ListFeedbackPostsRequest $request, string $portal): FeedbackPostCollection
    {
        $portal = $this->visibleFeedbackPortal($request, $portal);
        $page = FeedbackModeration::tabQuery($portal, (string) ($request->validated('tab') ?? 'published'))
            ->cursorPaginate($request->limit(), ['*'], 'cursor', $request->cursor());
        $page->getCollection()->each(fn (FeedbackPost $post) => $post->setRelation('portal', $portal));

        return new FeedbackPostCollection($page);
    }

    /**
     * Set an idea's public status (Planned / Building / Released …) and its note.
     *
     * Followers are notified as on the web. A merged idea → 422. Project admins only (403).
     *
     * @throws NotFoundHttpException the idea doesn't exist or the user can't open its project (404)
     */
    public function updateFeedbackPost(UpdateFeedbackPostRequest $request): FeedbackPostResource
    {
        $post = $request->feedbackPost();
        FeedbackModeration::setStatus($post, $request->user(), (string) $request->validated('status'), FeedbackText::block($request->validated('note'), 1000));

        return new FeedbackPostResource($this->fresh($post));
    }

    /**
     * Approve (make public) or reject a submitted idea.
     *
     * A merged idea → 422. Project admins only (403).
     *
     * @throws NotFoundHttpException the idea doesn't exist or the user can't open its project (404)
     */
    public function moderateFeedbackPost(ModerateFeedbackPostRequest $request): FeedbackPostResource
    {
        $post = $request->feedbackPost();
        FeedbackModeration::moderate($post, $request->user(), (string) $request->validated('decision'));

        return new FeedbackPostResource($this->fresh($post));
    }

    /**
     * The project's client portal and the user's rights in it.
     *
     * Every project member (as the web's Client portal page).
     *
     * @throws NotFoundHttpException the project doesn't exist, the user can't open it, or it has no client portal (404)
     */
    public function clientPortal(Request $request, string $project): ClientPortalResource
    {
        $project = $this->visibleProject($request, $project);
        $this->authorize('viewAny', [ClientPortal::class, $project]);
        $portal = ClientPortal::where('project_id', $project->id)->first();
        abort_unless($portal instanceof ClientPortal, 404, 'This project has no client portal.');

        return new ClientPortalResource($portal->setRelation('project', $project));
    }

    /**
     * What was shared in a client portal, current versions first, newest first, with the clients' approval state.
     *
     * Not paginated (at most 300, as the web).
     *
     * @throws NotFoundHttpException the portal doesn't exist or the user can't open its project (404)
     */
    public function deliverables(Request $request, string $portal): AnonymousResourceCollection
    {
        $portal = $this->visibleClientPortal($request, $portal);
        $rows = $portal->deliverables()->with('publisher:id,name')->withCount(['decisions', 'comments'])
            ->orderByRaw('superseded_at is not null')->orderByRaw('withdrawn_at is not null')
            ->orderByDesc('published_at')->orderByDesc('id')->limit(300)->get();

        return ClientDeliverableResource::collection($rows->map(fn (ClientDeliverable $d) => Deliverables::summary($d))->values());
    }

    /**
     * One deliverable: the snapshot, the clients' decisions, the conversation and the other versions.
     *
     * Clients' addresses only for project admins (`email: null` otherwise), as the web.
     *
     * @throws NotFoundHttpException the deliverable doesn't exist or the user can't open its project (404)
     */
    public function deliverable(Request $request, string $deliverable): ClientDeliverableDetailResource
    {
        abort_unless(preg_match('/^[1-9][0-9]{0,17}$/', $deliverable) === 1, 404);
        $item = ClientDeliverable::with(['publisher:id,name', 'decisions', 'comments'])->find((int) $deliverable);
        abort_unless($item instanceof ClientDeliverable, 404);
        $portal = $this->visibleClientPortal($request, (string) $item->client_portal_id);
        $canManage = $request->user()->can('manage', [ClientPortal::class, $portal->project]);

        $key = $item->kind === 'page' ? 'page_id' : 'plan_id';
        $versions = $item->{$key}
            ? $portal->deliverables()->where('kind', $item->kind)->where($key, $item->{$key})->orderByDesc('version')->orderByDesc('id')->get()
            : collect([$item]);

        return new ClientDeliverableDetailResource(Deliverables::detail($portal, $item, $canManage) + [
            'versions' => $versions->map(fn (ClientDeliverable $d) => [
                'id' => $d->id,
                'version' => $d->version,
                'status' => $d->displayStatus(),
                'published_at' => $d->published_at?->toIso8601String(),
            ])->values()->all(),
        ]);
    }

    private function visibleFeedbackPortal(Request $request, string $id): FeedbackPortal
    {
        abort_unless(preg_match('/^[1-9][0-9]{0,17}$/', $id) === 1, 404);
        $portal = FeedbackPortal::with('project.workspace')->find((int) $id);
        abort_unless($portal instanceof FeedbackPortal && $portal->project?->workspace !== null && $request->user()->can('view', $portal->project), 404);
        $this->authorize('update', $portal->project);

        return $portal;
    }

    private function visibleClientPortal(Request $request, string $id): ClientPortal
    {
        abort_unless(preg_match('/^[1-9][0-9]{0,17}$/', $id) === 1, 404);
        $portal = ClientPortal::with('project.workspace')->find((int) $id);
        abort_unless($portal instanceof ClientPortal && $portal->project?->workspace !== null && $request->user()->can('viewAny', [ClientPortal::class, $portal->project]), 404);

        return $portal;
    }

    private function fresh(FeedbackPost $post): FeedbackPost
    {
        $portal = $post->portal;

        return FeedbackPost::with(FeedbackModeration::ROW_WITH)->findOrFail($post->id)->setRelation('portal', $portal);
    }
}
