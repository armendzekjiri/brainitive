<?php

namespace App\Http\Requests\AppApi;

use Illuminate\Contracts\Validation\ValidationRule;

/** `PUT /pages/{uuid}/collab/draft`: the HTML of the live document, saved as the draft (as a browser does). */
class CollabSaveRequest extends PageRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'epoch' => ['required', 'integer', 'min:0'],
            // the live document's text in the editor's HTML
            'html' => ['present', 'nullable', 'string', 'max:10000000'],
            'name' => ['required', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:2000000'],
            // the Yjs state vector of the document this HTML was made from ({client id: clock})
            'vector' => ['present', 'array'],
            // true once, from the client that loaded the draft into an empty document: records the vector, writes nothing
            'baseline' => ['sometimes', 'boolean'],
        ];
    }
}
