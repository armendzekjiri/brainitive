<?php

namespace App\Http\Requests\AppApi;

use App\Http\Controllers\AppApi\Concerns\ResolvesVisible;
use App\Models\Item;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A change to an issue (BRAI-403 / 404). Authorized before validation: an issue the user can't open is
 * a 404 (nothing leaks through 422s), one they may only read a 403 (ItemPolicy::update: project admins /
 * editors and workspace admins).
 */
abstract class IssueRequest extends FormRequest
{
    use ResolvesVisible;

    private ?Item $item = null;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->issue());
    }

    public function issue(): Item
    {
        return $this->item ??= $this->visibleItem($this, (string) $this->route('item'));
    }

    /** The issue while a request is being served; null while the OpenAPI generator reads the rules. */
    protected function issueForRules(): ?Item
    {
        return $this->route('item') !== null ? $this->issue() : null;
    }
}
