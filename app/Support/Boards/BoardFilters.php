<?php

namespace App\Support\Boards;

use App\Models\Board;
use App\Models\Item;
use App\Models\ItemField;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The board page's filters in SQL (App API `GET /boards/{id}/items`, BRAI-407). Same params and meaning as
 * the board's URL and its saved views (BoardViewConfig: `q`, `assignee`, `status`, `labels`, `priority`,
 * `type`, `quick`, `f<field id>`), as Pages/Boards/Show.tsx and lib/customFields.ts apply them in the
 * browser — so a saved view's `params` can be sent as they are:
 *  - `q`: title or key contains it; `assignee`: me | unassigned | user-<id>;
 *  - `status` / `labels` / `priority` / `type`: comma-separated ids (any of them; labels: any label);
 *  - `quick`: recent (updated in the last 7 days), due (open, due within 3 days of the user's today or
 *    overdue), blocked (an "is blocked by" link) — all must match;
 *  - `f<id>`: none | any | yes | no | ~text | option ids[,none] | min..max (dates Y-m-d).
 * `sort`, `view` and `hide` are display settings and ignored here.
 */
final class BoardFilters
{
    private const RECENT_DAYS = 7;

    private const DUE_SOON_DAYS = 3;

    /**
     * @param  Builder<Item>  $query
     * @param  array<string, mixed>  $params
     * @return Builder<Item>
     */
    public static function apply(Builder $query, Board $board, array $params, User $user): Builder
    {
        $params = BoardViewConfig::normalize($params)['params'];

        if (isset($params['q'])) {
            $q = '%'.addcslashes(mb_strtolower($params['q']), '%_\\').'%';
            $query->where(fn (Builder $w) => $w->whereRaw('lower(items.title) like ?', [$q])->orWhereRaw('lower(items.key) like ?', [$q]));
        }

        $assignee = $params['assignee'] ?? null;
        if ($assignee === 'me') {
            $query->where('items.assigned_to', $user->id);
        } elseif ($assignee === 'unassigned') {
            $query->whereNull('items.assigned_to');
        } elseif (is_string($assignee) && preg_match('/^user-(\d+)$/', $assignee, $m)) {
            $query->where('items.assigned_to', (int) $m[1]);
        }

        foreach (['status' => 'items.column_id', 'priority' => 'items.priority_id', 'type' => 'items.item_type_id'] as $param => $column) {
            if (isset($params[$param])) {
                $query->whereIn($column, self::ids($params[$param]));
            }
        }
        if (isset($params['labels'])) {
            $query->whereHas('labels', fn (Builder $l) => $l->whereIn('labels.id', self::ids($params['labels'])));
        }

        foreach (explode(',', $params['quick'] ?? '') as $quick) {
            match ($quick) {
                'recent' => $query->where('items.updated_at', '>=', now()->subDays(self::RECENT_DAYS)),
                'due' => $query->whereNotNull('items.due_date')->whereNull('items.completed_at')
                    ->where('items.due_date', '<', now($user->localTimezone())->addDays(self::DUE_SOON_DAYS)->toDateString()),
                'blocked' => $query->where(fn (Builder $b) => $b
                    ->whereHas('outgoingLinks', fn (Builder $l) => $l->where('link_type', 'is_blocked_by'))
                    ->orWhereHas('incomingLinks', fn (Builder $l) => $l->where('link_type', 'blocks'))),
                default => null,
            };
        }

        $fields = null;
        foreach ($params as $key => $value) {
            if (! preg_match('/^f(\d+)$/', $key, $m)) {
                continue;
            }
            $fields ??= ItemField::forBoard($board)->keyBy('id');
            $field = $fields->get((int) $m[1]);
            if ($field instanceof ItemField) {
                self::field($query, $field, $value);
            }
        }

        return $query;
    }

    /**
     * One custom field filter (lib/customFields.ts parseFieldFilter + matchesFieldFilter); a value that
     * doesn't parse for the field's type filters nothing, as on the board.
     *
     * @param  Builder<Item>  $query
     */
    private static function field(Builder $query, ItemField $field, string $raw): void
    {
        $has = fn (?\Closure $where = null) => fn (Builder $q) => $q->whereHas('fieldValues', function (Builder $v) use ($field, $where) {
            $v->where('item_field_id', $field->id);
            if ($where) {
                $where($v);
            }
        });
        $missing = fn (Builder $q) => $q->whereDoesntHave('fieldValues', fn (Builder $v) => $v->where('item_field_id', $field->id));

        if ($raw === 'none') {
            $missing($query);

            return;
        }
        if ($raw === 'any') {
            if (! in_array($field->type, ['select', 'checkbox'], true)) {
                $has()($query);
            }

            return;
        }

        switch ($field->type) {
            case 'select':
                $parts = explode(',', $raw);
                $ids = array_values(array_intersect(array_map('intval', $parts), $field->options->pluck('id')->map(fn ($id) => (int) $id)->all()));
                $empty = in_array('none', $parts, true);
                if ($ids === [] && ! $empty) {
                    return;
                }
                $query->where(function (Builder $q) use ($has, $missing, $ids, $empty) {
                    if ($ids !== []) {
                        $q->orWhere($has(fn (Builder $v) => $v->whereIn('option_id', $ids)));
                    }
                    if ($empty) {
                        $q->orWhere($missing);
                    }
                });

                return;
            case 'checkbox':
                if ($raw === 'yes' || $raw === 'no') {
                    $has(fn (Builder $v) => $v->where('value_bool', $raw === 'yes'))($query);
                }

                return;
            case 'text':
                if (str_starts_with($raw, '~') && mb_strlen($raw) > 1) {
                    $like = '%'.addcslashes(mb_strtolower(mb_substr($raw, 1)), '%_\\').'%';
                    $has(fn (Builder $v) => $v->whereRaw('lower(value_text) like ?', [$like]))($query);
                }

                return;
            case 'number':
            case 'date':
                $parts = explode('..', $raw);
                if (count($parts) !== 2) {
                    return;
                }
                $valid = fn (string $v) => $field->type === 'number' ? $v !== '' && is_numeric($v) : (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $v);
                $min = $parts[0] !== '' && $valid($parts[0]) ? $parts[0] : null;
                $max = $parts[1] !== '' && $valid($parts[1]) ? $parts[1] : null;
                if ($min === null && $max === null) {
                    return;
                }
                $column = $field->type === 'number' ? 'value_number' : 'value_date';
                $has(function (Builder $v) use ($column, $min, $max, $field) {
                    $cast = fn (string $x) => $field->type === 'number' ? (float) $x : $x;
                    if ($min !== null) {
                        $v->where($column, '>=', $cast($min));
                    }
                    if ($max !== null) {
                        $v->where($column, '<=', $cast($max));
                    }
                })($query);
        }
    }

    /** @return list<int> */
    private static function ids(string $value): array
    {
        return array_values(array_filter(array_map('intval', explode(',', $value)), fn (int $id) => $id > 0));
    }
}
