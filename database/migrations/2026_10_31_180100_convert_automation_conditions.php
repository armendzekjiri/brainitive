<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * BRAI-144: stored automation conditions {type, value} become {field, operator, value}
 * (board → board is, label → labels has, assignee_empty → assignee is empty, assignee_is →
 * assignee is, title_contains → title contains, …). Self-contained (no app code), idempotent:
 * conditions already in the new shape are left alone, rules without a change aren't written.
 * The rule's version stays: the meaning of a condition doesn't change.
 *
 * down() does nothing on purpose: the new operators (is not, any of, …) have no old form, and
 * AutomationDefinition::normalizeCondition() reads both shapes, so the code works either way.
 */
return new class extends Migration
{
    private const LEGACY = [
        'board' => ['board', 'is'],
        'helpdesk' => ['helpdesk', 'is'],
        'item_type' => ['item_type', 'is'],
        'priority' => ['priority', 'is'],
        'label' => ['labels', 'has'],
        'assignee_empty' => ['assignee', 'is_empty'],
        'assignee_is' => ['assignee', 'is'],
        'column' => ['column', 'is'],
        'title_contains' => ['title', 'contains'],
    ];

    public function up(): void
    {
        DB::table('automation_rules')->select(['id', 'conditions'])->orderBy('id')
            ->chunkById(200, function ($rules) {
                foreach ($rules as $rule) {
                    $conditions = json_decode((string) $rule->conditions, true);
                    if (! is_array($conditions) || $conditions === []) {
                        continue;
                    }
                    $converted = array_map(fn ($c) => is_array($c) ? $this->convert($c) : $c, $conditions);
                    if ($converted !== $conditions) {
                        DB::table('automation_rules')->where('id', $rule->id)
                            ->update(['conditions' => json_encode(array_values($converted))]);
                    }
                }
            });
    }

    public function down(): void
    {
        // intentionally empty, see above
    }

    private function convert(array $condition): array
    {
        if (array_key_exists('field', $condition) || ! array_key_exists('type', $condition)) {
            return $condition;
        }
        $type = (string) $condition['type'];
        [$field, $operator] = self::LEGACY[$type] ?? [$type, 'is'];

        return [
            'field' => $field,
            'operator' => $operator,
            'value' => $operator === 'is_empty' ? null : ($condition['value'] ?? null),
        ];
    }
};
