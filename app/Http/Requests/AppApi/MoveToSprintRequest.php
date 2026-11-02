<?php

namespace App\Http\Requests\AppApi;

use App\Http\Controllers\AppApi\Concerns\ResolvesVisible;
use App\Models\Board;
use App\Models\Sprint;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/v1/sprints/{id}/items` · `POST /api/v1/boards/{id}/backlog/items`: issues to move. Authorized
 * before validation: a board / sprint the user can't open is a 404, planning needs BoardPolicy::update (403),
 * as the backlog page.
 */
class MoveToSprintRequest extends FormRequest
{
    use ResolvesVisible;

    private ?Board $resolvedBoard = null;

    private ?Sprint $resolvedSprint = null;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->board());
    }

    /** The target sprint; null = the backlog. */
    public function sprint(): ?Sprint
    {
        if ($this->route('sprint') === null) {
            return null;
        }

        return $this->resolvedSprint ??= $this->visibleSprint($this, (string) $this->route('sprint'));
    }

    public function board(): Board
    {
        return $this->resolvedBoard ??= $this->sprint()?->board ?? $this->visibleBoard($this, (string) $this->route('board'));
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            // top-level issues of the board (subtasks follow their parent)
            'item_ids' => ['required', 'array', 'min:1', 'max:200'],
            'item_ids.*' => ['integer', 'min:1'],
            'request_id' => ['nullable', 'uuid'],
        ];
    }

    /** @return list<int> */
    public function itemIds(): array
    {
        return array_values(array_unique(array_map('intval', (array) $this->validated('item_ids'))));
    }
}
