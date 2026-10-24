<?php

namespace App\Support\Billing;

use App\Models\BillingActivity;
use App\Models\BillingActivityDefault;
use App\Models\Item;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The activity side of logging time (v1.38.0, BRAI-99): what people may pick, whether they must,
 * and what is preselected. Member-facing — never expose a rate from here
 * (docs/ai/tasks/release-v1.38.0-billing.md: only workspace admins see money).
 */
class TimeLogActivities
{
    /** @return Collection<int, BillingActivity> */
    public static function active(Workspace|int $workspace): Collection
    {
        return BillingActivity::query()
            ->where('workspace_id', self::id($workspace))
            ->active()
            ->orderBy('position')->orderBy('name')
            ->get(['id', 'workspace_id', 'name', 'pricing_type', 'billable', 'color', 'position']);
    }

    /** Picking an activity is required once the workspace has at least one active activity. */
    public static function required(Workspace|int $workspace): bool
    {
        return BillingActivity::where('workspace_id', self::id($workspace))->active()->exists();
    }

    /**
     * What a member may see about the activities: no rate, no amount.
     *
     * @return list<array{id: int, name: string, color: string|null, pricing_type: string, pricing_label: string, billable: bool}>
     */
    public static function options(Workspace|int $workspace): array
    {
        return self::active($workspace)->map(fn (BillingActivity $a) => self::option($a))->values()->all();
    }

    /** @return array{id: int, name: string, color: string|null, pricing_type: string, pricing_label: string, billable: bool} */
    public static function option(BillingActivity $activity): array
    {
        return [
            'id' => $activity->id,
            'name' => $activity->name,
            'color' => $activity->color,
            'pricing_type' => $activity->pricing_type,
            'pricing_label' => $activity->isFixed() ? 'Fixed price' : 'Per hour',
            'billable' => (bool) $activity->billable,
        ];
    }

    /**
     * Preselected activity: the ticket type's default, then the one the user used last in this
     * workspace — each only while it is still active.
     */
    public static function defaultFor(User $user, Workspace|int $workspace, ?Item $item = null): ?int
    {
        $workspaceId = self::id($workspace);
        $activeIds = BillingActivity::where('workspace_id', $workspaceId)->active()->select('id');

        if ($item?->item_type_id) {
            $typeDefault = BillingActivityDefault::where('workspace_id', $workspaceId)
                ->where('item_type_id', $item->item_type_id)
                ->whereIn('billing_activity_id', $activeIds)
                ->value('billing_activity_id');
            if ($typeDefault) {
                return (int) $typeDefault;
            }
        }

        $lastUsed = TimeEntry::where('workspace_id', $workspaceId)
            ->where('user_id', $user->id)
            ->whereIn('billing_activity_id', BillingActivity::where('workspace_id', $workspaceId)->active()->select('id'))
            ->latest('id')
            ->value('billing_activity_id');

        return $lastUsed ? (int) $lastUsed : null;
    }

    /**
     * The activity for a new or changed entry, or a validation error on `activity_id`: it must be
     * an active activity of this workspace, and it is required while the workspace has any. An
     * entry may keep the (since archived) activity it already has.
     */
    public static function resolve(Workspace|int $workspace, mixed $activityId, ?int $current = null): ?BillingActivity
    {
        $workspaceId = self::id($workspace);

        if ($activityId === null || $activityId === '') {
            if (self::required($workspaceId)) {
                throw ValidationException::withMessages(['activity_id' => 'Pick an activity.']);
            }

            return null;
        }

        $activity = filter_var($activityId, FILTER_VALIDATE_INT) !== false
            ? BillingActivity::whereKey((int) $activityId)->where('workspace_id', $workspaceId)->first()
            : null;

        if (! $activity || ($activity->archived_at !== null && $activity->id !== $current)) {
            throw ValidationException::withMessages(['activity_id' => 'This activity is not available. Pick another one.']);
        }

        return $activity;
    }

    private static function id(Workspace|int $workspace): int
    {
        return $workspace instanceof Workspace ? $workspace->id : $workspace;
    }
}
