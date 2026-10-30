<?php

namespace App\Support\Boards;

use App\Models\ItemField;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Custom field definitions of a project (BRAI-77): the rules and the create / update behaviour
 * shared by project settings (`ItemFieldController`) and the MCP tools (v1.47.0). Options are
 * synced, never deleted — issues may still use them.
 */
class ItemFields
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(Project $project): array
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
     * @param  array<string, mixed>  $validated  name, type, board_ids?, options?
     *
     * @throws ValidationException
     */
    public static function create(Project $project, array $validated): ItemField
    {
        if ($project->itemFields()->active()->count() >= ItemField::MAX_FIELDS) {
            throw ValidationException::withMessages(['name' => 'A project can have up to '.ItemField::MAX_FIELDS.' active fields. Archive one first.']);
        }
        self::ensureUniqueName($project, $validated['name']);

        return DB::transaction(function () use ($project, $validated) {
            $field = $project->itemFields()->create([
                'name' => trim($validated['name']),
                'type' => $validated['type'],
                'board_ids' => self::boardIds($validated),
                'position' => ($project->itemFields()->max('position') ?? 0) + 1,
            ]);

            if ($field->type === 'select') {
                self::syncOptions($field, $validated['options'] ?? []);
            }

            return $field;
        });
    }

    /**
     * @param  array<string, mixed>  $validated  name, type?, board_ids?, options?, archived?
     * @return string the message for the person ("Field archived." …)
     *
     * @throws ValidationException
     */
    public static function update(Project $project, ItemField $itemField, array $validated): string
    {
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
            self::ensureUniqueName($project, $validated['name'], $itemField->id);
        }

        DB::transaction(function () use ($itemField, $validated, $type, $archived) {
            $itemField->update([
                'name' => trim($validated['name']),
                'type' => $type,
                // Leaving board_ids out keeps the current boards (null would mean "all boards").
                'board_ids' => array_key_exists('board_ids', $validated) ? self::boardIds($validated) : $itemField->board_ids,
                'archived_at' => $archived ? ($itemField->archived_at ?? now()) : null,
            ]);

            if ($type === 'select' && array_key_exists('options', $validated)) {
                self::syncOptions($itemField, $validated['options'] ?? []);
            } elseif ($type !== 'select') {
                $itemField->options()->reorder()->whereNull('archived_at')->update(['archived_at' => now()]);
            }
        });

        return match (true) {
            $archived && ! $wasArchived => 'Field archived.',
            ! $archived && $wasArchived => 'Field restored.',
            default => 'Field saved.',
        };
    }

    /**
     * Options in the given order: known ids are renamed / recoloured (and restored if they
     * were archived), new ones are created, and options left out are archived — never
     * deleted, because issues may still use them.
     *
     * @param  list<array{id?: int|null, label: string, color?: string|null}>  $options
     *
     * @throws ValidationException
     */
    public static function syncOptions(ItemField $field, array $options): void
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

    /**
     * @param  array<string, mixed>  $validated
     * @return list<int>|null
     */
    private static function boardIds(array $validated): ?array
    {
        $ids = $validated['board_ids'] ?? null;

        return $ids === null ? null : array_values(array_unique(array_map('intval', $ids)));
    }

    private static function ensureUniqueName(Project $project, string $name, ?int $exceptId = null): void
    {
        $taken = $project->itemFields()->active()
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->whereRaw('lower(name) = ?', [mb_strtolower(trim($name))])
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['name' => 'Another field already uses this name.']);
        }
    }
}
