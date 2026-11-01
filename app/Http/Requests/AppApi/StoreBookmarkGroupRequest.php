<?php

namespace App\Http\Requests\AppApi;

use App\Models\Bookmark;
use App\Models\Workspace;
use App\Support\Bookmarks\Bookmarks;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/v1/workspaces/{slug}/bookmarks` (BRAI-399): the web's fields, the icon as an emoji. Outside
 * the workspace → 404 before validation; members who aren't editors / admins → 403 (BookmarkPolicy::create).
 */
class StoreBookmarkGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        $workspace = $this->workspace();
        abort_unless($this->user()->can('view', $workspace), 404);

        return $this->user()->can('create', [Bookmark::class, $workspace]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...Bookmarks::groupRules(),
            // an emoji (image icons: web only)
            'icon' => ['nullable', 'string', 'max:10', 'not_regex:#[/\\\\]#'],
            // a uuid: a retry with the same one answers 200 with the group the first call created
            'request_id' => ['nullable', 'uuid'],
        ];
    }

    public function workspace(): Workspace
    {
        /** @var Workspace */
        return $this->route('workspace');
    }
}
