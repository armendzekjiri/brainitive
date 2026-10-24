<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectHealthReport;
use App\Models\User;
use App\Models\Workspace;
use App\Support\ProjectHealth\HealthAiException;
use App\Support\ProjectHealth\HealthMetrics;
use App\Support\ProjectHealth\HealthNarrator;
use App\Support\ProjectHealth\HealthSnapshot;
use App\Support\ProjectHealth\ReportSources;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Project health (BRAI-64): live deterministic metrics, an optional AI draft of the status
 * update and saved reports with a person-chosen health status.
 */
class ProjectHealthController extends Controller
{
    /** Longest period a report can cover. */
    public const MAX_PERIOD_DAYS = 366;

    public const DEFAULT_PERIOD_DAYS = 14;

    public function show(Request $request, Workspace $workspace, Project $project): Response
    {
        $this->scope($workspace, $project);
        $this->authorize('viewAny', [ProjectHealthReport::class, $project]);
        $user = $request->user();
        [$timezone, $from, $to] = $this->window($request, $user, strict: false);

        return Inertia::render('Projects/Health', [
            'workspace' => $workspace,
            'project' => $project,
            'health' => fn () => HealthMetrics::for($project, $user, $timezone, $from, $to)->compute(),
            'reports' => fn () => $project->healthReports()->with('author:id,name')->latest()->limit(30)->get()
                ->map(fn (ProjectHealthReport $report) => [
                    'id' => $report->id,
                    'status' => $report->status,
                    'summary' => $report->summary,
                    'author' => $report->author?->name,
                    'as_of' => $report->as_of?->toIso8601String(),
                    'created_at' => $report->created_at?->toIso8601String(),
                    'ai_assisted' => $report->ai_assisted,
                ])->all(),
            'openReport' => function () use ($request, $project, $user) {
                $report = $request->filled('report') && ctype_digit((string) $request->query('report'))
                    ? $project->healthReports()->with('author:id,name')->find((int) $request->query('report'))
                    : null;
                $report?->setRelation('project', $project);

                return $report && $user->can('view', $report) ? ReportSources::present($report, $user) : null;
            },
            'aiConfigured' => HealthNarrator::configured($workspace),
            'canCreate' => $user->can('create', [ProjectHealthReport::class, $project]),
            'timezones' => array_values(array_unique([$timezone, $user->localTimezone(), 'UTC'])),
        ]);
    }

    /**
     * Draft Progress / Risks / Next steps from the bounded snapshot. JSON; nothing is saved.
     */
    public function narrative(Request $request, Workspace $workspace, Project $project): JsonResponse
    {
        $this->scope($workspace, $project);
        $this->authorize('create', [ProjectHealthReport::class, $project]);
        $user = $request->user();
        [$timezone, $from, $to] = $this->window($request, $user);

        $health = HealthMetrics::for($project, $user, $timezone, $from, $to)->compute();
        $snapshot = HealthSnapshot::build($project, $health);

        try {
            $draft = HealthNarrator::draft($workspace, $snapshot);
        } catch (HealthAiException $e) {
            return response()->json(['success' => false, 'ai_unavailable' => $e->notConfigured, 'message' => $e->getMessage()], 422);
        }

        $refs = [];
        foreach ($draft['sections'] as $claims) {
            foreach ($claims as $claim) {
                array_push($refs, ...$claim['sources']);
            }
        }

        return response()->json([
            'success' => true,
            'sections' => $draft['sections'],
            'dropped' => $draft['dropped'],
            'sources' => ReportSources::resolve($user, $refs),
            'as_of' => $health['as_of'],
        ]);
    }

    public function store(Request $request, Workspace $workspace, Project $project): RedirectResponse
    {
        $this->scope($workspace, $project);
        $this->authorize('create', [ProjectHealthReport::class, $project]);
        $user = $request->user();

        $validated = $request->validate([
            'status' => ['required', Rule::in(ProjectHealthReport::STATUSES)],
            'summary' => 'nullable|string|max:5000',
            'sections' => 'present|array:'.implode(',', ProjectHealthReport::SECTIONS),
            'sections.*' => 'array|max:10',
            'sections.*.*.text' => 'required|string|max:1000',
            'sections.*.*.sources' => 'present|array|max:10',
            'sections.*.*.sources.*' => ['string', 'regex:'.ReportSources::REF_PATTERN],
            'ai_assisted' => 'boolean',
        ], [
            'status.required' => 'Pick a health status.',
            'sections.*.*.text.required' => 'Points can’t be empty.',
        ]);
        [$timezone, $from, $to] = $this->window($request, $user);

        $sections = [];
        $refs = [];
        foreach (ProjectHealthReport::SECTIONS as $section) {
            $sections[$section] = array_map(function (array $claim) use (&$refs) {
                $sources = array_values(array_unique($claim['sources'] ?? []));
                array_push($refs, ...$sources);

                return ['text' => trim(strip_tags($claim['text'])), 'sources' => $sources];
            }, array_values($validated['sections'][$section] ?? []));
            if (in_array('', array_column($sections[$section], 'text'), true)) {
                throw ValidationException::withMessages(['sections' => 'Points can’t be empty.']);
            }
        }

        $invalid = ReportSources::invalid($project, $refs);
        if ($invalid !== []) {
            throw ValidationException::withMessages(['sections' => 'These sources aren’t in this project: '.implode(', ', array_slice($invalid, 0, 5)).'.']);
        }

        $health = HealthMetrics::for($project, $user, $timezone, $from, $to)->compute();
        $report = $project->healthReports()->create([
            'created_by' => $user->id,
            'status' => $validated['status'],
            'summary' => trim(strip_tags((string) ($validated['summary'] ?? ''))) ?: null,
            'sections' => $sections,
            'metrics' => ReportSources::storedMetrics($health),
            'as_of' => CarbonImmutable::parse($health['as_of']),
            'period_start' => $from,
            'period_end' => $to,
            'timezone' => $timezone,
            'ai_assisted' => (bool) ($validated['ai_assisted'] ?? false),
        ]);

        return redirect()->route('projects.health', [$workspace->slug, $project->slug, 'report' => $report->id, 'tz' => $timezone, 'from' => $from, 'to' => $to])
            ->with('success', 'Status update saved.');
    }

    public function destroy(Request $request, Workspace $workspace, Project $project, ProjectHealthReport $healthReport): RedirectResponse
    {
        $this->scope($workspace, $project);
        $this->authorize('delete', $healthReport);

        $healthReport->delete();
        [$timezone, $from, $to] = $this->window($request, $request->user(), strict: false);

        return redirect()->route('projects.health', [$workspace->slug, $project->slug, 'tz' => $timezone, 'from' => $from, 'to' => $to])
            ->with('success', 'Status update deleted.');
    }

    private function scope(Workspace $workspace, Project $project): void
    {
        abort_unless($project->workspace_id === $workspace->id, 404);
    }

    /**
     * Time zone and period (local dates) from the request. Writes validate strictly; the page
     * falls back to the defaults (viewer's zone, last 14 days) instead of erroring.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function window(Request $request, User $user, bool $strict = true): array
    {
        $rules = [
            'tz' => 'nullable|string|timezone:all_with_bc',
            'from' => 'nullable|date_format:Y-m-d|after_or_equal:2000-01-01',
            'to' => 'nullable|date_format:Y-m-d|before_or_equal:2100-12-31',
        ];
        $input = $request->only(['tz', 'from', 'to']);
        $validator = Validator::make($input, $rules);
        if ($validator->fails()) {
            if ($strict) {
                throw new ValidationException($validator);
            }
            $input = array_diff_key($input, $validator->errors()->messages());
        }

        $timezone = $input['tz'] ?? $user->localTimezone();
        $today = CarbonImmutable::now($timezone)->toDateString();
        $to = $input['to'] ?? $today;
        $from = $input['from'] ?? CarbonImmutable::parse($to)->subDays(self::DEFAULT_PERIOD_DAYS - 1)->toDateString();

        if ($from > $to || CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) >= self::MAX_PERIOD_DAYS) {
            if ($strict) {
                throw ValidationException::withMessages(['from' => 'Pick a period of up to a year that starts before it ends.']);
            }
            $from = CarbonImmutable::parse($to)->subDays(self::DEFAULT_PERIOD_DAYS - 1)->toDateString();
        }

        return [$timezone, $from, $to];
    }
}
