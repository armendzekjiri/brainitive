<?php

namespace App\Support\Plans;

use App\Models\Plan;
use Illuminate\Validation\Rule;

/**
 * Shape of a plan document and its normalisation. A document is a flat outline of rows
 * (`level` = indent), each with any number of bars or milestones, plus finish-to-start
 * dependencies between bars.
 */
class PlanDocument
{
    public const STATUSES = ['not_started', 'in_progress', 'done', 'on_hold', 'at_risk'];

    public const MAX_ROWS = 1000;

    public const MAX_BARS_PER_ROW = 20;

    public const MAX_LEVEL = 8;

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'data' => 'required|array',
            'data.rows' => 'present|array|max:'.self::MAX_ROWS,
            'data.rows.*.id' => 'required|string|max:64',
            'data.rows.*.name' => 'nullable|string|max:500',
            'data.rows.*.level' => 'required|integer|min:0|max:'.self::MAX_LEVEL,
            'data.rows.*.who' => 'nullable|string|max:255',
            'data.rows.*.status' => ['nullable', Rule::in(self::STATUSES)],
            'data.rows.*.notes' => 'nullable|string|max:5000',
            'data.rows.*.collapsed' => 'nullable|boolean',
            'data.rows.*.bars' => 'present|array|max:'.self::MAX_BARS_PER_ROW,
            'data.rows.*.bars.*.id' => 'required|string|max:64',
            'data.rows.*.bars.*.start' => 'required|date_format:Y-m-d',
            'data.rows.*.bars.*.end' => 'required|date_format:Y-m-d',
            'data.rows.*.bars.*.type' => 'required|in:bar,milestone',
            'data.rows.*.bars.*.color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'data.rows.*.bars.*.label' => 'nullable|string|max:255',
            'data.rows.*.bars.*.progress' => 'nullable|integer|min:0|max:100',
            'data.dependencies' => 'present|array|max:2000',
            'data.dependencies.*.id' => 'required|string|max:64',
            'data.dependencies.*.from' => 'required|string|max:64',
            'data.dependencies.*.to' => 'required|string|max:64',
            'settings' => 'nullable|array',
            'settings.zoom' => ['nullable', Rule::in(Plan::ZOOM_LEVELS)],
            'settings.columns' => 'nullable|array',
            'settings.columns.*' => 'boolean',
            'settings.gridWidth' => 'nullable|integer|min:240|max:1400',
            'settings.showWeekends' => 'nullable|boolean',
        ];
    }

    /**
     * Keep only known keys, repair what a client can get slightly wrong (end before start,
     * a jump of several levels, dangling dependencies) and drop duplicate ids.
     *
     * @param  array<string, mixed>  $data  validated against rules()
     * @return array{rows: list<array<string, mixed>>, dependencies: list<array{id: string, from: string, to: string}>}
     */
    public static function normalize(array $data): array
    {
        $rows = [];
        $rowIds = [];
        $barIds = [];
        $previousLevel = -1;

        foreach ($data['rows'] ?? [] as $row) {
            if (isset($rowIds[$row['id']])) {
                continue;
            }
            $rowIds[$row['id']] = true;

            $level = min((int) $row['level'], $previousLevel + 1);
            $previousLevel = $level;

            $bars = [];
            foreach ($row['bars'] ?? [] as $bar) {
                if (isset($barIds[$bar['id']])) {
                    continue;
                }
                $barIds[$bar['id']] = true;

                [$start, $end] = $bar['end'] < $bar['start'] ? [$bar['end'], $bar['start']] : [$bar['start'], $bar['end']];
                $isMilestone = $bar['type'] === 'milestone';
                $bars[] = array_filter([
                    'id' => $bar['id'],
                    'start' => $start,
                    'end' => $isMilestone ? $start : $end,
                    'type' => $bar['type'],
                    'color' => strtoupper($bar['color']),
                    'label' => self::text($bar['label'] ?? null),
                    'progress' => $isMilestone ? null : ($bar['progress'] ?? null),
                ], fn ($value) => $value !== null);
            }

            $rows[] = array_filter([
                'id' => $row['id'],
                'name' => (string) ($row['name'] ?? ''),
                'level' => $level,
                'who' => self::text($row['who'] ?? null),
                'status' => $row['status'] ?? null,
                'notes' => self::text($row['notes'] ?? null),
                'collapsed' => ! empty($row['collapsed']) ? true : null,
                'bars' => $bars,
            ], fn ($value) => $value !== null);
        }

        $dependencies = [];
        $seen = [];
        foreach ($data['dependencies'] ?? [] as $dependency) {
            $key = $dependency['from'].'>'.$dependency['to'];
            if ($dependency['from'] === $dependency['to'] || ! isset($barIds[$dependency['from']], $barIds[$dependency['to']]) || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $dependencies[] = ['id' => $dependency['id'], 'from' => $dependency['from'], 'to' => $dependency['to']];
        }

        return ['rows' => $rows, 'dependencies' => $dependencies];
    }

    /**
     * @param  array<string, mixed>|null  $settings
     * @return array<string, mixed>
     */
    public static function normalizeSettings(?array $settings): array
    {
        $defaults = Plan::defaultSettings();
        $settings ??= [];

        return [
            'zoom' => $settings['zoom'] ?? $defaults['zoom'],
            'columns' => array_replace($defaults['columns'], array_intersect_key($settings['columns'] ?? [], $defaults['columns'])),
            'gridWidth' => $settings['gridWidth'] ?? $defaults['gridWidth'],
            'showWeekends' => $settings['showWeekends'] ?? $defaults['showWeekends'],
        ];
    }

    private static function text(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
