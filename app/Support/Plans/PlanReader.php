<?php

namespace App\Support\Plans;

use App\Models\Item;
use App\Models\Plan;
use App\Models\User;

/**
 * A Gantt plan as readers get it (rows, bars, dependencies, linked issues), shared by the MCP's get_plan /
 * list_plans and the App API (BRAI-415). A linked issue is only named when the reader may open it.
 */
final class PlanReader
{
    /**
     * @return array{rows: list<array{id: string, name: string, level: int, who: string|null, status: string|null, notes: string|null, collapsed: bool, issue: array{id: int, key: string, title: string}|null, bars: list<array{id: string, start: string, end: string, type: string, color: string|null, label: string|null, progress: int|null}>}>, dependencies: list<array{id: string, from: string, to: string}>}
     */
    public static function read(Plan $plan, User $user): array
    {
        $data = $plan->data ?? ['rows' => [], 'dependencies' => []];
        $items = Item::whereIn('id', PlanIssueSync::linkedIds($data))->with('board.project')->get(['id', 'key', 'title', 'board_id'])
            ->filter(fn (Item $item) => $user->can('view', $item))->keyBy('id');

        $rows = [];
        foreach ($data['rows'] ?? [] as $row) {
            $item = isset($row['itemId']) ? $items->get($row['itemId']) : null;
            $rows[] = [
                'id' => (string) $row['id'],
                'name' => (string) ($row['name'] ?? ''),
                'level' => (int) ($row['level'] ?? 0),
                'who' => self::text($row['who'] ?? null),
                'status' => self::text($row['status'] ?? null),
                'notes' => self::text($row['notes'] ?? null),
                'collapsed' => ! empty($row['collapsed']),
                'issue' => $item ? ['id' => (int) $item->id, 'key' => (string) $item->key, 'title' => (string) $item->title] : null,
                'bars' => array_values(array_map(fn (array $bar) => [
                    'id' => (string) $bar['id'],
                    'start' => (string) $bar['start'],
                    'end' => (string) $bar['end'],
                    'type' => (string) $bar['type'],
                    'color' => self::text($bar['color'] ?? null),
                    'label' => self::text($bar['label'] ?? null),
                    'progress' => isset($bar['progress']) ? (int) $bar['progress'] : null,
                ], $row['bars'] ?? [])),
            ];
        }

        $dependencies = array_values(array_map(
            fn (array $d) => ['id' => (string) $d['id'], 'from' => (string) $d['from'], 'to' => (string) $d['to']],
            $data['dependencies'] ?? [],
        ));

        return ['rows' => $rows, 'dependencies' => $dependencies];
    }

    /**
     * The first bar start and the last bar end (Y-m-d), null for a plan without bars.
     *
     * @return array{start: string|null, end: string|null}
     */
    public static function range(Plan $plan): array
    {
        $bars = collect($plan->data['rows'] ?? [])->flatMap(fn ($row) => $row['bars'] ?? []);

        return ['start' => $bars->min('start'), 'end' => $bars->max('end')];
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
