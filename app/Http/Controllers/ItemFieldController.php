<?php

namespace App\Http\Controllers;

use App\Models\ItemField;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Custom field definitions for a project's board issues (BRAI-77), managed in project
 * settings by project admins (ProjectPolicy::update). Values: BoardController@updateItemFields.
 */
class ItemFieldController extends Controller
{
    public function store(Request $request, Workspace $workspace, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $validated = $request->validate($this->rules($project) + [
            'type' => ['required', Rule::in(ItemField::TYPES)],
        ]);

        if ($project->itemFields()->active()->count() >= ItemField::MAX_FIELDS) {
            throw ValidationException::withMessages(['name' => 'A project can have up to '.ItemField::MAX_FIELDS.' active fields. Archive one first.']);
        }
        $this->ensureUniqueName($project, $validated['name']);

        DB::transaction(function () use ($project, $validated) {
            $field = $project->itemFields()->create([
                'name' => trim($validated['name']),
                'type' => $validated['type'],
                'board_ids' => $this->boardIds($validated),
                'position' => ($project->itemFields()->max('position') ?? 0) + 1,
            ]);

            if ($field->type === 'select') {
                $this->syncOptions($field, $validated['options'] ?? []);
            }
        });

        return back()->with('success', 'Field created.');
    }

    public function update(Request $request, Workspace $workspace, Project $project, ItemField $itemField): RedirectResponse
    {
        $this->authorize('update', $project);
        abort_unless($itemField->project_id === $project->id, 404);

        $validated = $request->validate($this->rules($project) + [
            'type' => ['sometimes', Rule::in(ItemField::TYPES)],
            'archived' => 'sometimes|boolean',
        ]);

        $type = $validated['type'] ?? $itemField->type;
        // Values are stored per type; converting them is not supported in v1.
        if ($type !== $itemField->type && $itemField->values()->exists()) {
            throw ValidationException::withMessages(['type' => 'This field already has values, so its type can\'t change. Create a new field and archive this one.']);
        }

        $wasArchived = $itemField->isArchived();
        $archived = $validated['archived'] ?? $wasArchived;
        if (! $archived && $wasArchived && $project->itemFields()->active()->count() >= ItemField::MAX_FIELDS) {
            throw ValidationException::withMessages(['archived' => 'A project can have up to '.ItemField::MAX_FIELDS.' active fields. Archive one first.']);
        }
        if (! $archived) {
            $this->ensureUniqueName($project, $validated['name'], $itemField->id);
        }

        DB::transaction(function () use ($itemField, $validated, $type, $archived) {
            $itemField->update([
                'name' => trim($validated['name']),
                'type' => $type,
                // Leaving board_ids out keeps the current boards (null would mean "all boards").
                'board_ids' => array_key_exists('board_ids', $validated) ? $this->boardIds($validated) : $itemField->board_ids,
                'archived_at' => $archived ? ($itemField->archived_at ?? now()) : null,
            ]);

            if ($type === 'select' && array_key_exists('options', $validated)) {
                $this->syncOptions($itemField, $validated['options'] ?? []);
            } elseif ($type !== 'select') {
                $itemField->options()->reorder()->whereNull('archived_at')->update(['archived_at' => now()]);
            }
        });

        $message = match (true) {
            $archived && ! $wasArchived => 'Field archived.',
            ! $archived && $wasArchived => 'Field restored.',
            default => 'Field saved.',
        };

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

    /**
     * @return array<string, mixed>
     */
    private function rules(Project $project): array
    {
        return [
            'name' => 'required|string|max:100',
            'board_ids' => 'nullable|array|min:1|max:200',
            // Soft-deleted boards of the project are fine: an archived field may still name one.
            'board_ids.*' => ['integer', Rule::exists('boards', 'id')->where('project_id', $project->id)],
            'options' => 'nullable|array|max:'.ItemField::MAX_OPTIONS,
            'options.*.id' => 'nullable|integer',
            'options.*.label' => 'required|string|max:100',
            'options.*.color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{3,8}$/'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return list<int>|null
     */
    private function boardIds(array $validated): ?array
    {
        $ids = $validated['board_ids'] ?? null;

        return $ids === null ? null : array_values(array_unique(array_map('intval', $ids)));
    }

    private function ensureUniqueName(Project $project, string $name, ?int $exceptId = null): void
    {
        $taken = $project->itemFields()->active()
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->whereRaw('lower(name) = ?', [mb_strtolower(trim($name))])
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['name' => 'Another field already uses this name.']);
        }
    }

    /**
     * Options in the given order: known ids are renamed / recoloured (and restored if they
     * were archived), new ones are created, and options left out are archived — never
     * deleted, because issues may still use them.
     *
     * @param  list<array{id?: int|null, label: string, color?: string|null}>  $options
     */
    private function syncOptions(ItemField $field, array $options): void
    {
        $existing = $field->options()->get()->keyBy('id');
        $labels = [];
        $kept = [];

        foreach (array_values($options) as $position => $option) {
            $label = trim($option['label']);
            $key = mb_strtolower($label);
            if (isset($labels[$key])) {
                throw ValidationException::withMessages(["options.{$position}.label" => 'Each option needs a different name.']);
            }
            $labels[$key] = true;

            $attributes = ['label' => $label, 'color' => $option['color'] ?? null, 'position' => $position + 1, 'archived_at' => null];
            $id = $option['id'] ?? null;
            if ($id !== null) {
                $model = $existing->get((int) $id);
                if (! $model) {
                    throw ValidationException::withMessages(["options.{$position}.id" => 'This option belongs to another field.']);
                }
                $model->update($attributes);
                $kept[] = $model->id;
            } else {
                $kept[] = $field->options()->create($attributes)->id;
            }
        }

        $field->options()->reorder()->whereNotIn('id', $kept)->whereNull('archived_at')->update(['archived_at' => now()]);
    }
}
