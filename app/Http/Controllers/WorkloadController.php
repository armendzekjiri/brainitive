<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkloadAllocation;
use App\Models\Workspace;
use App\Rules\ProjectMember;
use App\Support\Workload\WorkloadFormat;
use App\Support\Workload\WorkloadGrid;
use App\Support\Workload\WorkloadPlanner;
use App\Support\Workload\WorkloadWeeks;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Workload planning (BRAI-75): a project's weekly grid of declared capacity vs explicitly
 * planned hours. Members view it; planners (project admins, workspace admins) change it.
 */
class WorkloadController extends Controller
{
    public function index(Request $request, Workspace $workspace, Project $project): Response
    {
        $this->authorize('viewAny', [WorkloadAllocation::class, $project]);
        $user = $request->user();
        $canPlan = $user->can('plan', [WorkloadAllocation::class, $project]);

        $span = in_array((int) $request->query('weeks'), WorkloadWeeks::SPANS, true) ? (int) $request->query('weeks') : WorkloadWeeks::DEFAULT_SPAN;
        $from = $request->query('from');
        $from = is_string($from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && checkdate((int) substr($from, 5, 2), (int) substr($from, 8, 2), (int) substr($from, 0, 4))
            && $from >= '2000-01-01' && $from <= '2099-12-31'
            ? WorkloadWeeks::monday($from)
            : WorkloadWeeks::current($user);

        return Inertia::render('Workload/Index', [
            'workspace' => $workspace,
            'project' => $project,
            'from' => $from,
            'span' => $span,
            'spans' => WorkloadWeeks::SPANS,
            'previousFrom' => WorkloadWeeks::add($from, -$span),
            'nextFrom' => WorkloadWeeks::add($from, $span),
            'timezone' => $user->localTimezone(),
            'canPlan' => $canPlan,
            'scope' => 'project',
        ] + WorkloadGrid::build($workspace, $project, $user, $from, $span, $canPlan));
    }

    /**
     * Open issues of the project to plan (search by key or title); the person's own first.
     */
    public function items(Request $request, Workspace $workspace, Project $project): JsonResponse
    {
        $this->authorize('plan', [WorkloadAllocation::class, $project]);
        $validated = $request->validate([
            'user_id' => ['nullable', 'integer', new ProjectMember($project)],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $userId = isset($validated['user_id']) ? (int) $validated['user_id'] : null;
        $search = trim($validated['q'] ?? '');
        $current = WorkloadWeeks::current($request->user());

        $items = WorkloadPlanner::projectItems($project)
            ->whereNull('completed_at')
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->whereLike('items.title', '%'.$search.'%')->orWhereLike('items.key', '%'.$search.'%')))
            ->select(['items.id', 'items.key', 'items.title', 'items.board_id', 'items.parent_id', 'items.assigned_to', 'items.original_estimate', 'items.time_spent'])
            ->addSelect(['open_planned_minutes' => WorkloadAllocation::query()->selectRaw('coalesce(sum(minutes), 0)')
                ->whereColumn('workload_allocations.item_id', 'items.id')->where('workload_allocations.project_id', $project->id)->where('week_start', '>=', $current)])
            ->with(['assignee:id,name'])
            ->when($userId, fn ($q) => $q->orderByRaw('case when items.assigned_to = ? then 0 else 1 end', [$userId]))
            ->orderByDesc('items.updated_at')->orderByDesc('items.id')
            ->limit(20)
            ->get();

        return response()->json($items->map(fn (Item $item) => [
            'id' => $item->id,
            'key' => $item->key,
            'title' => $item->title,
            'is_subtask' => $item->parent_id !== null,
            'assignee' => $item->assignee?->only(['id', 'name']),
            'estimate_minutes' => $item->original_estimate,
            'logged_minutes' => (int) $item->time_spent,
            'remaining_minutes' => WorkloadPlanner::remaining($item),
            'open_planned_minutes' => (int) ($item->open_planned_minutes ?? 0),
        ])->values());
    }

    public function updateCapacity(Request $request, Workspace $workspace, Project $project): RedirectResponse
    {
        $this->authorize('plan', [WorkloadAllocation::class, $project]);
        $validated = $request->validate([
            'user_id' => ['required', 'integer', new ProjectMember($project)],
            'weekly_minutes' => ['present', 'nullable', 'integer', 'min:0', 'max:'.WorkloadPlanner::MAX_MINUTES],
        ]);
        $member = User::findOrFail($validated['user_id']);

        WorkloadPlanner::setCapacity($project, $member, $validated['weekly_minutes'], $request->user());

        return back()->with('success', $validated['weekly_minutes'] === null
            ? "Weekly capacity for {$member->name} cleared."
            : "{$member->name}: ".WorkloadFormat::hours($validated['weekly_minutes']).' per week in this project.');
    }

    public function updateOverride(Request $request, Workspace $workspace, Project $project): RedirectResponse
    {
        $this->authorize('plan', [WorkloadAllocation::class, $project]);
        $validated = $request->validate([
            'user_id' => ['required', 'integer', new ProjectMember($project)],
            'week_start' => ['required', $this->mondayRule()],
            'weeks' => ['nullable', 'integer', 'min:1', 'max:'.WorkloadPlanner::MAX_OVERRIDE_WEEKS],
            'minutes' => ['present', 'nullable', 'integer', 'min:0', 'max:'.WorkloadPlanner::MAX_MINUTES],
            'note' => ['nullable', 'string', 'max:120'],
        ]);
        $member = User::findOrFail($validated['user_id']);
        $weeks = (int) ($validated['weeks'] ?? 1);

        WorkloadPlanner::setOverride($project, $member, $validated['week_start'], $weeks, $validated['minutes'], $validated['note'] ?? null, $request->user());

        $span = $weeks === 1 ? 'this week' : "{$weeks} weeks";

        return back()->with('success', $validated['minutes'] === null
            ? "{$member->name}: default capacity again for {$span}."
            : "{$member->name}: ".WorkloadFormat::hours($validated['minutes'])." available for {$span}.");
    }

    /**
     * Set the planned minutes of an issue for a person and week (0 removes them).
     */
    public function updateAllocation(Request $request, Workspace $workspace, Project $project): RedirectResponse
    {
        $this->authorize('plan', [WorkloadAllocation::class, $project]);
        $validated = $request->validate([
            'item_id' => ['required', 'integer'],
            'user_id' => ['required', 'integer', new ProjectMember($project)],
            'week_start' => ['required', $this->mondayRule()],
            'minutes' => ['required', 'integer', 'min:0', 'max:'.WorkloadPlanner::MAX_MINUTES],
            'expected_minutes' => ['present', 'nullable', 'integer', 'min:0'],
        ]);

        /** @var Item|null $item */
        $item = WorkloadPlanner::projectItems($project)->find($validated['item_id']);
        if (! $item) {
            throw ValidationException::withMessages(['item_id' => 'Pick an issue of this project.']);
        }
        $member = User::findOrFail($validated['user_id']);

        $allocation = WorkloadPlanner::allocate($project, $item, $member, $validated['week_start'], $validated['minutes'], $request->user(), true, $validated['expected_minutes']);

        return back()->with('success', $allocation
            ? "{$item->key}: ".WorkloadFormat::hours($allocation->minutes)." planned for {$member->name}."
            : "{$item->key}: removed from {$member->name}'s week.");
    }

    /**
     * Remove one allocation by id — also for people who left the project (who can't be picked).
     */
    public function destroyAllocation(Workspace $workspace, Project $project, int $allocation): RedirectResponse
    {
        $this->authorize('plan', [WorkloadAllocation::class, $project]);

        WorkloadAllocation::where('project_id', $project->id)->findOrFail($allocation)->delete();

        return back()->with('success', 'Planned time removed.');
    }

    private function mondayRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (! WorkloadWeeks::isMonday($value) || $value < '2000-01-01' || $value > '2099-12-31') {
                $fail('Pick the Monday a week starts on.');
            }
        };
    }
}
