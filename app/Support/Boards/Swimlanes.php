<?php

namespace App\Support\Boards;

use App\Models\Board;
use App\Models\ItemPriority;
use App\Models\ItemType;
use App\Models\Release;
use Illuminate\Support\Str;

/**
 * Swimlane setup of a board (board.settings.swimlanes). Custom lanes are ordered rules —
 * a card goes into the first lane whose conditions all match (any selected value per
 * condition); everything else lands in the last lane. The board page evaluates the rules
 * (resources/js/lib/swimlanes.ts); this class validates and cleans what gets stored.
 */
class Swimlanes
{
    public const MODES = ['none', 'custom', 'assignee', 'priority', 'type', 'release'];

    public const MAX_LANES = 20;

    public const COLORS = ['#ef4444', '#f97316', '#eab308', '#22c55e', '#0ea5e9', '#6366f1', '#a855f7', '#ec4899', '#64748b'];

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'mode' => 'required|in:'.implode(',', self::MODES),
            'rest_name' => 'nullable|string|max:60',
            'lanes' => 'present|array|max:'.self::MAX_LANES,
            'lanes.*.id' => 'nullable|string|max:40',
            'lanes.*.name' => 'required|string|max:60',
            'lanes.*.color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'lanes.*.rules' => 'nullable|array',
            'lanes.*.rules.priority_ids' => 'nullable|array|max:50',
            'lanes.*.rules.priority_ids.*' => 'integer',
            'lanes.*.rules.type_ids' => 'nullable|array|max:50',
            'lanes.*.rules.type_ids.*' => 'integer',
            'lanes.*.rules.label_ids' => 'nullable|array|max:100',
            'lanes.*.rules.label_ids.*' => 'integer',
            'lanes.*.rules.assignee_ids' => 'nullable|array|max:200',
            'lanes.*.rules.assignee_ids.*' => 'integer|min:0', // 0 = unassigned
            'lanes.*.rules.release_ids' => 'nullable|array|max:100',
            'lanes.*.rules.release_ids.*' => 'integer|min:0', // 0 = no fix version
            'lanes.*.rules.overdue' => 'nullable|boolean',
        ];
    }

    /**
     * Keep only ids that belong to the board's project, so a lane never refers to (or reveals)
     * another project's labels, people or releases.
     *
     * @param  array<string, mixed>  $input  validated against rules()
     * @return array<string, mixed>
     */
    public static function normalize(Board $board, array $input): array
    {
        $project = $board->project;
        $allowed = [
            'priority_ids' => ItemPriority::forProject($project)->pluck('id')->all(),
            'type_ids' => ItemType::forProject($project)->pluck('id')->all(),
            'label_ids' => $project->labels()->pluck('id')->all(),
            'assignee_ids' => [0, ...$project->users()->pluck('users.id')->all(), ...$project->workspace->users()->pluck('users.id')->all()],
            'release_ids' => [0, ...Release::where('project_id', $project->id)->pluck('id')->all()],
        ];

        $lanes = [];
        foreach ($input['lanes'] ?? [] as $lane) {
            $rules = [];
            foreach ($allowed as $key => $ids) {
                $values = array_values(array_unique(array_map('intval', $lane['rules'][$key] ?? [])));
                $values = array_values(array_intersect($values, $ids));
                if ($values !== []) {
                    $rules[$key] = $values;
                }
            }
            if (! empty($lane['rules']['overdue'])) {
                $rules['overdue'] = true;
            }

            $lanes[] = [
                'id' => preg_match('/^[A-Za-z0-9_-]{1,40}$/', (string) ($lane['id'] ?? '')) ? $lane['id'] : 'lane-'.Str::lower(Str::random(8)),
                'name' => trim($lane['name']),
                'color' => isset($lane['color']) ? strtolower($lane['color']) : null,
                'rules' => (object) $rules,
            ];
        }

        return [
            'mode' => $input['mode'],
            'rest_name' => trim((string) ($input['rest_name'] ?? '')) ?: 'Everything else',
            'lanes' => $lanes,
        ];
    }
}
