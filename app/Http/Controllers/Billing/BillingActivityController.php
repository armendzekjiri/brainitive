<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\BillingActivity;
use App\Models\BillingActivityDefault;
use App\Models\BillingSetting;
use App\Models\ItemType;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Billing → Activities (v1.38.0, BRAI-99): what the team bills — priced per hour or as a fixed
 * price — and the ticket type → default activity mapping that preselects the activity when someone
 * logs time. Workspace admins only (BillingPolicy). Contract: docs/ai/tasks/release-v1.38.0-billing.md.
 */
class BillingActivityController extends Controller
{
    /** Highest price an admin can enter, in the currency's major unit. */
    private const MAX_PRICE = 1000000;

    public function index(Workspace $workspace): Response
    {
        Gate::authorize('manageBilling', $workspace);

        $activities = $workspace->billingActivities()
            ->withCount('timeEntries')
            ->orderByRaw('archived_at is not null')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (BillingActivity $activity) => [
                'id' => $activity->id,
                'name' => $activity->name,
                'pricing_type' => $activity->pricing_type,
                'rate_cents' => $activity->rate_cents,
                'billable' => $activity->billable,
                'color' => $activity->color,
                'archived_at' => $activity->archived_at?->toIso8601String(),
                'used' => $activity->time_entries_count > 0,
            ]);

        $defaults = BillingActivityDefault::where('workspace_id', $workspace->id)->pluck('billing_activity_id', 'item_type_id');

        $itemTypes = $this->itemTypes($workspace)->map(fn (ItemType $type) => [
            'id' => $type->id,
            'name' => $type->name,
            'color' => $type->color,
            'project' => $type->project?->name,
            'billing_activity_id' => $defaults[$type->id] ?? null,
        ]);

        return Inertia::render('Billing/Activities/Index', [
            'workspace' => $workspace->only(['id', 'name', 'slug']),
            'currency' => BillingSetting::forWorkspace($workspace)->currency,
            'activities' => $activities,
            'itemTypes' => $itemTypes,
        ]);
    }

    public function store(Request $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('manageBilling', $workspace);

        $validated = $request->validate($this->rules());
        $this->ensureUniqueName($workspace, $validated['name']);

        $this->guardUniqueKey(fn () => $workspace->billingActivities()->create($this->attributes($validated) + [
            'position' => ($workspace->billingActivities()->max('position') ?? 0) + 1,
        ]));

        return back()->with('success', 'Activity created.');
    }

    public function update(Request $request, Workspace $workspace, BillingActivity $billingActivity): RedirectResponse
    {
        Gate::authorize('manageBilling', $workspace);
        $this->ensureInWorkspace($workspace, $billingActivity);

        $validated = $request->validate($this->rules());
        $this->ensureUniqueName($workspace, $validated['name'], $billingActivity->id);

        $this->guardUniqueKey(fn () => $billingActivity->update($this->attributes($validated)));

        return back()->with('success', 'Activity saved.');
    }

    /**
     * Archived activities can't be picked for new time but stay on the entries that use them.
     * Defaults pointing at it are removed, so a ticket type's default is always pickable.
     */
    public function archive(Workspace $workspace, BillingActivity $billingActivity): RedirectResponse
    {
        Gate::authorize('manageBilling', $workspace);
        $this->ensureInWorkspace($workspace, $billingActivity);

        DB::transaction(function () use ($billingActivity) {
            $billingActivity->update(['archived_at' => $billingActivity->archived_at ?? now()]);
            BillingActivityDefault::where('billing_activity_id', $billingActivity->id)->delete();
        });

        return back()->with('success', "“{$billingActivity->name}” archived.");
    }

    public function restore(Workspace $workspace, BillingActivity $billingActivity): RedirectResponse
    {
        Gate::authorize('manageBilling', $workspace);
        $this->ensureInWorkspace($workspace, $billingActivity);

        if ($billingActivity->archived_at) {
            $billingActivity->update([
                'archived_at' => null,
                'position' => ($workspace->billingActivities()->active()->max('position') ?? 0) + 1,
            ]);
        }

        return back()->with('success', "“{$billingActivity->name}” restored.");
    }

    /**
     * Save the order of the active activities (ids in display order).
     */
    public function reorder(Request $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('manageBilling', $workspace);

        $validated = $request->validate([
            'ids' => 'required|array|max:500',
            'ids.*' => ['integer', 'distinct', Rule::exists('billing_activities', 'id')->where('workspace_id', $workspace->id)],
        ]);

        DB::transaction(function () use ($validated) {
            foreach (array_values($validated['ids']) as $position => $id) {
                BillingActivity::whereKey($id)->update(['position' => $position + 1]);
            }
        });

        return back();
    }

    /**
     * Delete an activity no time was logged with. Used ones are archived instead (the database
     * restricts the delete), so no logged time loses its activity.
     */
    public function destroy(Workspace $workspace, BillingActivity $billingActivity): RedirectResponse
    {
        Gate::authorize('manageBilling', $workspace);
        $this->ensureInWorkspace($workspace, $billingActivity);

        $inUse = ValidationException::withMessages([
            'activity' => "Time was logged with “{$billingActivity->name}”, so it can't be deleted. Archive it instead: it stays on those entries but can't be picked for new time.",
        ]);

        if ($billingActivity->timeEntries()->exists()) {
            throw $inUse;
        }

        try {
            // savepoint: a restrict violation (time logged meanwhile) must not abort an outer transaction
            DB::transaction(fn () => $billingActivity->delete());
        } catch (QueryException $e) {
            // 23503 = foreign key violation: time was logged with it meanwhile
            throw $e->getCode() === '23503' ? $inUse : $e;
        }

        return back()->with('success', "“{$billingActivity->name}” deleted.");
    }

    /**
     * Ticket type → default activity, one row at a time. `billing_activity_id` null removes it.
     */
    public function updateDefault(Request $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('manageBilling', $workspace);

        $validated = $request->validate([
            'item_type_id' => ['required', 'integer', Rule::in($this->itemTypes($workspace)->modelKeys())],
            'billing_activity_id' => [
                'nullable', 'integer',
                Rule::exists('billing_activities', 'id')->where('workspace_id', $workspace->id)->whereNull('archived_at'),
            ],
        ], [
            'item_type_id.in' => 'This issue type isn\'t used in this workspace.',
            'billing_activity_id.exists' => 'Pick an active activity of this workspace.',
        ]);

        $match = ['workspace_id' => $workspace->id, 'item_type_id' => $validated['item_type_id']];

        if ($validated['billing_activity_id'] ?? null) {
            BillingActivityDefault::updateOrCreate($match, ['billing_activity_id' => $validated['billing_activity_id']]);
        } else {
            BillingActivityDefault::where($match)->delete();
        }

        return back()->with('success', 'Default activity saved.');
    }

    /**
     * Item types usable in the workspace: the active global types plus the types of its projects.
     *
     * @return Collection<int, ItemType>
     */
    private function itemTypes(Workspace $workspace): Collection
    {
        $projectIds = $workspace->projects()->pluck('id');

        return ItemType::query()
            ->with('project:id,name')
            ->where(fn ($q) => $q->where(fn ($g) => $g->whereNull('project_id')->where('is_active', true))
                ->orWhereIn('project_id', $projectIds))
            ->orderByRaw('project_id is not null')
            ->orderBy('project_id')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'name' => 'required|string|max:80',
            'pricing_type' => ['required', Rule::in(BillingActivity::PRICING_TYPES)],
            'price' => 'required|numeric|decimal:0,2|min:0|max:'.self::MAX_PRICE,
            'billable' => 'required|boolean',
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function attributes(array $validated): array
    {
        return [
            'name' => trim($validated['name']),
            'pricing_type' => $validated['pricing_type'],
            // major unit (≤ 2 decimals, validated) → integer cents
            'rate_cents' => (int) round(((float) $validated['price']) * 100),
            'billable' => (bool) $validated['billable'],
            'color' => $validated['color'] ?? null,
        ];
    }

    /**
     * Two admins saving the same name at once: the second hits the (workspace_id, name) key.
     * Answer with the validation message, not a 500 (savepoint, so no outer transaction aborts).
     */
    private function guardUniqueKey(callable $write): void
    {
        try {
            DB::transaction($write);
        } catch (QueryException $e) {
            if ($e->getCode() !== '23505') {
                throw $e;
            }
            throw ValidationException::withMessages(['name' => 'Another activity already uses this name.']);
        }
    }

    private function ensureInWorkspace(Workspace $workspace, BillingActivity $activity): void
    {
        abort_unless($activity->workspace_id === $workspace->id, 404);
    }

    /**
     * One name per workspace, case-insensitive — archived activities included, because the
     * database's unique key covers them too.
     */
    private function ensureUniqueName(Workspace $workspace, string $name, ?int $exceptId = null): void
    {
        $taken = $workspace->billingActivities()
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->whereRaw('lower(name) = ?', [mb_strtolower(trim($name))])
            ->first();

        if ($taken) {
            throw ValidationException::withMessages(['name' => $taken->archived_at
                ? 'An archived activity already uses this name. Restore it instead, or pick another name.'
                : 'Another activity already uses this name.']);
        }
    }
}
