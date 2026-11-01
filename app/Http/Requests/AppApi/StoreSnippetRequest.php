<?php

namespace App\Http\Requests\AppApi;

use App\Http\Controllers\AppApi\Concerns\ResolvesVisible;
use App\Models\SnippetCollection;
use App\Support\Snippets\Snippets;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/v1/snippet-collections/{id}/snippets`: the web's fields. Not a member → 404; not a
 * workspace editor / admin → 403 (SnippetPolicy::create, as the web).
 */
class StoreSnippetRequest extends FormRequest
{
    use ResolvesVisible;

    private ?SnippetCollection $collection = null;

    public function authorize(): bool
    {
        return $this->user()->can('create', [SnippetCollection::class, $this->collection()->workspace]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...Snippets::snippetRules(),
            // a uuid: a retry with the same one answers 200 with the snippet the first call created
            'request_id' => ['nullable', 'uuid'],
        ];
    }

    public function collection(): SnippetCollection
    {
        return $this->collection ??= $this->visibleSnippetCollection($this, (string) $this->route('collection'));
    }
}
