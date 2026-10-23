<?php

namespace App\Support\Boards;

use App\Models\Board;
use App\Models\ItemField;
use App\Models\ItemPriority;
use App\Models\ItemType;
use App\Models\User;

/**
 * Saved board view configuration (BRAI-78) = the board's own URL view params, versioned:
 *   { version: 1, params: { q, assignee, status, labels, priority, type, quick, sort, view, hide, f<id> } }
 * (hide = custom-field columns hidden in the list view)
 * Same formats the board page reads (Pages/Boards/Show.tsx, lib/customFields.ts) — no second
 * filter language. normalize() allowlists keys + formats on save; sanitize() drops what no longer
 * exists on the board when a view is opened (deleted columns, labels, fields, members …).
 */
class BoardViewConfig
{
    public const VERSION = 1;

    public const ID_LISTS = ['status', 'labels', 'priority', 'type'];

    public const QUICK = ['recent', 'due', 'blocked'];

    public const VIEWS = ['kanban', 'list', 'gantt', 'calendar'];

    public const SORT_KEYS = ['key', 'title', 'status', 'priority', 'assignee', 'due_date', 'story_points'];

    /**
     * @param  array<string, mixed>  $params  as the board page sends them
     * @return array{version: int, params: array<string, string>}
     */
    public static function normalize(array $params): array
    {
        $out = [];
        foreach ($params as $key => $value) {
            $key = (string) $key;
            if (! is_scalar($value)) {
                continue;
            }
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }

            if ($key === 'q') {
                $out['q'] = mb_substr($value, 0, 200);
            } elseif ($key === 'assignee') {
                if (in_array($value, ['me', 'unassigned'], true) || preg_match('/^user-\d{1,10}$/', $value)) {
                    $out['assignee'] = $value;
                }
            } elseif (in_array($key, self::ID_LISTS, true) || $key === 'hide') {
                $ids = self::ids($value);
                if ($ids !== []) {
                    $out[$key] = implode(',', $ids);
                }
            } elseif ($key === 'quick') {
                $quick = array_values(array_intersect(self::QUICK, explode(',', $value)));
                if ($quick !== []) {
                    $out['quick'] = implode(',', $quick);
                }
            } elseif ($key === 'view') {
                if (in_array($value, self::VIEWS, true)) {
                    $out['view'] = $value;
                }
            } elseif ($key === 'sort') {
                if (preg_match('/^-?(?:'.implode('|', self::SORT_KEYS).'|f\d{1,10})$/', $value)) {
                    $out['sort'] = $value;
                }
            } elseif (preg_match('/^f\d{1,10}$/', $key)) {
                // custom-field filter: none | any | yes | no | ~text | ids[,none] | min..max
                if (mb_strlen($value) <= 200 && preg_match('/^(none|any|yes|no|~.+|[\d,]*(none)?|[\w.:-]*\.\.[\w.:-]*)$/u', $value)) {
                    $out[$key] = $value;
                }
            }
        }
        ksort($out);

        return ['version' => self::VERSION, 'params' => $out];
    }

    /**
     * The params of a stored config, reduced to what still exists on the board.
     *
     * @return array{params: array<string, string>, dropped: list<string>}
     */
    public static function sanitize(array $config, Board $board): array
    {
        $params = self::normalize((array) ($config['params'] ?? []))['params'];
        $dropped = [];
        $project = $board->project;

        $known = [
            'status' => $board->columns()->pluck('id')->all(),
            'labels' => $project->labels()->pluck('id')->all(),
            'priority' => ItemPriority::forProject($project)->pluck('id')->all(),
            'type' => ItemType::forProject($project)->pluck('id')->all() ?: ItemType::pluck('id')->all(),
        ];
        $names = ['status' => 'columns', 'labels' => 'labels', 'priority' => 'priorities', 'type' => 'issue types'];
        foreach (self::ID_LISTS as $key) {
            if (! isset($params[$key])) {
                continue;
            }
            $ids = self::ids($params[$key]);
            $keep = array_values(array_intersect($ids, $known[$key]));
            if (count($keep) !== count($ids)) {
                $dropped[] = $names[$key];
            }
            if ($keep === []) {
                unset($params[$key]);
            } else {
                $params[$key] = implode(',', $keep);
            }
        }

        if (isset($params['assignee']) && preg_match('/^user-(\d+)$/', $params['assignee'], $m)) {
            $member = User::find((int) $m[1]);
            if (! $member || ! $member->hasProjectAccess($project)) {
                unset($params['assignee']);
                $dropped[] = 'a member who left the project';
            }
        }

        $fields = ItemField::forBoard($board)->keyBy('id');
        foreach (array_keys($params) as $key) {
            if (! preg_match('/^f(\d+)$/', $key, $m)) {
                continue;
            }
            $field = $fields->get((int) $m[1]);
            if (! $field) {
                unset($params[$key]);
                $dropped[] = 'a removed field';

                continue;
            }
            if ($field->type === 'select' && preg_match('/^[\d,]*(none)?$/', $params[$key])) {
                $optionIds = $field->options->pluck('id')->all();
                $parts = array_values(array_filter(explode(',', $params[$key]), fn ($p) => $p !== ''));
                $keep = array_values(array_filter($parts, fn ($p) => $p === 'none' || in_array((int) $p, $optionIds, true)));
                if (count($keep) !== count($parts)) {
                    $dropped[] = 'removed field options';
                }
                if ($keep === []) {
                    unset($params[$key]);
                } else {
                    $params[$key] = implode(',', $keep);
                }
            }
        }

        if (isset($params['hide'])) {
            // hidden list columns of removed fields simply don't matter any more
            $hide = array_values(array_intersect(self::ids($params['hide']), $fields->keys()->all()));
            if ($hide === []) {
                unset($params['hide']);
            } else {
                $params['hide'] = implode(',', $hide);
            }
        }

        if (isset($params['sort']) && preg_match('/^-?f(\d+)$/', $params['sort'], $m) && ! $fields->has((int) $m[1])) {
            unset($params['sort']);
            $dropped[] = 'a removed field';
        }

        return ['params' => $params, 'dropped' => array_values(array_unique($dropped))];
    }

    /** @return list<int> */
    private static function ids(string $value): array
    {
        $ids = array_map('intval', array_filter(explode(',', $value), fn ($p) => ctype_digit(trim($p))));

        return array_slice(array_values(array_unique(array_filter($ids, fn ($id) => $id > 0))), 0, 100);
    }
}
