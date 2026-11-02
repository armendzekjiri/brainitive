<?php

namespace App\Http\Requests\AppApi;

use App\Http\Requests\AppApi\Concerns\PageFields;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Validator;

/**
 * `PATCH /pages/{uuid}`: only the sent fields change. A doc's text is replaced as a whole, so `markdown` needs
 * the `revision` the app last read (409 when the page changed since — live editors save every few seconds).
 */
class UpdatePageRequest extends PageRequest
{
    use PageFields;

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'icon' => ['sometimes', ...self::iconRules()],
            // docs only; replaces the whole text
            'markdown' => ['sometimes', ...self::markdownRules()],
            // the page's `revision` as last read: 409 conflict when it changed since (required with `markdown`)
            'revision' => ['required_with:markdown', 'nullable', 'integer', 'min:0'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $page = $this->pageForRules();
            if ($page !== null && $this->has('markdown') && ($page->isFolder() || $page->is_whiteboard)) {
                $validator->errors()->add('markdown', 'Only docs have a text.');
            }
        }];
    }

    public function revision(): ?int
    {
        $revision = $this->validated('revision');

        return $revision === null ? null : (int) $revision;
    }
}
