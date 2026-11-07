<?php

namespace App\Http\Controllers\AppApi;

use App\Http\Controllers\AppApi\Concerns\AnswersWithETag;
use App\Http\Controllers\Controller;
use App\Http\Resources\AppApi\TodayResource;
use App\Support\ApiKeys\ApiKeyAccess;
use App\Support\Calendar\AgendaEvent;
use App\Support\MyDay\MyDayPlan;
use App\Support\MyWork\AssignedWork;
use App\Support\MyWork\WorkRef;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * App API v1 — "Today" (BRAI-396, for the TV wallboard): My Work due today / overdue, today's My Day
 * and today's calendar events in one call, built from the same shared pieces as GET /me/work,
 * GET /me/my-day and GET /calendar/events. A fixed number of queries (no per-row lookups), plus one
 * call per connected calendar; an ETag lets a refresh without changes answer 304.
 */
#[Group('My Work', weight: 130)]
class TodayController extends Controller
{
    use AnswersWithETag;

    /** At most this many work items (the wallboard can't show more). */
    public const WORK_LIMIT = 100;

    /**
     * Everything for today in one call.
     *
     * Open work assigned to the user that is overdue or due today (soonest first, ≤ 100), today's My Day
     * and today's events of connected calendars and bookings, for the user's local day. Send the last
     * `ETag` as `If-None-Match`: nothing changed → 304 without a body. Refresh every few minutes: the
     * connected calendars are read live.
     */
    public function __invoke(Request $request): JsonResponse|Response
    {
        $user = $request->user();
        $date = MyDayPlan::today($user);

        $rows = AssignedWork::combined($user, ['overdue', 'today'], AssignedWork::OPEN, null, $date)->limit(self::WORK_LIMIT)->get();
        // a personal API key: connected calendars and bookings only with Calendar & bookings read (never even fetched)
        $agenda = ApiKeyAccess::readable($user, 'calendar')
            ? AgendaEvent::forDays($user, $date, $date, ['external', 'booking'], false, true)
            : ['events' => [], 'errors' => []];

        $response = (new TodayResource([
            'date' => $date,
            'timezone' => $user->localTimezone(),
            'work' => WorkRef::load($rows->map(fn (object $row) => [$row->kind, (int) $row->id])),
            'my_day' => MyDayPlan::forDate($user, $date, WorkRef::ISSUE_WITH, WorkRef::TASK_WITH)->pluck('work')->values(),
            'events' => $agenda['events'],
            'errors' => $agenda['errors'],
        ]))->response($request);

        return $this->withETag($request, $response);
    }
}
