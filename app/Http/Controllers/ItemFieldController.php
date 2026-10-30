<?php

namespace App\Http\Controllers;

use App\Models\ItemField;
use App\Models\Project;
use App\Models\Workspace;
use App\Support\Boards\ItemFields;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Custom field definitions for a project's board issues (BRAI-77), managed in project
 * settings by project admins (ProjectPolicy::update). Values: BoardController@updateItemFields.
 * The behaviour lives in `App\Support\Boards\ItemFields` (shared with the MCP tools).
 */
class ItemFieldController extends Controller
{
    public function store(Request $request, Workspace $workspace, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $validated = $request->validate(ItemFields::rules($project) + [
            'type' => ['required', Rule::in(ItemField::TYPES)],
        ]);

        ItemFields::create($project, $validated);

        return back()->with('success', 'Field created.');
    }

    public function update(Request $request, Workspace $workspace, Project $project, ItemField $itemField): RedirectResponse
    {
        $this->authorize('update', $project);
        abort_unless($itemField->project_id === $project->id, 404);

        $validated = $request->validate(ItemFields::rules($project) + [
            'type' => ['sometimes', Rule::in(ItemField::TYPES)],
            'archived' => 'sometimes|boolean',
        ]);

        $message = ItemFields::update($project, $itemField, $validated);

        return back()->with('success', $message);
    }

    /**
     * Save the order of the project's fields (ids in display order).
     */
    public function reorder(Request $request, Workspace $workspace, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'ids' => 'required|array|max:500',
            'ids.*' => ['integer', Rule::exists('item_fields', 'id')->where('project_id', $project->id)],
        ]);

        DB::transaction(function () use ($validated) {
            foreach (array_values($validated['ids']) as $position => $id) {
                ItemField::whereKey($id)->update(['position' => $position + 1]);
            }
        });

        return back();
    }

    /**
     * Delete a field that was never used. Fields with values are archived instead, so no
     * data disappears silently.
     */
    public function destroy(Workspace $workspace, Project $project, ItemField $itemField): RedirectResponse
    {
        $this->authorize('update', $project);
        abort_unless($itemField->project_id === $project->id, 404);

        if ($itemField->values()->exists()) {
            throw ValidationException::withMessages(['field' => 'This field has values on issues. Archive it instead.']);
        }

        $itemField->delete();

        return back()->with('success', 'Field deleted.');
    }
}
