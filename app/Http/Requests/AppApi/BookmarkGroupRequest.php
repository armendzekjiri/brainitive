<?php

namespace App\Http\Requests\AppApi;

use App\Http\Controllers\AppApi\Concerns\ResolvesVisible;
use App\Models\Bookmark;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A change to a bookmark group or its items (BRAI-399). Authorized before validation: a group the user
 * can't see is a 404, one they can only read a 403 (BookmarkPolicy::update: its creator, editor
 * collaborators and workspace admins).
 */
abstract class BookmarkGroupRequest extends FormRequest
{
    use ResolvesVisible;

    private ?Bookmark $group = null;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->group());
    }

    public function group(): Bookmark
    {
        return $this->group ??= $this->visibleBookmarkGroup($this, (string) $this->route('group'));
    }
}
