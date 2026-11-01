<?php

namespace App\Http\Controllers\AppApi;

use App\Http\Controllers\AppApi\Concerns\ResolvesVisible;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppApi\ResolveLinksRequest;
use App\Http\Resources\AppApi\SmartLinkResource;
use App\Models\Workspace;
use App\Support\References\SmartLinks;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * App API v1 — smart link chips (BRAI-413): live title / status / assignee of issues, docs, whiteboards,
 * tickets and plans linked in a doc or issue, as the web's chips (SmartLinks::resolve, the same visibility).
 */
#[Group('Docs', weight: 230)]
class LinkController extends Controller
{
    use ResolvesVisible;

    /**
     * Resolve smart links: refs from the HTML (`data-smart-link`) and / or links to this Brainitive from Markdown.
     *
     * One entry per requested ref / URL, in the order asked: `ok` (live data), `restricted` (it exists but the
     * user can't open it — no title) or `not_found` (also any URL that isn't an issue, doc, whiteboard, ticket or
     * plan page of this instance, and content of other workspaces). At most 100 refs + 100 URLs per call.
     *
     * @throws NotFoundHttpException the workspace doesn't exist or the user isn't a member (404)
     */
    public function resolve(ResolveLinksRequest $request): AnonymousResourceCollection
    {
        $workspace = Workspace::where('slug', (string) $request->validated('workspace'))->first();
        abort_unless($workspace instanceof Workspace && $request->user()->hasWorkspaceAccess($workspace), 404);

        $asked = [];
        foreach ($request->validated('refs') ?? [] as $ref) {
            $asked[] = [(string) $ref, (string) $ref];
        }
        foreach ($request->validated('urls') ?? [] as $url) {
            $asked[] = [(string) $url, SmartLinks::refFromUrl((string) $url) ?? 'none:'.$url];
        }
        $resolved = SmartLinks::resolve($workspace, $request->user(), array_values(array_unique(array_column($asked, 1))));

        return SmartLinkResource::collection(array_map(
            fn (array $pair) => ['requested' => $pair[0]] + ($resolved[$pair[1]] ?? ['state' => 'not_found']),
            $asked,
        ));
    }
}
