<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Support\Insights\HelpdeskInsights;
use App\Support\Insights\InsightsCsv;
use App\Support\Insights\InsightsFilters;
use App\Support\Insights\OverviewInsights;
use App\Support\Insights\ProjectInsights;
use App\Support\Insights\TeamInsights;
use App\Support\Insights\UsageInsights;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Workspace → Insights (BRAI-105): read-only numbers for workspace admins, one page per tab, each
 * with a CSV export. Admins can open every project of their workspace, so every live project is in
 * scope (or the one picked in the project filter). Definitions live on the App\Support\Insights classes.
 */
class InsightsController extends Controller
{
    /** Tab => [Inertia page, computing class]. */
    public const TABS = [
        'overview' => ['Insights/Overview', OverviewInsights::class],
        'team' => ['Insights/Team', TeamInsights::class],
        'projects' => ['Insights/Projects', ProjectInsights::class],
        'helpdesk' => ['Insights/Helpdesk', HelpdeskInsights::class],
        'usage' => ['Insights/Usage', UsageInsights::class],
    ];

    public function index(Request $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('update', $workspace);

        return redirect()->route('insights.overview', [$workspace, ...$request->only(['period', 'from', 'to', 'project'])]);
    }

    public function overview(Request $request, Workspace $workspace): Response
    {
        return $this->tab($request, $workspace, 'overview');
    }

    public function team(Request $request, Workspace $workspace): Response
    {
        return $this->tab($request, $workspace, 'team');
    }

    public function projects(Request $request, Workspace $workspace): Response
    {
        return $this->tab($request, $workspace, 'projects');
    }

    public function helpdesk(Request $request, Workspace $workspace): Response
    {
        return $this->tab($request, $workspace, 'helpdesk');
    }

    public function usage(Request $request, Workspace $workspace): Response
    {
        return $this->tab($request, $workspace, 'usage');
    }

    public function export(Request $request, Workspace $workspace, string $tab): StreamedResponse
    {
        Gate::authorize('update', $workspace);
        $filters = InsightsFilters::fromRequest($request, $workspace);

        return InsightsCsv::download($tab, self::compute($tab, $filters), $filters);
    }

    /** @return array<string, mixed> */
    public static function compute(string $tab, InsightsFilters $filters): array
    {
        return self::TABS[$tab][1]::compute($filters);
    }

    private function tab(Request $request, Workspace $workspace, string $tab): Response
    {
        Gate::authorize('update', $workspace);
        $filters = InsightsFilters::fromRequest($request, $workspace);

        return Inertia::render(self::TABS[$tab][0], [
            'workspace' => $workspace->only(['id', 'name', 'slug']),
            'filters' => $filters->toArray(),
            'presets' => $filters->presets(),
            'projectOptions' => array_values(array_map(fn (array $p) => ['id' => $p['id'], 'name' => $p['name']], $filters->projects)),
            'exportQuery' => $filters->query(),
            'insights' => self::compute($tab, $filters),
        ]);
    }
}
