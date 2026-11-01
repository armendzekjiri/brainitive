<?php

namespace App\Http\Requests\AppApi;

use App\Http\Requests\AppApi\Concerns\IssueFields;
use App\Models\Item;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * `PATCH /api/v1/items/{id}`: only the sent fields change (the issue dialog's rules, Support\Boards\Issues).
 * `updated_at` (the issue's, as last read) makes it a conditional write: 409 when it changed since.
 */
class UpdateIssueRequest extends IssueRequest
{
    use IssueFields;

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $item = $this->issueForRules();

        return [
            ...$this->issueRules($item?->board->project, $item?->board, $item ?? new Item),
            // the issue's updated_at as last read: 409 conflict when it changed since
            'updated_at' => ['sometimes', 'date'],
        ];
    }

    /** @return array<string, mixed> what Issues::update takes */
    public function issueData(): array
    {
        return $this->issueColumns($this->safe()->except(['updated_at']), $this->issue()->board->project);
    }
}
