<?php

namespace App\Support\ProjectHealth;

use App\Models\Project;
use Illuminate\Support\Str;

/**
 * The bounded, authorized input for the AI narrative (BRAI-64): numbers plus at most
 * MAX_PER_LIST issues / milestones per list, titles cut short, only this project's content
 * (a blocker in another project appears without id or title). Assignee names are left out;
 * the AI only learns whether an issue has an assignee.
 */
class HealthSnapshot
{
    public const MAX_PER_LIST = 10;

    public const TITLE_LIMIT = 140;

    /**
     * @param  array<string, mixed>  $health  HealthMetrics::compute() for this viewer
     * @return array<string, mixed>
     */
    public static function build(Project $project, array $health): array
    {
        $metrics = [];
        foreach (HealthMetrics::LABELS as $key => $label) {
            $metrics[] = [
                'id' => 'metric:'.$key,
                'label' => $label,
                'value' => $health['values'][$key] ?? 'unknown',
            ] + (isset($health['unknown'][$key]) ? ['note' => $health['unknown'][$key]] : []);
        }

        $issue = fn (array $ref) => array_filter([
            'id' => $ref['ref'],
            'key' => $ref['key'],
            'title' => Str::limit($ref['title'], self::TITLE_LIMIT),
            'due' => $ref['due_date'],
            'assigned' => $ref['assignee'] !== null,
        ], fn ($value) => $value !== null);

        $lists = [];
        foreach (['overdue', 'due_soon', 'unassigned', 'completed'] as $list) {
            $lists[$list] = array_map($issue, array_slice($health['lists'][$list] ?? [], 0, self::MAX_PER_LIST));
        }

        $lists['blocked'] = array_map(fn (array $entry) => $issue($entry['item']) + [
            'blocked_by' => array_map(
                fn (array $blocker) => ($blocker['external'] ?? false) ? 'an open issue in another project' : $blocker['ref'].' '.$blocker['key'].' '.Str::limit($blocker['title'], self::TITLE_LIMIT),
                $entry['blockers'],
            ),
        ], array_slice($health['lists']['blocked'] ?? [], 0, self::MAX_PER_LIST));

        $lists['milestones'] = array_map(fn (array $m) => [
            'id' => $m['ref'],
            'plan' => Str::limit($m['plan'], self::TITLE_LIMIT),
            'milestone' => Str::limit($m['name'], self::TITLE_LIMIT),
            'date' => $m['date'],
            'state' => $m['state'],
        ], array_slice($health['lists']['milestones'] ?? [], 0, self::MAX_PER_LIST));

        return [
            'project' => Str::limit($project->name, self::TITLE_LIMIT),
            'as_of' => $health['as_of'],
            'timezone' => $health['timezone'],
            'today' => $health['today'],
            'period' => $health['period'],
            'metrics' => $metrics,
            'lists' => $lists,
        ];
    }

    /**
     * Every source id the snapshot contains — the only ids a narrative may cite.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, true>
     */
    public static function sourceIds(array $snapshot): array
    {
        $ids = [];
        foreach ($snapshot['metrics'] as $metric) {
            $ids[$metric['id']] = true;
        }
        foreach ($snapshot['lists'] as $entries) {
            foreach ($entries as $entry) {
                $ids[$entry['id']] = true;
                foreach ($entry['blocked_by'] ?? [] as $blocker) {
                    if (preg_match('/^item:\d+/', $blocker, $m)) {
                        $ids[$m[0]] = true;
                    }
                }
            }
        }

        return $ids;
    }
}
