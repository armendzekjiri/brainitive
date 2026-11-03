<?php

namespace App\Http\Requests\AppApi;

use App\Support\References\SmartLinks;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** `GET /links/resolve?workspace=&refs[]=&urls[]=`: smart link chips, at most 100 per call. */
class ResolveLinksRequest extends FormRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            // the workspace (slug) of the doc / issue the links are in
            'workspace' => ['required', 'string', 'max:255'],
            // `item:<id>`, `page:<uuid>`, `ticket:<id>`, `plan:<id>` (the `data-smart-link` of the HTML)
            'refs' => ['sometimes', 'array', 'max:'.SmartLinks::MAX_REFS],
            'refs.*' => ['string', 'max:300'],
            // links to this Brainitive (issue, doc, whiteboard, ticket, plan pages) as found in Markdown
            'urls' => ['sometimes', 'array', 'max:'.SmartLinks::MAX_REFS],
            'urls.*' => ['string', 'max:2000'],
        ];
    }
}
