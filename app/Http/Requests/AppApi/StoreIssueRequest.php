<?php

namespace App\Http\Requests\AppApi;

use App\Http\Controllers\AppApi\Concerns\ResolvesVisible;
use App\Http\Requests\AppApi\Concerns\IssueFields;
use App\Models\Board;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/v1/boards/{id}/items`: a new issue, the issue dialog's rules (Support\Boards\Issues).
 * Authorized before validation: a board the user can't open is a 404, one they may only read a 403
 * (BoardPolicy::createItems).
 */
class StoreIssueRequest extends FormRequest
{
    use IssueFields;
    use ResolvesVisible;

    private ?Board $resolvedBoard = null;

    public function authorize(): bool
    {
        return $this->user()->can('createItems', $this->board());
    }

    public function board(): Board
    {
        return $this->resolvedBoard ??= $this->visibleBoard($this, (string) $this->route('board'));
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $board = $this->route('board') !== null ? $this->board() : null;

        return [
            ...$this->issueRules($board?->project, $board),
            'request_id' => ['nullable', 'uuid'],
        ];
    }

    /** @return array<string, mixed> what Issues::create takes */
    public function issueData(): array
    {
        $board = $this->board();
        $data = $this->issueColumns($this->safe()->except(['request_id']), $board->project);
        $data['column_id'] ??= $board->columns()->value('id');

        return $data;
    }
}
