<?php

namespace App\Http\Requests\AppApi;

use App\Support\Sidebar\SidebarPreferences;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PUT /api/v1/me/pins` (BRAI-391): replace one project's sidebar pins (the web's sidebar rules,
 * SidebarPreferencesController). Pins the user can't open are dropped, not an error.
 */
class UpdatePinsRequest extends FormRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', 'min:1'],
            // the whole list, in sidebar order; [] unpins everything
            'pins' => ['present', 'array', 'max:'.SidebarPreferences::MAX_PINS],
            'pins.*' => ['array:type,id'],
            'pins.*.type' => ['required', Rule::in(SidebarPreferences::PIN_TYPES)],
            'pins.*.id' => ['required', 'integer', 'min:1'],
            // show only the Pinned group of this project
            'pinned_only' => ['sometimes', 'boolean'],
        ];
    }
}
