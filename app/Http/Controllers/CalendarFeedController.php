<?php

namespace App\Http\Controllers;

use App\Models\CalendarFeed;
use App\Models\Project;
use App\Support\CalendarFeed\CalendarFeedBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The private calendar feed (BRAI-142): the owner manages it from the Calendar page ("Subscribe in
 * other apps", JSON endpoints), calendar apps fetch `/calendar/feed/{token}.ics` without a session.
 */
class CalendarFeedController extends Controller
{
    /** Per feed, whatever the IP: calendar apps refresh every 15 min or so; a leaked URL can't be hammered. */
    public const FETCHES_PER_MINUTE = 30;

    /** The feed (or null), the defaults and the projects the filter can pick. */
    public function show(Request $request): JsonResponse
    {
        return response()->json($this->payload($request));
    }

    /** Turn the feed on; when one is already active, its options are updated instead. */
    public function store(Request $request): JsonResponse
    {
        $options = $this->validatedOptions($request);
        $feed = CalendarFeed::activeFor($request->user());
        $feed ? $feed->update(['options' => $options]) : CalendarFeed::issue($request->user(), $options);

        return response()->json($this->payload($request), $feed ? 200 : 201);
    }

    public function update(Request $request): JsonResponse
    {
        $feed = CalendarFeed::activeFor($request->user());
        abort_unless($feed, 404);
        $feed->update(['options' => $this->validatedOptions($request)]);

        return response()->json($this->payload($request));
    }

    /** A new secret URL; the old one stops working at once. Keeps the options. */
    public function reset(Request $request): JsonResponse
    {
        abort_unless(CalendarFeed::activeFor($request->user()), 404);
        CalendarFeed::issue($request->user());

        return response()->json($this->payload($request));
    }

    /** Turn the feed off: the URL stops working. */
    public function destroy(Request $request): JsonResponse
    {
        CalendarFeed::active()->where('user_id', $request->user()->id)->update(['revoked_at' => now()]);

        return response()->json($this->payload($request));
    }

    /**
     * The ICS body for calendar apps. Stateless (no session or cookies); unknown, revoked or
     * suspended → a plain 404. ETag / Last-Modified let apps skip unchanged bodies (304).
     */
    public function ics(Request $request, string $token): Response
    {
        $headers = ['X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'private, max-age=0, must-revalidate'];
        $feed = CalendarFeed::findActive($token);
        if (! $feed || ! $feed->user || $feed->user->isSuspended()) {
            return response('Not found', 404, $headers + ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        $limiter = 'calendar-feed.ics:'.$feed->id;
        if (RateLimiter::tooManyAttempts($limiter, self::FETCHES_PER_MINUTE)) {
            return response('Too many requests', 429, $headers + [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Retry-After' => (string) RateLimiter::availableIn($limiter),
            ]);
        }
        RateLimiter::hit($limiter, 60);

        $body = CalendarFeedBuilder::build($feed);
        $hash = hash('sha256', $body);
        $now = now();
        $changes = ['last_fetched_at' => $now, 'fetch_count' => DB::raw('fetch_count + 1')];
        if ($feed->content_hash !== $hash || ! $feed->content_changed_at) {
            $changes += ['content_hash' => $hash, 'content_changed_at' => $now];
            $feed->content_changed_at = $now;
        }
        // bookkeeping only: no model events, and updated_at stays "when the owner changed it"
        DB::table('calendar_feeds')->where('id', $feed->id)->update($changes);

        $response = response($body, 200, $headers + [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="brainitive.ics"',
        ]);
        $response->setEtag($hash);
        $response->setLastModified($feed->content_changed_at);
        $response->isNotModified($request); // 304 without a body when If-None-Match / If-Modified-Since match

        return $response;
    }

    private function validatedOptions(Request $request): array
    {
        $validated = $request->validate([
            'options' => 'nullable|array',
            'options.assigned' => 'sometimes|boolean',
            'options.created' => 'sometimes|boolean',
            'options.tasks' => 'sometimes|boolean',
            'options.my_day' => 'sometimes|boolean',
            'options.focus' => 'sometimes|boolean',
            'options.completed' => 'sometimes|boolean',
            'options.descriptions' => 'sometimes|boolean',
            'options.project_ids' => 'sometimes|array|max:500',
            'options.project_ids.*' => 'integer',
        ]);
        $options = CalendarFeed::normalizeOptions($validated['options'] ?? []);
        // only projects the user can open now (also re-checked on every fetch)
        $options['project_ids'] = array_values(array_intersect($options['project_ids'], $request->user()->accessibleProjectIds()));

        return $options;
    }

    private function payload(Request $request): array
    {
        $user = $request->user();
        $feed = CalendarFeed::activeFor($user);

        return [
            'feed' => $feed ? [
                'url' => $feed->url(),
                'links' => $feed->subscribeLinks(),
                'options' => $feed->resolvedOptions(),
                'created_at' => $feed->created_at?->toIso8601String(),
                'last_fetched_at' => $feed->last_fetched_at?->toIso8601String(),
                'fetch_count' => $feed->fetch_count,
            ] : null,
            'defaults' => CalendarFeed::DEFAULT_OPTIONS,
            'projects' => Project::query()
                ->whereIn('id', $user->accessibleProjectIds())
                ->with('workspace:id,name')
                ->orderBy('name')
                ->get(['id', 'name', 'workspace_id'])
                ->map(fn (Project $p) => ['id' => $p->id, 'name' => $p->name, 'workspace' => $p->workspace?->name])
                ->values(),
        ];
    }
}
