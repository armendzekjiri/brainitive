<?php

namespace App\Http\Controllers\AppApi;

use App\Http\Controllers\AppApi\Concerns\ResolvesVisible;
use App\Http\Controllers\Controller;
use App\Http\Controllers\InsightsController as WebInsightsController;
use App\Http\Requests\AppApi\InsightsRequest;
use App\Http\Requests\AppApi\ProjectHealthRequest;
use App\Http\Requests\AppApi\WorkloadRequest;
use App\Http\Resources\AppApi\InsightsResource;
use App\Http\Resources\AppApi\ProjectHealthResource;
use App\Http\Resources\AppApi\WorkloadResource;
use App\Models\ProjectHealthReport;
use App\Models\WorkloadAllocation;
use App\Models\Workspace;
use App\Support\Insights\InsightsFilters;
use App\Support\ProjectHealth\HealthMetrics;
use App\Support\ProjectHealth\HealthWindow;
use App\Support\Workload\WorkloadGrid;
use App\Support\Workload\WorkloadWeeks;
use Dedoc\Scramble\Attributes\Group;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * App API v1 — read-only summaries (BRAI-423): the workspace admins' Insights tabs, a project's health and its
 * workload grid — the same Support classes and role checks as the web pages. CSV / Excel exports, writing
 * status updates and planning workload: web only. Team time: the Insights `team` tab (hours per member); the
 * billing time overview (amounts) stays on the web.
 */
#[Group('Insights', weight: 320)]
class InsightsController extends Controller
{
    use ResolvesVisible;

    /**
     * One tab of the workspace's Insights: overview, team, projects, helpdesk or usage.
     *
     * Workspace admins only (403 for members; 404 for others), as the web. Filters: `period` (default last_30)
     * or `period=custom&from=&to=`, `project`.
     *
     * @throws NotFoundHttpException not a member of the workspace, or an unknown tab (404)
     * @throws AccessDeniedHttpException not a workspace admin (403)
     */
    public function insights(InsightsRequest $request, Workspace $workspace, string $tab): InsightsResource
    {
        $this->visibleWorkspace($request, $workspace);
        abort_unless(array_key_exists($tab, WebInsightsController::TABS), 404);
        $this->authorize('update', $workspace);
        $filters = InsightsFilters::fromRequest($request, $workspace);

        return new InsightsResource([
            'tab' => $tab,
            'filters' => $filters->toArray(),
            'projects' => array_values(array_map(fn (array $p) => ['id' => (int) $p['id'], 'name' => (string) $p['name']], $filters->projects)),
            'insights' => WebInsightsController::compute($tab, $filters),
        ]);
    }

    /**
     * A project's health: live metrics of a period and the saved status updates.
     *
     * Every project member, as the web. `from` / `to` (local days, ≤ a year; default the last 14 days), `tz`.
     *
     * @throws NotFoundHttpException the project doesn't exist or the user can't open it (404)
     */
    public function health(ProjectHealthRequest $request, string $project): ProjectHealthResource
    {
        $project = $this->visibleProject($request, $project);
        $this->authorize('viewAny', [ProjectHealthReport::class, $project]);
        $user = $request->user();
        [$timezone, $from, $to] = HealthWindow::resolve($request->validated(), $user);

        return new ProjectHealthResource([
            'from' => $from,
            'to' => $to,
            'timezone' => $timezone,
            'metrics' => HealthMetrics::for($project, $user, $timezone, $from, $to)->compute(),
            'reports' => $project->healthReports()->with('author:id,name')->latest()->limit(30)->get()
                ->map(fn (ProjectHealthReport $report) => [
                    'id' => $report->id,
                    'status' => (string) $report->status,
                    'summary' => $report->summary,
                    'author' => $report->author?->name,
                    'as_of' => $report->as_of?->toIso8601ZuluString(),
                    'created_at' => $report->created_at?->toIso8601ZuluString(),
                    'ai_assisted' => (bool) $report->ai_assisted,
                ])->all(),
            'can_create' => $user->can('create', [ProjectHealthReport::class, $project]),
            'url' => route('projects.health', [$project->workspace->slug, $project->slug]),
        ]);
    }

    /**
     * A project's workload: capacity vs planned hours per member and week.
     *
     * Every project member, as the web. `from` (any day of the first week), `weeks` 4 | 6 | 8 | 12.
     *
     * @throws NotFoundHttpException the project doesn't exist or the user can't open it (404)
     */
    public function workload(WorkloadRequest $request, string $project): WorkloadResource
    {
        $project = $this->visibleProject($request, $project);
        $this->authorize('viewAny', [WorkloadAllocation::class, $project]);
        $user = $request->user();
        $canPlan = $user->can('plan', [WorkloadAllocation::class, $project]);
        $span = (int) ($request->validated('weeks') ?? WorkloadWeeks::DEFAULT_SPAN);
        $from = $request->validated('from');
        $from = is_string($from) ? WorkloadWeeks::monday($from) : WorkloadWeeks::current($user);

        return new WorkloadResource([
            'from' => $from,
            'span' => $span,
            'timezone' => $user->localTimezone(),
            'canPlan' => $canPlan,
            'url' => route('workload.index', [$project->workspace->slug, $project->slug]),
        ] + WorkloadGrid::build($project->workspace, $project, $user, $from, $span, $canPlan));
    }
}
