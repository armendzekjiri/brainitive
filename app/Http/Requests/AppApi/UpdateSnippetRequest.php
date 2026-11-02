<?php

namespace App\Http\Requests\AppApi;

use App\Http\Controllers\AppApi\Concerns\ResolvesVisible;
use App\Models\Snippet;
use App\Models\SnippetCollection;
use App\Support\Snippets\Snippets;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `PATCH /api/v1/snippets/{id}`: only the sent fields change. Not a member → 404; not a workspace
 * editor / admin → 403 (SnippetPolicy::create, as the web).
 */
class UpdateSnippetRequest extends FormRequest
{
    use ResolvesVisible;

    private ?Snippet $snippet = null;

    public function authorize(): bool
    {
        return $this->user()->can('create', [SnippetCollection::class, $this->snippet()->collection->workspace]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return Snippets::snippetRules(update: true);
    }

    public function snippet(): Snippet
    {
        return $this->snippet ??= $this->visibleSnippet($this, (string) $this->route('snippet'));
    }
}
