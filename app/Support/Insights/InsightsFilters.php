<?php

namespace App\Support\Insights;

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Throwable;

/**
 * The filters every Insights tab shares (BRAI-105): a date range in the viewer's time zone and
 * an optional project of the workspace. Built once per request; `projects` (one query) is the
 * list of live projects of the workspace, reused by the tabs and the project picker.
 */
class InsightsFilters
{
    public const PERIODS = ['this_month', 'last_month', 'last_30', 'last_90', 'custom'];

    public const DEFAULT_PERIOD = 'last_30';

    /** Longest custom range. */
    public const MAX_DAYS = 366;

    /**
     * @param  array<int, array{id: int, name: string, slug: string, key: string|null, color: string|null}>  $projects  every live project of the workspace, by id
     */
    public function __construct(
        public readonly Workspace $workspace,
        public readonly string $period,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly ?int $projectId,
        public readonly string $timezone,
        public readonly CarbonImmutable $now,
        public readonly array $projects,
    ) {}

    public static function fromRequest(Request $request, Workspace $workspace): self
    {
        $user = $request->user();
        $timezone = $user instanceof User ? $user->localTimezone() : (string) config('app.timezone');
        $now = CarbonImmutable::now('UTC');
        $today = $now->setTimezone($timezone)->startOfDay();

        $period = in_array($request->query('period'), self::PERIODS, true) ? (string) $request->query('period') : self::DEFAULT_PERIOD;
        [$from, $to] = self::presetRange($period === 'custom' ? self::DEFAULT_PERIOD : $period, $today);
        if ($period === 'custom') {
            $customFrom = self::date($request->query('from'));
            $customTo = self::date($request->query('to'));
            if ($customFrom && $customTo) {
                [$from, $to] = $customFrom->lte($customTo) ? [$customFrom, $customTo] : [$customTo, $customFrom];
                if ($from->diffInDays($to) >= self::MAX_DAYS) {
                    $to = $from->addDays(self::MAX_DAYS - 1);
                }
            } else {
                $period = self::DEFAULT_PERIOD;
            }
        }

        $projects = Project::query()->where('workspace_id', $workspace->id)->orderBy('name')
            ->get(['id', 'name', 'slug', 'key', 'color'])
            ->mapWithKeys(fn (Project $p) => [$p->id => ['id' => $p->id, 'name' => (string) $p->name, 'slug' => (string) $p->slug, 'key' => $p->key, 'color' => $p->color]])
            ->all();

        $project = $request->query('project');
        $projectId = is_scalar($project) && ctype_digit((string) $project) && isset($projects[(int) $project]) ? (int) $project : null;

        return new self($workspace, $period, $from, $to, $projectId, $timezone, $now, $projects);
    }

    /**
     * Inclusive local dates of a preset, relative to the viewer's today.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function presetRange(string $preset, CarbonImmutable $today): array
    {
        $today = CarbonImmutable::createFromFormat('!Y-m-d', $today->toDateString());

        return match ($preset) {
            'this_month' => [$today->startOfMonth(), $today],
            'last_month' => [$today->startOfMonth()->subMonthNoOverflow(), $today->startOfMonth()->subDay()],
            'last_90' => [$today->subDays(89), $today],
            default => [$today->subDays(29), $today],
        };
    }

    /** @return array<string, array{from: string, to: string}> */
    public function presets(): array
    {
        $today = $this->now->setTimezone($this->timezone)->startOfDay();
        $presets = [];
        foreach (['this_month', 'last_month', 'last_30', 'last_90'] as $preset) {
            [$from, $to] = self::presetRange($preset, $today);
            $presets[$preset] = ['from' => $from->toDateString(), 'to' => $to->toDateString()];
        }

        return $presets;
    }

    /** @return list<int> ids of the projects in scope (the picked one, or every live project) */
    public function projectIds(): array
    {
        return $this->projectId !== null ? [$this->projectId] : array_keys($this->projects);
    }

    /** Start of the range in UTC (`Y-m-d H:i:s`), inclusive. */
    public function startUtc(): string
    {
        return CarbonImmutable::parse($this->from->toDateString(), $this->timezone)->startOfDay()->utc()->format('Y-m-d H:i:s');
    }

    /** End of the range in UTC (`Y-m-d H:i:s`), exclusive: the start of the day after `to`. */
    public function endUtc(): string
    {
        return CarbonImmutable::parse($this->to->toDateString(), $this->timezone)->addDay()->startOfDay()->utc()->format('Y-m-d H:i:s');
    }

    /** The viewer's today (`Y-m-d`). */
    public function today(): string
    {
        return $this->now->setTimezone($this->timezone)->toDateString();
    }

    /** The filters as the page and the CSV links use them. */
    public function toArray(): array
    {
        return [
            'period' => $this->period,
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'project' => $this->projectId,
            'timezone' => $this->timezone,
        ];
    }

    /** @return array<string, string|int> the query string that reproduces these filters */
    public function query(): array
    {
        return array_filter([
            'period' => $this->period,
            'from' => $this->period === 'custom' ? $this->from->toDateString() : null,
            'to' => $this->period === 'custom' ? $this->to->toDateString() : null,
            'project' => $this->projectId,
        ], fn ($value) => $value !== null);
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            return null;
        }

        return $date && $date->format('Y-m-d') === $value ? $date : null;
    }
}
