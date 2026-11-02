<?php

namespace App\Http\Requests\AppApi;

use App\Support\Navigation\RecentDestinations;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `GET /api/v1/me/navigation` (BRAI-391): the sidebar of one workspace, plus the app's own recent
 * destinations to resolve (`recent[]=board:12`, most recent first; like the web's command palette,
 * the apps keep that list on the device).
 */
class NavigationRequest extends FormRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            // the workspace's slug
            'workspace' => ['required', 'string', 'max:255'],
            'recent' => ['sometimes', 'array', 'max:'.RecentDestinations::MAX],
            // "<type>:<id>", type one of project, board, page, item, plan
            'recent.*' => ['string', 'regex:/^('.implode('|', RecentDestinations::TYPES).'):[1-9][0-9]{0,18}$/'],
        ];
    }

    /** @return list<array{type: string, id: int}> */
    public function recent(): array
    {
        return array_map(function (string $entry) {
            [$type, $id] = explode(':', $entry, 2);

            return ['type' => $type, 'id' => (int) $id];
        }, array_values((array) $this->validated('recent', [])));
    }
}
