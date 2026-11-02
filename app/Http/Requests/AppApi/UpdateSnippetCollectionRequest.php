<?php

namespace App\Http\Requests\AppApi;

use App\Http\Controllers\AppApi\Concerns\ResolvesVisible;
use App\Models\SnippetCollection;
use App\Support\Snippets\Snippets;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `PATCH /api/v1/snippet-collections/{id}`: only the sent fields change. Not a member → 404; not its
 * creator or a workspace admin → 403 (SnippetPolicy::update).
 */
class UpdateSnippetCollectionRequest extends FormRequest
{
    use ResolvesVisible;

    private ?SnippetCollection $collection = null;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->collection());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return Snippets::collectionRules(update: true);
    }

    public function collection(): SnippetCollection
    {
        return $this->collection ??= $this->visibleSnippetCollection($this, (string) $this->route('collection'));
    }
}
