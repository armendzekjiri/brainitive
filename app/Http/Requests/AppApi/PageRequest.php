<?php

namespace App\Http\Requests\AppApi;

use App\Http\Controllers\AppApi\Concerns\ResolvesVisible;
use App\Models\Page;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A change to a doc, whiteboard or folder (BRAI-411). Authorized before validation: a page the user can't
 * see (another project, someone else's unpublished draft) is a 404, one they may only read a 403
 * (PagePolicy::update: project admins / editors and workspace admins).
 */
abstract class PageRequest extends FormRequest
{
    use ResolvesVisible;

    private ?Page $resolvedPage = null;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->page());
    }

    public function page(): Page
    {
        return $this->resolvedPage ??= $this->visiblePage($this, (string) $this->route('uuid'));
    }

    /** The page while a request is being served; null while the OpenAPI generator reads the rules. */
    protected function pageForRules(): ?Page
    {
        return $this->route('uuid') !== null ? $this->page() : null;
    }
}
