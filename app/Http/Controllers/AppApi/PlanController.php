<?php

namespace App\Http\Controllers\AppApi;

use App\Http\Controllers\AppApi\Concerns\ResolvesVisible;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppApi\ListRequest;
use App\Http\Resources\AppApi\PlanCollection;
use App\Http\Resources\AppApi\PlanDetailResource;
use App\Models\Plan;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * App API v1 — Gantt plans, read-only (BRAI-415). PlanPolicy (everybody who can open the project reads them) and
 * Support\Plans\PlanReader (shared with the MCP's get_plan). Editing plans, sharing, versions: web only.
 */
#[Group('Plans', weight: 250)]
class PlanController extends Controller
{
    use ResolvesVisible;

    /**
     * A project's Gantt plans in the web's order, cursor-paginated.
     *
     * @throws NotFoundHttpException the project doesn't exist or the user can't open it (404)
     */
    public function index(ListRequest $request, string $project): PlanCollection
    {
        $project = $this->visibleProject($request, $project);

        $page = $project->plans()->getQuery()
            ->when($request->updatedSince(), fn ($q, $since) => $q->where('updated_at', '>=', $since))
            ->reorder()->orderBy('position')->orderBy('id')
            ->cursorPaginate($request->limit(), ['*'], 'cursor', $request->cursor());
        $page->getCollection()->each(fn (Plan $plan) => $plan->setRelation('project', $project));

        return new PlanCollection($page);
    }

    /**
     * One plan: rows in outline order with their bars, the dependencies between bars, linked issues.
     *
     * A row's `issue` is the board issue it is linked to (its dates follow the bar), named only when the user can
     * open it. Editing: web only (`can.update` says whether to offer "Open in browser").
     *
     * @throws NotFoundHttpException the plan doesn't exist or the user can't open its project (404)
     */
    public function show(Request $request, string $plan): PlanDetailResource
    {
        return new PlanDetailResource($this->visiblePlan($request, $plan));
    }
}
