<?php

namespace App\Support\Boards;

use App\Models\Board;
use App\Models\Item;
use App\Models\Sprint;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Numbers behind the sprint reports: burndown (ideal vs. remaining) and velocity.
 * Estimates use story points; a sprint without any points burns down by issue count.
 */
class SprintReport
{
    /** Completed sprints shown in the velocity chart. */
    public const VELOCITY_SPRINTS = 7;

    /**
     * Top-level issues in the sprint (subtasks are part of their parent's estimate).
     *
     * @return Collection<int, Item>
     */
    public static function scope(Sprint $sprint): Collection
    {
        return $sprint->items()->whereNull('parent_id')->get(['id', 'story_points', 'completed_at', 'created_at']);
    }

    /**
     * Day-by-day remaining work from the first to the last sprint day.
     *
     * @return array{unit: 'points'|'issues', total: int, days: list<array{date: string, ideal: float, remaining: int|null}>}
     */
    public static function burndown(Sprint $sprint): array
    {
        if ($sprint->isCompleted() && is_array($sprint->burndown)) {
            return $sprint->burndown;
        }

        $items = self::scope($sprint);
        $usePoints = $items->contains(fn (Item $item) => (int) $item->story_points > 0);
        $weight = fn (Item $item) => $usePoints ? (int) $item->story_points : 1;
        $total = (int) $items->sum($weight);

        $start = CarbonImmutable::parse($sprint->start_date ?? $sprint->started_at ?? now())->startOfDay();
        $end = CarbonImmutable::parse($sprint->end_date ?? $start->addDays(Sprint::DEFAULT_LENGTH_DAYS))->startOfDay();
        $lastDay = max(1, (int) $start->diffInDays($end));
        $today = CarbonImmutable::now()->startOfDay();

        $days = [];
        for ($day = 0; $day <= $lastDay; $day++) {
            $date = $start->addDays($day);
            $endOfDay = $date->endOfDay();
            $remaining = $date->greaterThan($today) && ! $sprint->isCompleted()
                ? null // future: no actual line yet
                : $total - (int) $items
                    ->filter(fn (Item $item) => $item->completed_at !== null && $item->completed_at->lessThanOrEqualTo($endOfDay))
                    ->sum($weight);

            $days[] = [
                'date' => $date->toDateString(),
                'ideal' => round($total * (1 - $day / $lastDay), 2),
                'remaining' => $remaining,
            ];
        }

        return ['unit' => $usePoints ? 'points' : 'issues', 'total' => $total, 'days' => $days];
    }

    /**
     * Committed vs. completed for the board's last completed sprints, oldest first.
     *
     * @return list<array{id: int, name: string, committed_points: int, completed_points: int, committed_issues: int, completed_issues: int}>
     */
    public static function velocity(Board $board): array
    {
        return $board->sprints()
            ->where('status', Sprint::STATUS_COMPLETED)
            ->reorder()
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->limit(self::VELOCITY_SPRINTS)
            ->get()
            ->reverse()
            ->map(fn (Sprint $sprint) => [
                'id' => $sprint->id,
                'name' => $sprint->name,
                'committed_points' => (int) $sprint->committed_points,
                'completed_points' => (int) $sprint->completed_points,
                'committed_issues' => (int) $sprint->committed_issues,
                'completed_issues' => (int) $sprint->completed_issues,
            ])
            ->values()
            ->all();
    }
}
