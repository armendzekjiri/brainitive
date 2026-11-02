<?php

namespace App\Http\Resources\AppApi;

use App\Models\ItemField;
use App\Support\Boards\ItemFieldValues;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A custom field of the issue's board with the issue's value (null = not set). `value`: text → string,
 * number → number, date → Y-m-d, checkbox → bool, select → the option id; `display` is the text the web
 * shows. The controller sets `issue_value` on the field.
 *
 * @mixin ItemField
 */
class IssueFieldValueResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $value = $this->resource->getAttribute('issue_value');

        return [
            'field_id' => (int) $this->id,
            'name' => (string) $this->name,
            // text | number | date | checkbox | select
            'type' => (string) $this->type,
            'value' => $value,
            'display' => ItemFieldValues::display($this->resource, $value),
        ];
    }
}
