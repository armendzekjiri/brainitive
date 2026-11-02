<?php

namespace App\Http\Controllers\AppApi;

use App\Http\Controllers\AppApi\Concerns\ResolvesVisible;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppApi\ListReleasesRequest;
use App\Http\Resources\AppApi\ReleaseDetailResource;
use App\Http\Resources\AppApi\ReleaseResource;
use App\Models\Release;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * App API v1 — releases (fix versions), read-only (BRAI-415). ReleasePolicy: everybody who can open the project
 * reads them, as on the web's releases page (clients on the portal only ever see the published notes, on the public
 * page). Planning, shipping, notes and publishing: web only.
 */
#[Group('Releases', weight: 260)]
class ReleaseController extends Controller
{
    use ResolvesVisible;

    /**
     * A project's releases in the web's order: unreleased (by release date), released, archived. Not paginated.
     *
     * @throws NotFoundHttpException the project doesn't exist or the user can't open it (404)
     */
    public function index(ListReleasesRequest $request, string $project): AnonymousResourceCollection
    {
        $project = $this->visibleProject($request, $project);
        $statuses = $request->statuses();

        $releases = $project->releases()
            ->when($statuses !== [], fn ($q) => $q->whereIn('status', $statuses))
            ->orderByRaw("case status when 'unreleased' then 0 when 'released' then 1 else 2 end")
            ->orderByRaw('release_date is null')->orderBy('release_date')->orderByDesc('released_at')->orderBy('id')
            ->get()
            ->each(fn (Release $release) => $release->setRelation('project', $project));

        return ReleaseResource::collection($releases);
    }

    /**
     * One release: description, notes (Markdown + sanitized HTML; the working copy and the published one), its issues
     * (up to 500, by key) with their state, the public page link.
     *
     * @throws NotFoundHttpException the release doesn't exist or the user can't open its project (404)
     */
    public function show(Request $request, string $release): ReleaseDetailResource
    {
        return new ReleaseDetailResource($this->visibleRelease($request, $release));
    }
}
