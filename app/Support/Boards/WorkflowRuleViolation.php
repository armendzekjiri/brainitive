<?php

namespace App\Support\Boards;

use App\Models\BoardColumn;
use App\Models\Item;
use App\Support\AppApi\ValidationWithExtras;

/**
 * A board's workflow rule refuses a move or a create (v1.52.0, WorkflowGuard). A 422 validation error on
 * `column_id` everywhere: the web gets it as a form error (toast), JSON callers as `errors.column_id`, the App
 * API additionally as `workflow_rule` {column_id, column, reason, item_id, item_key}, MCP as the tool error text.
 *
 * `reason`: actor (not allowed to move cards there) | from (not from an allowed column; also creating there) |
 * assignee | subtasks | due_date (a condition isn't met).
 */
class WorkflowRuleViolation extends ValidationWithExtras
{
    public string $reason = '';

    public string $plainMessage = '';

    public static function make(string $reason, string $message, BoardColumn $column, ?Item $item, string $prefix = ''): self
    {
        $e = static::with(['column_id' => [$prefix.$message]], ['workflow_rule' => [
            'column_id' => (int) $column->id,
            'column' => (string) $column->name,
            'reason' => $reason,
            'item_id' => $item?->exists ? (int) $item->id : null,
            'item_key' => $item?->exists ? (string) $item->key : null,
        ]]);
        $e->reason = $reason;
        $e->plainMessage = $message;

        return $e;
    }

    /** The same refusal, worded for an issue that isn't the one the user acted on ("Subtask BRAI-4: …"). */
    public function about(string $prefix): self
    {
        $e = static::with(['column_id' => [$prefix.$this->plainMessage]], $this->extras);
        $e->reason = $this->reason;
        $e->plainMessage = $this->plainMessage;

        return $e;
    }

    /**
     * Several issues refused in one action (bulk edit): "BRAI-3, BRAI-5: Only … can move cards to Done." — one
     * line per distinct reason.
     *
     * @param  list<array{0: Item, 1: self}>  $blocked
     */
    public static function forMany(array $blocked): self
    {
        $groups = [];
        foreach ($blocked as [$item, $violation]) {
            $groups[$violation->plainMessage][] = (string) $item->key;
        }
        $lines = [];
        foreach ($groups as $message => $keys) {
            $shown = array_slice($keys, 0, 5);
            $more = count($keys) - count($shown);
            $lines[] = implode(', ', $shown).($more > 0 ? " and {$more} more" : '').': '.$message;
        }

        $first = $blocked[0][1];
        $e = static::with(['column_id' => [implode(' ', $lines)]], $first->extras);
        $e->reason = $first->reason;
        $e->plainMessage = $first->plainMessage;

        return $e;
    }

    /** The message shown to people. */
    public function text(): string
    {
        return (string) ($this->errors()['column_id'][0] ?? $this->plainMessage);
    }
}
