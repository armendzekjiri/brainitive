<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A board's workflow rule for one target column (v1.52.0): who may move cards into the column (listed
 * members OR roles; neither = anyone who may edit the board's cards), from which columns of the board
 * (none = from anywhere), and conditions the card must meet. Written through Support\Boards\WorkflowRules,
 * enforced by Support\Boards\WorkflowGuard.
 */
class BoardColumnRule extends Model
{
    protected $fillable = [
        'board_id',
        'board_column_id',
        'allowed_roles',
        'restrict_who',
        'require_assignee',
        'require_subtasks_done',
        'require_due_date',
    ];

    protected function casts(): array
    {
        return [
            'allowed_roles' => 'array',
            'restrict_who' => 'boolean',
            'require_assignee' => 'boolean',
            'require_subtasks_done' => 'boolean',
            'require_due_date' => 'boolean',
        ];
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    public function column(): BelongsTo
    {
        return $this->belongsTo(BoardColumn::class, 'board_column_id');
    }

    /** Members who may move cards into the column. */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'board_column_rule_user');
    }

    /** The columns cards may come from. */
    public function fromColumns(): BelongsToMany
    {
        return $this->belongsToMany(BoardColumn::class, 'board_column_rule_from_column');
    }

    /** @return list<string> */
    public function roles(): array
    {
        return array_values(array_map('strval', (array) ($this->allowed_roles ?? [])));
    }

    /** True when the rule restricts nothing (the editor deletes it instead of saving it). */
    public function isEmpty(): bool
    {
        return ! $this->restrict_who
            && $this->roles() === []
            && $this->users->isEmpty()
            && $this->fromColumns->isEmpty()
            && ! $this->require_assignee && ! $this->require_subtasks_done && ! $this->require_due_date;
    }
}
