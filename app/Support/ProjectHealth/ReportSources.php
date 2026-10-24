<?php

namespace App\Support\ProjectHealth;

use App\Models\Board;
use App\Models\Item;
use App\Models\Plan;
use App\Models\Project;
use App\Models\ProjectHealthReport;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Source refs of health reports (BRAI-64): `item:<id>`, `plan:<id>`, `metric:<key>`.
 *
 * - On save, every ref must be a live issue / plan of the report's project or a known metric
 *   (`invalid()`); anything else is rejected.
 * - A saved report keeps ids only. On read, `resolve()` turns refs into titles and links *for
 *   the reader*: deleted, moved or inaccessible sources come back as "Unavailable source"
 *   without title, key or link.
 */
class ReportSources
{
    public const REF_PATTERN = '/^(item|plan|metric):([a-z0-9_]{1,40})$/';

    /**
     * Refs that aren't citable in this project.
     *
     * @param  list<string>  $refs
     * @return list<string>
     */
    public static function invalid(Project $project, array $refs): array
    {
        $byType = self::group($refs);
        $valid = [];

        if ($byType['item'] !== []) {
            $valid['item'] = Item::whereIn('id', $byType['item'])
                ->whereIn('board_id', Board::where('project_id', $project->id)->select('id'))
                ->pluck('id')->all();
        }
        if ($byType['plan'] !== []) {
            $valid['plan'] = Plan::where('project_id', $project->id)->whereIn('id', $byType['plan'])->pluck('id')->all();
        }

        return array_values(array_filter(array_unique($refs), function (string $ref) use ($valid) {
            if (! preg_match(self::REF_PATTERN, $ref, $m)) {
                return true;
            }

            return match ($m[1]) {
                'metric' => ! array_key_exists($m[2], HealthMetrics::LABELS),
                default => ! ctype_digit($m[2]) || ! in_array((int) $m[2], $valid[$m[1]] ?? [], true),
            };
        }));
    }

    /**
     * Titles and links for the refs, as this reader may see them (two queries at most).
     *
     * @param  list<string>  $refs
     * @return array<string, array{ref: string, available: bool, type: string, label: string, url: string|null}>
     */
    public static function resolve(User $reader, array $refs): array
    {
        $byType = self::group($refs);
        $accessible = null;
        $canOpen = function (int $projectId) use (&$accessible, $reader) {
            $accessible ??= array_flip($reader->accessibleProjectIds());

            return isset($accessible[$projectId]);
        };

        $items = $byType['item'] === [] ? collect() : DB::table('items')
            ->join('boards', 'boards.id', '=', 'items.board_id')
            ->join('projects', 'projects.id', '=', 'boards.project_id')
            ->join('workspaces', 'workspaces.id', '=', 'projects.workspace_id')
            ->whereIn('items.id', $byType['item'])
            ->whereNull('items.deleted_at')->whereNull('boards.deleted_at')->whereNull('projects.deleted_at')->whereNull('workspaces.deleted_at')
            ->get(['items.id', 'items.key', 'items.title', 'boards.slug as board_slug', 'projects.id as project_id', 'projects.slug as project_slug', 'workspaces.slug as workspace_slug'])
            ->keyBy('id');

        $plans = $byType['plan'] === [] ? collect() : DB::table('plans')
            ->join('projects', 'projects.id', '=', 'plans.project_id')
            ->join('workspaces', 'workspaces.id', '=', 'projects.workspace_id')
            ->whereIn('plans.id', $byType['plan'])
            ->whereNull('plans.deleted_at')->whereNull('projects.deleted_at')->whereNull('workspaces.deleted_at')
            ->get(['plans.id', 'plans.name', 'plans.slug', 'projects.id as project_id', 'projects.slug as project_slug', 'workspaces.slug as workspace_slug'])
            ->keyBy('id');

        $resolved = [];
        foreach (array_unique($refs) as $ref) {
            if (! preg_match(self::REF_PATTERN, $ref, $m)) {
                continue;
            }
            [$type, $id] = [$m[1], $m[2]];
            $entry = match ($type) {
                'metric' => isset(HealthMetrics::LABELS[$id]) ? ['label' => HealthMetrics::LABELS[$id], 'url' => null] : null,
                'item' => ($row = $items->get((int) $id)) && $canOpen((int) $row->project_id)
                    ? ['label' => $row->key.' '.$row->title, 'url' => route('boards.items.show', [$row->workspace_slug, $row->project_slug, $row->board_slug, $row->id])]
                    : null,
                'plan' => ($row = $plans->get((int) $id)) && $canOpen((int) $row->project_id)
                    ? ['label' => $row->name, 'url' => route('plans.show', [$row->workspace_slug, $row->project_slug, $row->slug])]
                    : null,
            };
            $resolved[$ref] = $entry === null
                ? ['ref' => $ref, 'available' => false, 'type' => $type, 'label' => 'Unavailable source', 'url' => null]
                : ['ref' => $ref, 'available' => true, 'type' => $type] + $entry;
        }

        return $resolved;
    }

    /**
     * What a saved report keeps of the live metrics: numbers, reasons and ids — no titles.
     *
     * @param  array<string, mixed>  $health
     * @return array<string, mixed>
     */
    public static function storedMetrics(array $health): array
    {
        $refs = fn (array $list) => array_values(array_map(fn (array $entry) => $entry['ref'], $list));

        return [
            'values' => $health['values'],
            'unknown' => $health['unknown'],
            'today' => $health['today'],
            'lists' => [
                'overdue' => $refs($health['lists']['overdue']),
                'due_soon' => $refs($health['lists']['due_soon']),
                'unassigned' => $refs($health['lists']['unassigned']),
                'completed' => $refs($health['lists']['completed']),
                // blockers in other projects are kept as a count only: a later reader may not see that project
                'blocked' => array_map(fn (array $entry) => [
                    'item' => $entry['item']['ref'],
                    'blockers' => $refs(array_filter($entry['blockers'], fn (array $blocker) => ! ($blocker['external'] ?? false))),
                    'external_blockers' => count(array_filter($entry['blockers'], fn (array $blocker) => $blocker['external'] ?? false)),
                ], $health['lists']['blocked']),
                'milestones' => array_map(fn (array $m) => ['plan' => $m['ref'], 'date' => $m['date'], 'state' => $m['state']], $health['lists']['milestones']),
            ],
        ];
    }

    /**
     * A saved report for one reader, every source resolved for them.
     *
     * @return array<string, mixed>
     */
    public static function present(ProjectHealthReport $report, User $reader): array
    {
        $sections = $report->sections ?? [];
        $metrics = $report->metrics ?? [];
        $lists = $metrics['lists'] ?? [];

        $refs = [];
        foreach (ProjectHealthReport::SECTIONS as $section) {
            foreach ($sections[$section] ?? [] as $claim) {
                array_push($refs, ...($claim['sources'] ?? []));
            }
        }
        $claimRefs = array_flip(array_filter($refs, 'is_string'));
        foreach (['overdue', 'due_soon', 'unassigned', 'completed'] as $list) {
            array_push($refs, ...($lists[$list] ?? []));
        }
        foreach ($lists['blocked'] ?? [] as $entry) {
            $refs[] = $entry['item'];
            array_push($refs, ...$entry['blockers']);
        }
        foreach ($lists['milestones'] ?? [] as $milestone) {
            $refs[] = $milestone['plan'];
        }

        $sources = self::resolve($reader, array_values(array_filter($refs, 'is_string')));
        // list entries the reader can't open are dropped, not just redacted
        $visible = fn (array $list) => array_values(array_filter($list, fn ($ref) => is_string($ref) && ($sources[$ref]['available'] ?? false)));
        foreach (['overdue', 'due_soon', 'unassigned', 'completed'] as $list) {
            $lists[$list] = $visible($lists[$list] ?? []);
        }
        $lists['blocked'] = array_values(array_map(fn (array $entry) => ['item' => $entry['item'], 'blockers' => $visible($entry['blockers'] ?? [])],
            array_filter($lists['blocked'] ?? [], fn (array $entry) => $sources[$entry['item']]['available'] ?? false)));
        $lists['milestones'] = array_values(array_filter($lists['milestones'] ?? [], fn (array $m) => $sources[$m['plan']]['available'] ?? false));
        // a claim keeps its unavailable sources as "Unavailable source" chips; lists don't need them
        $sources = array_filter($sources, fn (array $source, string $ref) => $source['available'] || isset($claimRefs[$ref]), ARRAY_FILTER_USE_BOTH);

        return [
            'id' => $report->id,
            'status' => $report->status,
            'summary' => $report->summary,
            'sections' => array_map(fn ($claims) => array_values($claims ?? []), array_replace(array_fill_keys(ProjectHealthReport::SECTIONS, []), array_intersect_key($sections, array_flip(ProjectHealthReport::SECTIONS)))),
            'values' => $metrics['values'] ?? [],
            'unknown' => $metrics['unknown'] ?? [],
            'lists' => $lists,
            'sources' => $sources,
            'as_of' => $report->as_of?->toIso8601String(),
            'period' => ['from' => $report->period_start?->toDateString(), 'to' => $report->period_end?->toDateString()],
            'timezone' => $report->timezone,
            'ai_assisted' => $report->ai_assisted,
            'author' => $report->author?->name,
            'created_at' => $report->created_at?->toIso8601String(),
            'can_delete' => $reader->can('delete', $report),
        ];
    }

    /**
     * @param  list<string>  $refs
     * @return array{item: list<int>, plan: list<int>}
     */
    private static function group(array $refs): array
    {
        $byType = ['item' => [], 'plan' => []];
        foreach ($refs as $ref) {
            if (is_string($ref) && preg_match(self::REF_PATTERN, $ref, $m) && isset($byType[$m[1]]) && ctype_digit($m[2]) && strlen($m[2]) <= 18) {
                $byType[$m[1]][] = (int) $m[2];
            }
        }

        return ['item' => array_values(array_unique($byType['item'])), 'plan' => array_values(array_unique($byType['plan']))];
    }
}
