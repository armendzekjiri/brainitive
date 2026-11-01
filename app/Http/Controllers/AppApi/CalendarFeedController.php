<?php

namespace App\Http\Controllers\AppApi;

use App\Http\Controllers\Controller;
use App\Http\Requests\AppApi\UpdateCalendarFeedRequest;
use App\Http\Resources\AppApi\CalendarFeedResource;
use App\Models\CalendarFeed;
use App\Support\CalendarFeed\CalendarFeeds;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * App API v1 — the private calendar feed link (BRAI-418, the web's "Subscribe in other apps", BRAI-142): an ICS
 * link of the user's issues, tasks, My Day and focus time for Apple / Google / Outlook Calendar. Same model and
 * option rules as the web (CalendarFeeds); calendar apps fetch the link itself without any token.
 */
#[Group('Calendar', weight: 140)]
class CalendarFeedController extends Controller
{
    /** The user's private feed: the secret link, subscribe links and options (`enabled: false` when off). */
    public function show(Request $request): CalendarFeedResource
    {
        return CalendarFeedResource::make(CalendarFeed::activeFor($request->user()));
    }

    /**
     * Turn the feed on, or replace its options.
     *
     * 201 when it was off (a new secret link), 200 when it only changed the options. A missing option takes its
     * default; `project_ids` keeps only projects the user can open.
     */
    public function update(UpdateCalendarFeedRequest $request): JsonResponse
    {
        $user = $request->user();
        $created = CalendarFeeds::enable($user, CalendarFeeds::options($user, $request->validated('options') ?? []));
        $response = CalendarFeedResource::make(CalendarFeed::activeFor($user))->response();

        return $created ? $response->setStatusCode(201) : $response;
    }

    /**
     * A new secret link; the old one stops working at once. Keeps the options.
     *
     * @throws NotFoundHttpException the feed is off (404)
     */
    public function reset(Request $request): CalendarFeedResource
    {
        $user = $request->user();
        abort_unless(CalendarFeed::activeFor($user), 404);
        CalendarFeed::issue($user);

        return CalendarFeedResource::make(CalendarFeed::activeFor($user));
    }

    /** Turn the feed off: the link stops working (204; off already = 204 too). */
    public function destroy(Request $request): Response
    {
        CalendarFeeds::disable($request->user());

        return response()->noContent();
    }
}
