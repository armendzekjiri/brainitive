<?php

namespace App\Http\Controllers;

use App\Models\Board;
use App\Models\Item;
use App\Models\ItemPriority;
use App\Models\ItemType;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Workspace;
use App\Rules\ProjectMember;
use App\Support\Boards\BulkItemEdit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Multi-select actions of the board's list view (BRAI-108): one request changes or deletes up to
 * 200 issues of this board. Each issue is authorized like a single edit / delete.
 */
class BoardBulkItemController extends Controller
{
    /** Fields a bulk edit may change. */
    private const CHANGE_KEYS = ['column_id', 'assigned_to', 'priority_id', 'item_type_id', 'sprint_id', 'due_date', 'add_label_ids', 'remove_label_ids'];

    public function update(Request $request, Workspace $workspace, Project $project, Board $board): RedirectResponse
    {
        $this->authorize('view', $board);

        $validated = $request->validate([
            ...$this->itemIdRules($board),
            'changes' => ['required', 'array:'.implode(',', self::CHANGE_KEYS)],
            'changes.column_id' => ['sometimes', 'required', 'integer', Rule::exists('board_columns', 'id')->where('board_id', $board->id)],
            'changes.assigned_to' => ['sometimes', 'nullable', 'integer', new ProjectMember($project)],
            'changes.priority_id' => ['sometimes', 'required', 'integer', Rule::in(ItemPriority::forProject($project)->pluck('id')->all())],
            // the types the board offers: the ones enabled for the project (BoardController::show)
            'changes.item_type_id' => ['sometimes', 'required', 'integer', Rule::in(ItemType::selectableIdsFor($project))],
            'changes.sprint_id' => ['sometimes', 'nullable', 'integer', Rule::exists('sprints', 'id')->where('board_id', $board->id)->whereIn('status', [Sprint::STATUS_PLANNED, Sprint::STATUS_ACTIVE])],
            'changes.due_date' => ['sometimes', 'nullable', 'date'],
            'changes.add_label_ids' => ['sometimes', 'array', 'max:50'],
            'changes.add_label_ids.*' => ['integer', 'distinct', Rule::exists('labels', 'id')->where('project_id', $project->id)],
            'changes.remove_label_ids' => ['sometimes', 'array', 'max:50'],
            'changes.remove_label_ids.*' => ['integer', 'distinct', Rule::exists('labels', 'id')->where('project_id', $project->id)],
        ], [
            'changes.required' => 'Choose what to change.',
        ]);

        // Empty label lists change nothing
        $changes = array_filter($validated['changes'], fn ($value, $key) => ! str_ends_with($key, '_label_ids') || $value !== [], ARRAY_FILTER_USE_BOTH);
        if ($changes === []) {
            throw ValidationException::withMessages(['changes' => 'Choose what to change.']);
        }
        if (array_key_exists('sprint_id', $changes) && ! $board->isScrum()) {
            throw ValidationException::withMessages(['changes.sprint_id' => 'Only Scrum boards have sprints.']);
        }

        $items = $this->items($board, $validated['item_ids']);
        // ItemPolicy::update depends only on the issue's project, the same for every issue of this
        // board: one check covers them all (200 checks would be ~400 role queries)
        $this->authorize('update', $items->first());

        // A subtask needs a parent, as in the issue dialog
        $subtaskTypeId = ItemType::forProject($project)->where('slug', 'subtask')->value('id');
        if (isset($changes['item_type_id']) && $subtaskTypeId && (int) $changes['item_type_id'] === (int) $subtaskTypeId
            && $items->contains(fn (Item $item) => $item->parent_id === null)) {
            throw ValidationException::withMessages(['changes.item_type_id' => 'Subtasks must have a parent task.']);
        }

        $result = BulkItemEdit::apply($board, $items, $changes, $request->user());

        $message = $result['updated'] === 0
            ? 'Nothing to change.'
            : 'Updated '.$result['updated'].' '.Str::plural('issue', $result['updated']).'.';
        if ($result['skipped_subtasks'] > 0) {
            $message .= ' '.$result['skipped_subtasks'].' '.Str::plural('subtask', $result['skipped_subtasks']).' follow their parent’s sprint.';
        }

        return back()->with('success', $message);
    }

    public function destroy(Request $request, Workspace $workspace, Project $project, Board $board): RedirectResponse
    {
        $this->authorize('view', $board);

        $validated = $request->validate($this->itemIdRules($board));

        $items = $this->items($board, $validated['item_ids']);
        // Admins may delete any issue (one check); everyone else only what they created (per issue)
        $probe = (new Item(['created_by' => 0]))->setRelation('board', $board);
        if ($request->user()->cannot('delete', $probe)) {
            foreach ($items as $item) {
                $this->authorize('delete', $item);
            }
        }

        $deleted = BulkItemEdit::delete($items);

        return back()->with('success', 'Deleted '.$deleted.' '.Str::plural('issue', $deleted).'.');
    }

    /**
     * @return array<string, mixed>
     */
    private function itemIdRules(Board $board): array
    {
        return [
            'item_ids' => ['required', 'array', 'min:1', 'max:'.BulkItemEdit::MAX_ITEMS],
            // existence on this board is checked by items() with one query, not one per id
            'item_ids.*' => ['integer', 'distinct'],
        ];
    }

    /**
     * The issues in the order they were sent (the list's order), with what the policies need.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Item>
     */
    private function items(Board $board, array $ids): Collection
    {
        $order = array_flip(array_map('intval', $ids));

        $items = Item::where('board_id', $board->id)
            ->whereIn('id', array_keys($order))
            ->with(['column', 'labels:id', 'board.project.workspace'])
            ->get();

        // Every id must be a (not deleted) issue of this board
        if ($items->count() !== count($order)) {
            throw ValidationException::withMessages(['item_ids' => 'Some of the selected issues are not on this board any more. Reload and try again.']);
        }

        return $items->sortBy(fn (Item $item) => $order[$item->id])->values();
    }
}
