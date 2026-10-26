<?php

namespace App\Support\CalendarFeed;

use App\Models\CalendarFeed;
use App\Models\Item;
use App\Models\MyDayEntry;
use App\Models\TaskListItem;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Builds the ICS body of a calendar feed (BRAI-142). Access is re-checked on every build: only
 * projects the owner can open right now (`User::accessibleProjectIds()`), so someone removed from a
 * project stops getting its dates on the next refresh. Only titles, keys and links back to the app
 * (descriptions are opt-in) — never assignees, comments or other people.
 *
 * The body is deterministic for unchanged data (DTSTAMP = the record's updated_at), so its hash is a
 * stable ETag. Dates are the owner's wall clock: timed events convert from `localTimezone()` to UTC.
 */
class CalendarFeedBuilder
{
    public const DAYS_BACK = 60;

    public const DAYS_FORWARD = 365;

    public const MAX_EVENTS = 2000;

    /** Plain-text descriptions are cut to this many characters. */
    public const DESCRIPTION_CHARS = 500;

    private const RRULES = ['daily' => 'FREQ=DAILY', 'weekly' => 'FREQ=WEEKLY', 'monthly' => 'FREQ=MONTHLY', 'yearly' => 'FREQ=YEARLY'];

    private User $user;

    private string $zone;

    private array $options;

    private string $host;

    /** @var array<int, int> */
    private array $projectIds;

    private CarbonImmutable $from;

    private CarbonImmutable $to;

    /** @var array<int, array<string, mixed>> */
    private array $events = [];

    public function __construct(private CalendarFeed $feed)
    {
        $this->user = $feed->user;
        $this->zone = $this->user->localTimezone();
        $this->options = $feed->resolvedOptions();
        $this->host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'brainitive.app';

        $accessible = $this->user->accessibleProjectIds();
        $this->projectIds = $this->options['project_ids']
            ? array_values(array_intersect($accessible, $this->options['project_ids']))
            : $accessible;

        $today = CarbonImmutable::now($this->zone)->startOfDay();
        $this->from = $today->subDays(self::DAYS_BACK);
        $this->to = $today->addDays(self::DAYS_FORWARD);
    }

    public static function build(CalendarFeed $feed): string
    {
        return (new self($feed))->render();
    }

    public function render(): string
    {
        if ($this->projectIds) {
            if ($this->options['assigned'] || $this->options['created']) {
                $this->addIssues();
            }
            if ($this->options['tasks']) {
                $this->addTasks();
            }
            if ($this->options['my_day']) {
                $this->addMyDay();
            }
            if ($this->options['focus']) {
                $this->addFocusSessions();
            }
        }

        return Ics::calendar('Brainitive — '.$this->user->name, $this->events);
    }

    private function room(): int
    {
        return max(0, self::MAX_EVENTS - count($this->events));
    }

    /** Rows whose start..due span touches the feed's range. */
    private function inRange(Builder $query): Builder
    {
        $from = $this->from->toDateString();
        $to = $this->to->toDateString();

        return $query->where(fn (Builder $q) => $q
            ->whereBetween('start_date', [$from, $to])
            ->orWhereBetween('due_date', [$from, $to])
            ->orWhere(fn (Builder $span) => $span->where('start_date', '<', $from)->where('due_date', '>', $to)));
    }

    private function addIssues(): void
    {
        if (! $this->room()) {
            return;
        }
        $userId = $this->user->id;
        $query = Item::query()
            ->whereIn('project_id', $this->projectIds)
            ->whereHas('board') // a deleted board soft-deletes only the board
            ->where(fn (Builder $q) => $q
                ->when($this->options['assigned'], fn (Builder $w) => $w->orWhere('assigned_to', $userId))
                ->when($this->options['created'], fn (Builder $w) => $w->orWhere('created_by', $userId)));
        $this->inRange($query);
        if (! $this->options['completed']) {
            $query->whereNull('completed_at')->whereDoesntHave('column', fn (Builder $c) => $c->where('is_done_column', true));
        }

        $items = $query
            ->with(['board:id,project_id,slug', 'board.project:id,workspace_id,slug,key', 'board.project.workspace:id,slug', 'column:id,is_done_column'])
            ->orderBy('id')
            ->limit($this->room())
            ->get();

        foreach ($items as $item) {
            $span = $this->span($item->start_date, $item->start_time, $item->due_date, $item->due_time);
            if (! $span) {
                continue;
            }
            $done = $item->completed_at !== null || ($item->column?->is_done_column ?? false);
            $this->events[] = $span + [
                'uid' => "item-{$item->id}@{$this->host}",
                'stamp' => $item->updated_at ?? $item->created_at ?? now(),
                'summary' => $this->summary($item->key, $item->title, $done),
                'url' => $url = $this->itemUrl($item),
                'description' => $this->description($item->description, $url),
            ];
        }
    }

    private function addTasks(): void
    {
        if (! $this->room()) {
            return;
        }
        $userId = $this->user->id;
        $query = TaskListItem::query()
            ->whereHas('taskList', fn (Builder $q) => $q->whereIn('project_id', $this->projectIds))
            // mine: assigned to me, or a personal task nobody is assigned to that I created
            ->where(fn (Builder $q) => $q
                ->where('assignee_id', $userId)
                ->orWhere(fn (Builder $o) => $o->whereNull('assignee_id')->where('created_by', $userId)))
            ->where(fn (Builder $q) => $this->inRange($q)
                ->orWhere(fn (Builder $r) => $r->whereIn('recurrence', array_keys(self::RRULES))->where('due_date', '<=', $this->to->toDateString())));
        if (! $this->options['completed']) {
            $query->where('status', '!=', 'done');
        }

        $tasks = $query
            ->with(['taskList:id,project_id,slug', 'taskList.project:id,workspace_id,slug,key', 'taskList.project.workspace:id,slug'])
            ->orderBy('id')
            ->limit($this->room())
            ->get();

        foreach ($tasks as $task) {
            $rrule = self::RRULES[$task->recurrence] ?? null;
            // a repeating task repeats its due date (start dates only belong to the first one)
            $span = $rrule
                ? $this->span(null, null, $task->due_date, $task->due_time)
                : $this->span($task->start_date, $task->start_time, $task->due_date, $task->due_time);
            if (! $span) {
                continue;
            }
            $project = $task->taskList->project;
            $this->events[] = $span + [
                'uid' => "task-{$task->id}@{$this->host}",
                'stamp' => $task->updated_at ?? $task->created_at ?? now(),
                'summary' => $this->summary($project?->key, $task->title, $task->status === 'done'),
                'url' => $url = $this->taskUrl($task),
                'description' => $this->description($task->description, $url),
                'rrule' => $rrule,
            ];
        }
    }

    /** My Day: one all-day event per planned task, on the day it was planned for. */
    private function addMyDay(): void
    {
        if (! $this->room()) {
            return;
        }
        $entries = MyDayEntry::where('user_id', $this->user->id)
            ->whereBetween('plan_date', [$this->from->toDateString(), $this->to->toDateString()])
            ->orderBy('plan_date')->orderBy('position')->orderBy('id')
            ->limit($this->room())
            ->get();
        if ($entries->isEmpty()) {
            return;
        }
        $items = Item::whereIn('id', $entries->where('work_type', 'board')->pluck('work_id'))
            ->whereIn('project_id', $this->projectIds)
            ->whereHas('board')
            ->with(['board:id,project_id,slug', 'board.project:id,workspace_id,slug,key', 'board.project.workspace:id,slug', 'column:id,is_done_column'])
            ->get()->keyBy('id');
        $tasks = TaskListItem::whereIn('id', $entries->where('work_type', 'list')->pluck('work_id'))
            ->whereHas('taskList', fn (Builder $q) => $q->whereIn('project_id', $this->projectIds))
            ->with(['taskList:id,project_id,slug', 'taskList.project:id,workspace_id,slug,key', 'taskList.project.workspace:id,slug'])
            ->get()->keyBy('id');

        foreach ($entries as $entry) {
            $work = $entry->work_type === 'board' ? $items->get($entry->work_id) : $tasks->get($entry->work_id);
            if (! $work) {
                continue; // deleted, or no longer accessible
            }
            if ($work instanceof Item) {
                $done = $work->completed_at !== null || ($work->column?->is_done_column ?? false);
                [$key, $url] = [$work->key, $this->itemUrl($work)];
            } else {
                $done = $work->status === 'done';
                [$key, $url] = [$work->taskList->project?->key, $this->taskUrl($work)];
            }
            if ($done && ! $this->options['completed']) {
                continue;
            }
            $day = Carbon::parse($entry->plan_date->toDateString());
            $this->events[] = [
                'uid' => "myday-{$entry->id}@{$this->host}",
                'stamp' => $entry->updated_at ?? $entry->created_at ?? now(),
                'all_day' => true,
                'start' => $day,
                'end' => $day,
                'summary' => 'My Day: '.$this->summary($key, $work->title, $done),
                'url' => $url,
                'description' => $url,
            ];
        }
    }

    /** Focus sessions logged with the timer: a timed block that ends when the session was logged. */
    private function addFocusSessions(): void
    {
        if (! $this->room()) {
            return;
        }
        $entries = TimeEntry::query()
            ->where('user_id', $this->user->id)
            ->where('source', TimeEntry::SOURCE_TIMER)
            ->whereIn('project_id', $this->projectIds)
            ->whereBetween('created_at', [$this->from->utc(), $this->to->addDay()->utc()])
            ->orderBy('id')
            ->limit($this->room())
            ->get(['id', 'item_id', 'item_key', 'item_title', 'minutes', 'created_at', 'updated_at']);
        if ($entries->isEmpty()) {
            return;
        }
        $items = Item::whereIn('id', $entries->pluck('item_id')->filter())
            ->whereIn('project_id', $this->projectIds)
            ->whereHas('board')
            ->with(['board:id,project_id,slug', 'board.project:id,workspace_id,slug,key', 'board.project.workspace:id,slug'])
            ->get()->keyBy('id');

        foreach ($entries as $entry) {
            $item = $entry->item_id ? $items->get($entry->item_id) : null;
            $end = Carbon::parse($entry->created_at)->utc();
            $url = $item ? $this->itemUrl($item) : null;
            $this->events[] = [
                'uid' => "focus-{$entry->id}@{$this->host}",
                'stamp' => $entry->updated_at ?? $entry->created_at,
                'all_day' => false,
                'start' => $end->copy()->subMinutes(max(1, (int) $entry->minutes)),
                'end' => $end,
                'summary' => 'Focus: '.$this->summary($item?->key ?? $entry->item_key, $item?->title ?? $entry->item_title ?? 'Focus session', false),
                'url' => $url,
                'description' => $url,
                'transparent' => false,
            ];
        }
    }

    /**
     * The event's time span from wall-clock dates / times, or null without any date.
     * - start + due with times (due after start) → timed block
     * - due with a time → 30 minutes ending at the due time; start with a time and no due → 1 hour
     * - otherwise all-day from the start date (or due date) to the due date (or start date)
     *
     * @return array{all_day: bool, start: Carbon, end: Carbon}|null
     */
    private function span($startDate, ?string $startTime, $dueDate, ?string $dueTime): ?array
    {
        $startDay = $startDate ? Carbon::parse($startDate)->toDateString() : null;
        $dueDay = $dueDate ? Carbon::parse($dueDate)->toDateString() : null;
        if (! $startDay && ! $dueDay) {
            return null;
        }
        $start = $startDay && $startTime ? $this->moment($startDay, $startTime) : null;
        $due = $dueDay && $dueTime ? $this->moment($dueDay, $dueTime) : null;

        if ($start && $due && $due->gt($start)) {
            return ['all_day' => false, 'start' => $start, 'end' => $due];
        }
        if ($due) {
            return ['all_day' => false, 'start' => $due->copy()->subMinutes(30), 'end' => $due];
        }
        if ($start && ! $dueDay) {
            return ['all_day' => false, 'start' => $start, 'end' => $start->copy()->addHour()];
        }

        $first = Carbon::parse($startDay ?? $dueDay);
        $last = Carbon::parse($dueDay ?? $startDay);
        if ($first->gt($last)) {
            $first = $last->copy();
        }

        return ['all_day' => true, 'start' => $first, 'end' => $last];
    }

    /** A wall-clock date + time in the owner's zone, as UTC. */
    private function moment(string $day, string $time): Carbon
    {
        return Carbon::parse($day.' '.substr($time, 0, 5), $this->zone)->utc();
    }

    private function summary(?string $key, ?string $title, bool $done): string
    {
        $title = trim((string) $title) !== '' ? trim((string) $title) : 'Untitled';

        return ($done ? '✓ ' : '').($key ? "[{$key}] " : '').$title;
    }

    /** The link back to the app, after the (opt-in) plain-text description. Google ignores URL. */
    private function description(mixed $raw, string $url): string
    {
        $text = $this->options['descriptions'] ? $this->plainText($raw) : '';

        return $text !== '' ? $text."\n\n".$url : $url;
    }

    /** HTML (issues) or Editor.js JSON (tasks) to shortened plain text. */
    private function plainText(mixed $raw): string
    {
        if (is_array($raw)) {
            $parts = [];
            array_walk_recursive($raw, function ($value, $key) use (&$parts) {
                if (in_array($key, ['text', 'content', 'caption'], true) && is_string($value)) {
                    $parts[] = $value;
                }
            });
            $raw = implode("\n", $parts);
        }
        if (! is_string($raw) || trim($raw) === '') {
            return '';
        }
        $raw = (string) preg_replace('#<(br|/p|/div|/li|/h[1-6])\b[^>]*>#i', "\n", $raw);
        $text = html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace("/[ \t]+/", ' ', $text);
        $text = trim((string) preg_replace("/\n\s*\n+/", "\n", $text));

        return Str::limit($text, self::DESCRIPTION_CHARS);
    }

    private function itemUrl(Item $item): string
    {
        $project = $item->board->project;

        return route('boards.items.show', [$project->workspace->slug, $project->slug, $item->board->slug, $item->id]);
    }

    private function taskUrl(TaskListItem $task): string
    {
        $project = $task->taskList->project;

        return route('task-lists.show', [$project->workspace->slug, $project->slug, $task->taskList->slug]).'#task-'.$task->id;
    }
}
