<?php

namespace App\Http\Requests\AppApi;

use App\Http\Controllers\AppApi\Concerns\ResolvesVisible;
use App\Http\Requests\AppApi\Concerns\PageFields;
use App\Models\Page;
use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * `POST /projects/{id}/pages`: a new doc, whiteboard or folder. Authorized before validation: a project the
 * user can't open is a 404, one where they may only read a 403 (PagePolicy::create).
 */
class StorePageRequest extends FormRequest
{
    use PageFields;
    use ResolvesVisible;

    private ?Project $resolvedProject = null;

    private ?Page $resolvedParent = null;

    public function authorize(): bool
    {
        return $this->user()->can('create', [Page::class, $this->project()]);
    }

    public function project(): Project
    {
        return $this->resolvedProject ??= $this->visibleProject($this, (string) $this->route('project'));
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // default doc
            'kind' => ['sometimes', 'string', 'in:doc,whiteboard,folder'],
            // a folder or page of this project the user can see; leave out for the top level
            'parent_uuid' => ['nullable', 'uuid'],
            'icon' => self::iconRules(),
            // docs only
            'markdown' => self::markdownRules(),
            // make it visible to the project right away (docs and whiteboards; default: an author-only draft)
            'publish' => ['sometimes', 'boolean'],
            'request_id' => ['nullable', 'uuid'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            if ($this->kind() !== 'doc' && $this->filled('markdown')) {
                $validator->errors()->add('markdown', 'Only docs have a text.');
            }
            if ($this->kind() === 'folder' && $this->boolean('publish')) {
                $validator->errors()->add('publish', 'Folders are not published.');
            }
            if ($this->filled('parent_uuid') && $this->parentPage() === null) {
                $validator->errors()->add('parent_uuid', 'That folder or page doesn\'t exist in this project.');
            }
        }];
    }

    /** @return 'doc'|'whiteboard'|'folder' */
    public function kind(): string
    {
        $kind = $this->input('kind', 'doc');

        return in_array($kind, ['doc', 'whiteboard', 'folder'], true) ? $kind : 'doc';
    }

    public function parentPage(): ?Page
    {
        $uuid = $this->input('parent_uuid');
        if (! is_string($uuid) || $uuid === '') {
            return null;
        }

        return $this->resolvedParent ??= $this->project()->allPages()->visibleTo($this->user())->where('uuid', $uuid)->first();
    }
}
