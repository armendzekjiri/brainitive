<?php

namespace App\Http\Requests\AppApi;

use App\Models\SnippetCollection;
use App\Models\Workspace;
use App\Support\Snippets\Snippets;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/v1/workspaces/{slug}/snippet-collections` (BRAI-399): the web's fields. Outside the
 * workspace → 404 before validation; members who aren't editors / admins → 403 (SnippetPolicy::create).
 */
class StoreSnippetCollectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $workspace = $this->workspace();
        abort_unless($this->user()->can('view', $workspace), 404);

        return $this->user()->can('create', [SnippetCollection::class, $workspace]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...Snippets::collectionRules(),
            // a uuid: a retry with the same one answers 200 with the collection the first call created
            'request_id' => ['nullable', 'uuid'],
        ];
    }

    public function workspace(): Workspace
    {
        /** @var Workspace */
        return $this->route('workspace');
    }
}
