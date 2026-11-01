<?php

namespace App\Http\Requests\AppApi;

use App\Support\Boards\ItemLinks;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/** `POST /api/v1/items/{id}/links`: link this issue to another one the user can open (ItemPolicy::update). */
class StoreIssueLinkRequest extends IssueRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            // the other issue (any project the user can open; GET /items/by-key/{KEY} finds it by key)
            'target_id' => ['required', 'integer', 'min:1'],
            // read from this issue's side: "blocks" = this issue blocks the other one
            'type' => ['required', 'string', Rule::in(ItemLinks::types())],
        ];
    }
}
