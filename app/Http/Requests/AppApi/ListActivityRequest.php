<?php

namespace App\Http\Requests\AppApi;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;

/** `GET /api/v1/items/{id}/activity`: a cursor page of the history (entries never change: no updated_since). */
class ListActivityRequest extends ListRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return Arr::except(parent::rules(), 'updated_since');
    }
}
