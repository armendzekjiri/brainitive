<?php

namespace App\Support\Plans;

use InvalidArgumentException;

/**
 * Apply a bounded list of row / bar / dependency operations to a plan document (MCP). No
 * arbitrary JSON patches: every operation names stable ids, and any invalid operation fails
 * the whole batch (InvalidArgumentException "Operation #n: …"), so nothing is half-applied.
 */
class PlanOperations
{
    public const MAX_OPERATIONS = 100;

    public const DEFAULT_COLOR = '#0E83DC';

    /**
     * @param  array{rows: list<array<string, mixed>>, dependencies: list<array<string, string>>}  $data
     * @param  list<array<string, mixed>>  $operations
     * @return array{data: array, touched_rows: list<string>}
     */
    public static function apply(array $data, array $operations): array
    {
        $rows = array_values($data['rows'] ?? []);
        $deps = array_values($data['dependencies'] ?? []);
        $touched = [];

        foreach (array_values($operations) as $i => $op) {
            $n = $i + 1;
            $fail = fn (string $message) => throw new InvalidArgumentException("Operation #{$n} ({$op['op']}): {$message}");
            $rowIndex = function (?string $id) use (&$rows, $fail): int {
                foreach ($rows as $index => $row) {
                    if ($row['id'] === $id) {
                        return $index;
                    }
                }
                $fail("no row with id \"{$id}\".");
            };
            $barLocation = function (?string $id) use (&$rows, $fail): array {
                foreach ($rows as $r => $row) {
                    foreach ($row['bars'] ?? [] as $b => $bar) {
                        if ($bar['id'] === $id) {
                            return [$r, $b];
                        }
                    }
                }
                $fail("no bar with id \"{$id}\".");
            };

            switch ($op['op']) {
                case 'add_row':
                    $id = $op['row_id'] ?? self::id('r');
                    if (self::rowExists($rows, $id)) {
                        $fail("row id \"{$id}\" already exists.");
                    }
                    $at = isset($op['after_row_id']) ? $rowIndex($op['after_row_id']) + 1 : count($rows);
                    $row = array_filter([
                        'id' => $id,
                        'name' => (string) ($op['name'] ?? ''),
                        'level' => (int) ($op['level'] ?? 0),
                        'who' => $op['who'] ?? null,
                        'status' => $op['status'] ?? null,
                        'notes' => $op['notes'] ?? null,
                        'bars' => [],
                    ], fn ($v) => $v !== null);
                    array_splice($rows, $at, 0, [$row]);
                    $touched[] = $id;
                    break;

                case 'update_row':
                    $r = $rowIndex($op['row_id'] ?? null);
                    foreach (['name', 'level', 'who', 'status', 'notes'] as $field) {
                        if (array_key_exists($field, $op)) {
                            $rows[$r][$field] = $field === 'level' ? (int) $op[$field] : $op[$field];
                        }
                    }
                    $touched[] = $rows[$r]['id'];
                    break;

                case 'remove_row':
                    // Like the editor: a row goes with its sub-rows.
                    $r = $rowIndex($op['row_id'] ?? null);
                    $level = (int) $rows[$r]['level'];
                    $end = $r + 1;
                    while ($end < count($rows) && (int) $rows[$end]['level'] > $level) {
                        $end++;
                    }
                    $removed = array_splice($rows, $r, $end - $r);
                    $barIds = array_merge(...array_map(fn ($row) => array_column($row['bars'] ?? [], 'id'), $removed));
                    $deps = array_values(array_filter($deps, fn ($d) => ! in_array($d['from'], $barIds, true) && ! in_array($d['to'], $barIds, true)));
                    $touched = array_merge($touched, array_column($removed, 'id'));
                    break;

                case 'add_bar':
                    $r = $rowIndex($op['row_id'] ?? null);
                    $id = $op['bar_id'] ?? self::id('b');
                    if (self::barExists($rows, $id)) {
                        $fail("bar id \"{$id}\" already exists.");
                    }
                    $rows[$r]['bars'][] = array_filter([
                        'id' => $id,
                        'start' => $op['start'] ?? $fail('start is required.'),
                        'end' => $op['end'] ?? $op['start'],
                        'type' => $op['type'] ?? 'bar',
                        'color' => $op['color'] ?? self::DEFAULT_COLOR,
                        'label' => $op['label'] ?? null,
                        'progress' => $op['progress'] ?? null,
                    ], fn ($v) => $v !== null);
                    $touched[] = $rows[$r]['id'];
                    break;

                case 'update_bar':
                    [$r, $b] = $barLocation($op['bar_id'] ?? null);
                    foreach (['start', 'end', 'type', 'color', 'label', 'progress'] as $field) {
                        if (array_key_exists($field, $op)) {
                            $rows[$r]['bars'][$b][$field] = $op[$field];
                        }
                    }
                    $touched[] = $rows[$r]['id'];
                    break;

                case 'remove_bar':
                    [$r, $b] = $barLocation($op['bar_id'] ?? null);
                    array_splice($rows[$r]['bars'], $b, 1);
                    $deps = array_values(array_filter($deps, fn ($d) => $d['from'] !== $op['bar_id'] && $d['to'] !== $op['bar_id']));
                    $touched[] = $rows[$r]['id'];
                    break;

                case 'add_dependency':
                    $from = $op['from_bar_id'] ?? null;
                    $to = $op['to_bar_id'] ?? null;
                    $barLocation($from);
                    $barLocation($to);
                    if ($from === $to) {
                        $fail('a bar cannot depend on itself.');
                    }
                    if (self::reachable($deps, $to, $from)) {
                        $fail('this dependency would create a cycle.');
                    }
                    $exists = array_filter($deps, fn ($d) => $d['from'] === $from && $d['to'] === $to);
                    if ($exists === []) {
                        $deps[] = ['id' => self::id('d'), 'from' => $from, 'to' => $to];
                    }
                    break;

                case 'remove_dependency':
                    $before = count($deps);
                    $deps = array_values(array_filter($deps, fn ($d) => ! (
                        (isset($op['dependency_id']) && $d['id'] === $op['dependency_id'])
                        || (isset($op['from_bar_id'], $op['to_bar_id']) && $d['from'] === $op['from_bar_id'] && $d['to'] === $op['to_bar_id'])
                    )));
                    if (count($deps) === $before) {
                        $fail('no such dependency.');
                    }
                    break;

                default:
                    $fail('unknown operation.');
            }
        }

        return ['data' => ['rows' => $rows, 'dependencies' => $deps], 'touched_rows' => array_values(array_unique($touched))];
    }

    private static function id(string $prefix): string
    {
        return $prefix.'-'.substr(bin2hex(random_bytes(6)), 0, 10);
    }

    private static function rowExists(array $rows, string $id): bool
    {
        return in_array($id, array_column($rows, 'id'), true);
    }

    private static function barExists(array $rows, string $id): bool
    {
        foreach ($rows as $row) {
            if (in_array($id, array_column($row['bars'] ?? [], 'id'), true)) {
                return true;
            }
        }

        return false;
    }

    /** Is $target reachable from $start following dependencies? */
    private static function reachable(array $deps, string $start, string $target): bool
    {
        $stack = [$start];
        $seen = [];
        while ($stack !== []) {
            $node = array_pop($stack);
            if ($node === $target) {
                return true;
            }
            if (isset($seen[$node])) {
                continue;
            }
            $seen[$node] = true;
            foreach ($deps as $d) {
                if ($d['from'] === $node) {
                    $stack[] = $d['to'];
                }
            }
        }

        return false;
    }
}
