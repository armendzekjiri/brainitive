<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Support\Sidebar\SidebarPreferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Saves the customisable sidebar (BRAI-106): hidden top-level entries, per-project groups,
 * pins and "pinned only". Partial updates; the response is the normalised preferences.
 */
class SidebarPreferencesController extends Controller
{
    public function update(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'reset' => 'sometimes|accepted',
            'hidden_nav' => 'sometimes|array|max:'.count(SidebarPreferences::NAV),
            'hidden_nav.*' => ['string', Rule::in(SidebarPreferences::NAV)],
            'project' => 'sometimes|array:id,groups,pins,pinned_only',
            'project.id' => 'required_with:project|integer',
            'project.groups' => 'sometimes|array:'.implode(',', SidebarPreferences::GROUPS),
            'project.groups.*' => 'boolean',
            'project.pins' => 'sometimes|array|max:'.SidebarPreferences::MAX_PINS,
            'project.pins.*' => 'array:type,id',
            'project.pins.*.type' => ['required', Rule::in(SidebarPreferences::PIN_TYPES)],
            'project.pins.*.id' => 'required|integer|min:1',
            'project.pinned_only' => 'sometimes|boolean',
        ]);

        $user = $request->user();

        if ($request->boolean('reset')) {
            $prefs = SidebarPreferences::reset($user);
        } else {
            $project = null;
            if (isset($validated['project'])) {
                $project = Project::find($validated['project']['id']);
                if (! $project || ! $user->can('view', $project)) {
                    throw ValidationException::withMessages(['project.id' => 'This project is not available.']);
                }
            }
            $prefs = SidebarPreferences::apply($user, $validated, $project);
        }

        return $request->header('X-Inertia') || ! $request->expectsJson()
            ? back()
            : response()->json(['sidebarPreferences' => $prefs]);
    }
}
