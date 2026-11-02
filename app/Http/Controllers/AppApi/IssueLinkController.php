<?php

namespace App\Http\Controllers\AppApi;

use App\Http\Controllers\AppApi\Concerns\ResolvesVisible;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppApi\ListRequest;
use App\Http\Requests\AppApi\StoreIssueLinkRequest;
use App\Http\Resources\AppApi\IssueCollection;
use App\Http\Resources\AppApi\IssueLinkResource;
use App\Http\Resources\AppApi\IssueResource;
use App\Models\Item;
use App\Models\ItemLink;
use App\Support\Boards\ItemLinks;
use App\Support\Mcp\ContentLinkFormatter;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * App API v1 — subtasks and linked issues (BRAI-406). Subtasks are issues with `parent_id` (created with
 * `POST /boards/{id}/items`, 3 levels at most); links go through ItemLinks (the issue view and the MCP use
 * it: one row per link, history entries on the issue) and show issues the user can't open as restricted.
 */
#[Group('Issues', weight: 210)]
class IssueLinkController extends Controller
{
    use ResolvesVisible;

    /**
     * The direct subtasks of an issue, in board order.
     *
     * Their own subtasks: `counts.subtasks` of each, and this call on them.
     *
     * @throws NotFoundHttpException the issue doesn't exist or the user can't open it (404)
     */
    public function children(ListRequest $request, string $item): IssueCollection
    {
        $item = $this->visibleItem($request, $item);
        $since = $request->updatedSince();

        $page = IssueResource::eagerLoad(Item::query()->where('parent_id', $item->id))
            ->when($since, fn ($q) => $q->where('updated_at', '>=', $since))
            ->orderBy('position')
            ->orderBy('id')
            ->cursorPaginate($request->limit(), ['*'], 'cursor', $request->cursor());
        $page->getCollection()->each(fn (Item $child) => $child->setRelation('board', $item->board));

        return new IssueCollection($page);
    }

    /**
     * The linked issues of an issue, read from its side (oldest link first, not paginated).
     *
     * @throws NotFoundHttpException the issue doesn't exist or the user can't open it (404)
     */
    public function index(Request $request, string $item): AnonymousResourceCollection
    {
        $item = $this->visibleItem($request, $item);

        return IssueLinkResource::collection(ContentLinkFormatter::issueLinks($item, $request->user()));
    }

    /**
     * Link the issue to another one.
     *
     * Answers 201 with the new link, or 200 with the existing one when the two are already linked that way
     * (either side: "A blocks B" = "B is blocked by A"). Needs `can.update` of the issue (403); the other
     * issue must be one the user can open (422 `target_id`).
     *
     * @throws NotFoundHttpException the issue doesn't exist or the user can't open it (404)
     */
    public function store(StoreIssueLinkRequest $request): JsonResponse
    {
        $item = $request->issue();
        $target = Item::with('board.project.workspace')->find((int) $request->validated('target_id'));
        if (! $target instanceof Item || $target->board?->project === null || $request->user()->cannot('view', $target)) {
            throw ValidationException::withMessages(['target_id' => 'That issue was not found.']);
        }
        if ($target->id === $item->id) {
            throw ValidationException::withMessages(['target_id' => 'Cannot link an item to itself.']);
        }

        $link = ItemLinks::add($item, $target, (string) $request->validated('type'), $request->user());
        $entry = $this->entry($item, $link, $request);
        if (! $link->wasRecentlyCreated) {
            return (new IssueLinkResource($entry))->response();
        }

        return (new IssueLinkResource($entry))->response()->setStatusCode(201);
    }

    /**
     * Remove a link of the issue (added on either side).
     *
     * Needs `can.update` of the issue (403).
     *
     * @throws NotFoundHttpException the issue or the link doesn't exist, or the user can't open it (404)
     */
    public function destroy(Request $request, string $item, string $link): Response
    {
        $item = $this->visibleItem($request, $item);
        $row = preg_match('/^[1-9][0-9]{0,17}$/', $link)
            ? ItemLink::whereKey((int) $link)->where(fn ($q) => $q->where('source_item_id', $item->id)->orWhere('target_item_id', $item->id))->first()
            : null;
        abort_unless($row instanceof ItemLink, 404);
        $this->authorize('update', $item);

        ItemLinks::remove($item, $row);

        return response()->noContent();
    }

    /** @return array{link_id: int, link_type: string, label: string, direction: string, restricted: bool, id: int, key: string, title: string, url: string} */
    private function entry(Item $item, ItemLink $link, Request $request): array
    {
        $entry = collect(ContentLinkFormatter::issueLinks($item, $request->user()))->firstWhere('link_id', $link->id);
        abort_if($entry === null, 404);

        return $entry;
    }
}
