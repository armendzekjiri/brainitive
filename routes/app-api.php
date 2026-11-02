<?php

use App\Http\Controllers\AppApi\AuthController;
use App\Http\Controllers\AppApi\BoardController;
use App\Http\Controllers\AppApi\BookingController;
use App\Http\Controllers\AppApi\BookmarkController;
use App\Http\Controllers\AppApi\CalendarController;
use App\Http\Controllers\AppApi\CalendarEventController;
use App\Http\Controllers\AppApi\CalendarFeedController;
use App\Http\Controllers\AppApi\CaptureController;
use App\Http\Controllers\AppApi\ConnectedAppController;
use App\Http\Controllers\AppApi\DeviceAuthController;
use App\Http\Controllers\AppApi\DeviceController;
use App\Http\Controllers\AppApi\DocReviewController;
use App\Http\Controllers\AppApi\HelpdeskController;
use App\Http\Controllers\AppApi\InsightsController;
use App\Http\Controllers\AppApi\InstanceController;
use App\Http\Controllers\AppApi\InvitationController;
use App\Http\Controllers\AppApi\IssueActivityController;
use App\Http\Controllers\AppApi\IssueController;
use App\Http\Controllers\AppApi\IssueLinkController;
use App\Http\Controllers\AppApi\LeadController;
use App\Http\Controllers\AppApi\LinkController;
use App\Http\Controllers\AppApi\MeAvatarController;
use App\Http\Controllers\AppApi\MeController;
use App\Http\Controllers\AppApi\MeetingController;
use App\Http\Controllers\AppApi\MemberController;
use App\Http\Controllers\AppApi\MyWorkController;
use App\Http\Controllers\AppApi\NavigationController;
use App\Http\Controllers\AppApi\NotificationController;
use App\Http\Controllers\AppApi\PageCollabController;
use App\Http\Controllers\AppApi\PageController;
use App\Http\Controllers\AppApi\PlanController;
use App\Http\Controllers\AppApi\PortalController;
use App\Http\Controllers\AppApi\ProjectController;
use App\Http\Controllers\AppApi\ReleaseController;
use App\Http\Controllers\AppApi\SearchController;
use App\Http\Controllers\AppApi\SnippetController;
use App\Http\Controllers\AppApi\SprintController;
use App\Http\Controllers\AppApi\SyncController;
use App\Http\Controllers\AppApi\TaskListController;
use App\Http\Controllers\AppApi\TimerController;
use App\Http\Controllers\AppApi\TodayController;
use App\Http\Controllers\AppApi\UploadController;
use App\Http\Controllers\AppApi\WhiteboardController;
use App\Http\Controllers\AppApi\WorkLogController;
use App\Http\Controllers\AppApi\WorkspaceController;
use App\Http\Middleware\EnsureUserIsNotSuspended;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| App API v1 — desktop and mobile apps (BRAI-380)
|--------------------------------------------------------------------------
| Loaded in bootstrap/app.php: prefix /api/v1, names `app.v1.*`, group `app-api` (JSON, throttle,
| no session, no CSRF, no CORS). Separate from the MCP / CLI / extension APIs: only `app` tokens
| (App\Support\AppApi\AppTokens) pass `auth:app`.
|
| Every endpoint: Form Request in, Resource out, policies, tests incl. "Passport token → 401",
| OpenAPI regenerated, a row in docs/ai/API.md, and the `## API` section on its mobile card.
| Recipe: docs/ai/skills/add-app-api-endpoint.md
*/

// ── Instance (public) ───────────────────────────────────────────────────────────────────────
Route::get('instance', InstanceController::class)->name('instance');

// ── Auth (public): code exchange, refresh ────────────────────────────────────────────────────

Route::middleware('throttle:app-auth')->group(function () {
    Route::post('auth/exchange', [AuthController::class, 'exchange'])->name('auth.exchange');
    Route::post('auth/refresh', [AuthController::class, 'refresh'])->name('auth.refresh');
});

// ── Auth (public): device-code sign-in for the TV app (RFC 8628); confirmed at /app/device ──────
Route::post('auth/device', [DeviceAuthController::class, 'start'])->middleware('throttle:app-device-start')->name('auth.device');
Route::post('auth/device/token', [DeviceAuthController::class, 'token'])->middleware('throttle:app-device-poll')->name('auth.device.token');

Route::middleware(['auth:app', EnsureUserIsNotSuspended::class])->group(function () {

    // ── Auth: sign out, webview session ──────────────────────────────────────────────────────

    Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::post('auth/web-session', [AuthController::class, 'webSession'])->name('auth.web-session');

    // ── Account ───────────────────────────────────────────────────────────────────────────────
    Route::get('me', [MeController::class, 'show'])->name('me.show');
    Route::patch('me', [MeController::class, 'update'])->name('me.update');
    Route::post('me/avatar', [MeAvatarController::class, 'store'])->name('me.avatar.store');
    Route::delete('me/avatar', [MeAvatarController::class, 'destroy'])->name('me.avatar.destroy');

    // ── Devices ───────────────────────────────────────────────────────────────────────────────
    Route::get('devices', [DeviceController::class, 'index'])->name('devices.index');
    Route::patch('devices/current', [DeviceController::class, 'updateCurrent'])->name('devices.current.update');
    Route::delete('devices/{device}', [DeviceController::class, 'destroy'])->whereNumber('device')->name('devices.destroy');

    // ── Invitations & onboarding ──────────────────────────────────────────────────────────────
    Route::get('invitations/{token}', [InvitationController::class, 'show'])
        ->where('token', '[A-Za-z0-9]{1,128}')->name('invitations.show');
    // joining / creating need a verified address, as on the web (403 otherwise)
    Route::post('invitations/{token}/accept', [InvitationController::class, 'accept'])
        ->where('token', '[A-Za-z0-9]{1,128}')->middleware('verified')->name('invitations.accept');
    Route::post('workspaces', [WorkspaceController::class, 'store'])->middleware('verified')->name('workspaces.store');

    // Workspace content needs a verified address, as the whole web app does (403 otherwise; on
    // instances without verification every address counts as verified). /me stays open so the
    // apps can show "verify your email".
    Route::middleware('verified')->group(function () {

        // ── Workspaces ────────────────────────────────────────────────────────────────────────────
        Route::get('workspaces', [WorkspaceController::class, 'index'])->name('workspaces.index');
        Route::get('workspaces/{workspace:slug}', [WorkspaceController::class, 'show'])->name('workspaces.show');

        // ── Projects (addressed by id: slugs are unique per workspace only) ─────────────────────────
        Route::get('workspaces/{workspace:slug}/projects', [ProjectController::class, 'index'])->name('projects.index');
        Route::post('workspaces/{workspace:slug}/projects', [ProjectController::class, 'store'])->name('projects.store');
        Route::get('workspaces/{workspace:slug}/project-templates', [ProjectController::class, 'templates'])->name('projects.templates');
        Route::get('projects/{project}', [ProjectController::class, 'show'])->whereNumber('project')->name('projects.show');

        // ── Members (role changes and removals: web only) ──────────────────────────────────────
        Route::get('workspaces/{workspace:slug}/members', [MemberController::class, 'workspace'])->name('members.workspace');
        Route::get('projects/{project}/members', [MemberController::class, 'project'])->whereNumber('project')->name('members.project');
        Route::post('workspaces/{workspace:slug}/invitations', [MemberController::class, 'invite'])->name('members.invite');

        // ── Navigation (the web sidebar; recent destinations live on the device) ─────────────────
        Route::get('me/navigation', [NavigationController::class, 'show'])->name('navigation.show');
        Route::put('me/pins', [NavigationController::class, 'updatePins'])->name('navigation.pins');

        // ── Search (SearchService: the web's search, MCP and the extension share it) ─────────────
        Route::get('search', SearchController::class)->name('search');

        // ── Notifications (NotificationInbox, shared with the web bell) ─────────────────────────
        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('notifications.unread-count');
        Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
        Route::post('notifications/{id}/read', [NotificationController::class, 'read'])->name('notifications.read');
        Route::post('notifications/{id}/archive', [NotificationController::class, 'archive'])->name('notifications.archive');
        Route::get('me/notification-preferences', [NotificationController::class, 'preferences'])->name('notifications.preferences');
        Route::put('me/notification-preferences', [NotificationController::class, 'updatePreferences'])->name('notifications.preferences.update');

        // ── My Work + My Day (AssignedWork, MyDayPlan, WorkCompletion: shared with the web) ─────────
        // {id} = issue:<id> | task:<id>, checked in the controller (no route constraint on writes)
        Route::get('me/work', [MyWorkController::class, 'index'])->name('work.index');
        Route::patch('me/work/{id}', [MyWorkController::class, 'update'])->name('work.update');
        Route::get('me/my-day', [MyWorkController::class, 'myDay'])->name('my-day.show');
        Route::post('me/my-day/items', [MyWorkController::class, 'addToMyDay'])->name('my-day.items.store');
        Route::patch('me/my-day/items/{id}', [MyWorkController::class, 'updateMyDayItem'])->name('my-day.items.update');
        Route::delete('me/my-day/items/{id}', [MyWorkController::class, 'removeFromMyDay'])->name('my-day.items.destroy');
        Route::post('me/my-day/carry-over', [MyWorkController::class, 'carryOver'])->name('my-day.carry-over');
        // the TV wallboard: work due today / overdue + My Day + today's events in one call (ETag → 304)
        Route::get('me/today', TodayController::class)->name('today');

        // ── Calendar (CalendarEvents: the web calendar's sources), meetings, the ICS feed link ─────
        Route::get('calendar/events', [CalendarController::class, 'events'])->name('calendar.events');
        Route::get('calendar/connections', [CalendarController::class, 'connections'])->name('calendar.connections');
        // writes on the user's own writable calendars (CalendarProviders::writable); {event} = event:<connection>:<id>
        Route::post('calendar/events', [CalendarEventController::class, 'store'])->middleware('throttle:30,1,app.calendar.events.store')->name('calendar.events.store');
        Route::get('calendar/events/{event}', [CalendarEventController::class, 'show'])->name('calendar.events.show');
        Route::patch('calendar/events/{event}', [CalendarEventController::class, 'update'])->name('calendar.events.update');
        Route::delete('calendar/events/{event}', [CalendarEventController::class, 'destroy'])->name('calendar.events.destroy');
        Route::get('calendar/meeting-calendars', [CalendarEventController::class, 'meetingCalendars'])->name('calendar.meeting-calendars');
        // online meetings of issues / docs (MeetingScheduler, MeetingPolicy)
        Route::get('meetings', [MeetingController::class, 'index'])->name('meetings.index');
        Route::post('meetings', [MeetingController::class, 'store'])->middleware('throttle:20,1,app.meetings.store')->name('meetings.store');
        Route::delete('meetings/{meeting}', [MeetingController::class, 'destroy'])->middleware('throttle:30,1,app.meetings.destroy')->name('meetings.destroy');
        Route::post('meetings/{meeting}/notes', [MeetingController::class, 'notes'])->middleware('throttle:20,1,app.meetings.notes')->name('meetings.notes');
        // the private ICS feed link (CalendarFeeds)
        Route::get('me/calendar-feed', [CalendarFeedController::class, 'show'])->name('calendar-feed.show');
        Route::put('me/calendar-feed', [CalendarFeedController::class, 'update'])->middleware('throttle:20,1,app.calendar-feed.update')->name('calendar-feed.update');
        Route::post('me/calendar-feed/reset', [CalendarFeedController::class, 'reset'])->middleware('throttle:10,1,app.calendar-feed.reset')->name('calendar-feed.reset');
        Route::delete('me/calendar-feed', [CalendarFeedController::class, 'destroy'])->name('calendar-feed.destroy');

        // ── Bookings, host side (BookingRows, BookingService); the public page + guest links: web only ─
        Route::get('booking-pages', [BookingController::class, 'pages'])->name('booking-pages.index');
        Route::get('bookings', [BookingController::class, 'index'])->name('bookings.index');
        Route::get('bookings/{booking}', [BookingController::class, 'show'])->name('bookings.show');
        Route::post('bookings/{booking}/cancel', [BookingController::class, 'cancel'])->middleware('throttle:30,1,app.bookings.cancel')->name('bookings.cancel');

        // ── Helpdesk, agent side (TicketActions, TicketPolicy); the customer widget / portal: public routes ─
        Route::get('projects/{project}/helpdesks', [HelpdeskController::class, 'index'])->name('helpdesks.index');
        Route::get('helpdesks/{helpdesk}', [HelpdeskController::class, 'show'])->name('helpdesks.show');
        Route::get('helpdesks/{helpdesk}/tickets', [HelpdeskController::class, 'tickets'])->name('helpdesks.tickets');
        Route::get('tickets/{ticket}', [HelpdeskController::class, 'ticket'])->name('tickets.show');
        Route::patch('tickets/{ticket}', [HelpdeskController::class, 'update'])->name('tickets.update');
        Route::post('tickets/{ticket}/replies', [HelpdeskController::class, 'reply'])->middleware('throttle:60,1,app.tickets.replies')->name('tickets.replies.store');

        // ── Leads, member side (LeadActions, LeadFormPolicy); the public form and the builder: web only ──
        Route::get('projects/{project}/lead-forms', [LeadController::class, 'forms'])->name('lead-forms.index');
        Route::get('lead-forms/{form}/leads', [LeadController::class, 'leads'])->name('lead-forms.leads');
        Route::get('lead-forms/{form}/analytics', [LeadController::class, 'analytics'])->name('lead-forms.analytics');
        Route::get('leads/{lead}', [LeadController::class, 'show'])->name('leads.show');
        Route::patch('leads/{lead}', [LeadController::class, 'update'])->name('leads.update');

        // ── Portals, team side (FeedbackModeration, Deliverables); voters' and clients' side: public routes ─
        Route::get('projects/{project}/feedback-portal', [PortalController::class, 'feedbackPortal'])->name('feedback-portal.show');
        Route::get('feedback-portals/{portal}/posts', [PortalController::class, 'feedbackPosts'])->name('feedback-portals.posts');
        Route::patch('feedback-posts/{post}', [PortalController::class, 'updateFeedbackPost'])->name('feedback-posts.update');
        Route::post('feedback-posts/{post}/moderate', [PortalController::class, 'moderateFeedbackPost'])->name('feedback-posts.moderate');
        Route::get('projects/{project}/client-portal', [PortalController::class, 'clientPortal'])->name('client-portal.show');
        Route::get('client-portals/{portal}/deliverables', [PortalController::class, 'deliverables'])->name('client-portals.deliverables');
        Route::get('client-deliverables/{deliverable}', [PortalController::class, 'deliverable'])->name('client-deliverables.show');

        // ── Insights, project health, workload: read-only summaries (the web's Support classes) ────
        Route::get('workspaces/{workspace:slug}/insights/{tab}', [InsightsController::class, 'insights'])->name('insights.show');
        Route::get('projects/{project}/health', [InsightsController::class, 'health'])->name('projects.health');
        Route::get('projects/{project}/workload', [InsightsController::class, 'workload'])->name('projects.workload');

        // ── Task lists + to-dos (TaskLists, WorkCompletion: shared with the web, CLI and MCP) ──────
        // lists and tasks by id; ids on writes are checked in the controller (no route constraint)
        Route::get('projects/{project}/task-lists', [TaskListController::class, 'index'])->whereNumber('project')->name('task-lists.index');
        Route::post('projects/{project}/task-lists', [TaskListController::class, 'store'])->name('task-lists.store');
        Route::get('task-lists/{list}', [TaskListController::class, 'show'])->whereNumber('list')->name('task-lists.show');
        Route::get('task-lists/{list}/items', [TaskListController::class, 'items'])->whereNumber('list')->name('task-lists.items.index');
        Route::post('task-lists/{list}/items', [TaskListController::class, 'storeItem'])->name('task-lists.items.store');
        Route::post('task-lists/{list}/items/reorder', [TaskListController::class, 'reorder'])->name('task-lists.items.reorder');
        Route::patch('task-lists/{list}/items/{item}', [TaskListController::class, 'updateItem'])->name('task-lists.items.update');
        Route::delete('task-lists/{list}/items/{item}', [TaskListController::class, 'destroyItem'])->name('task-lists.items.destroy');

        // ── Captures: the private inbox (CaptureInbox, CaptureConversion: shared with the web, extension, MCP)
        // {capture} = the uuid, checked in the controller (no route constraint on writes)
        Route::get('captures', [CaptureController::class, 'index'])->name('captures.index');
        Route::get('captures/counts', [CaptureController::class, 'counts'])->name('captures.counts');
        Route::post('captures', [CaptureController::class, 'store'])->middleware('throttle:60,1,app.captures.store')->name('captures.store');
        Route::get('captures/{capture}', [CaptureController::class, 'show'])->whereUuid('capture')->name('captures.show');
        Route::patch('captures/{capture}', [CaptureController::class, 'update'])->name('captures.update');
        Route::delete('captures/{capture}', [CaptureController::class, 'destroy'])->name('captures.destroy');
        Route::post('captures/{capture}/archive', [CaptureController::class, 'archive'])->name('captures.archive');
        Route::post('captures/{capture}/restore', [CaptureController::class, 'restore'])->name('captures.restore');
        Route::get('captures/{capture}/preview', [CaptureController::class, 'preview'])->whereUuid('capture')->name('captures.preview');
        Route::post('captures/{capture}/convert', [CaptureController::class, 'convert'])->middleware('throttle:60,1,app.captures.convert')->name('captures.convert');
        Route::get('capture-destinations', [CaptureController::class, 'destinations'])->name('captures.destinations');

        // ── Snippets (Snippets: shared with the web; SnippetPolicy) ──────────────────────────────
        Route::get('workspaces/{workspace:slug}/snippet-collections', [SnippetController::class, 'index'])->name('snippet-collections.index');
        Route::post('workspaces/{workspace:slug}/snippet-collections', [SnippetController::class, 'store'])->name('snippet-collections.store');
        Route::get('snippet-collections/{collection}', [SnippetController::class, 'show'])->whereNumber('collection')->name('snippet-collections.show');
        Route::patch('snippet-collections/{collection}', [SnippetController::class, 'update'])->name('snippet-collections.update');
        Route::delete('snippet-collections/{collection}', [SnippetController::class, 'destroy'])->name('snippet-collections.destroy');
        Route::get('snippet-collections/{collection}/snippets', [SnippetController::class, 'snippets'])->whereNumber('collection')->name('snippets.index');
        Route::post('snippet-collections/{collection}/snippets', [SnippetController::class, 'storeSnippet'])->name('snippets.store');
        Route::get('snippets/{snippet}', [SnippetController::class, 'showSnippet'])->whereNumber('snippet')->name('snippets.show');
        Route::patch('snippets/{snippet}', [SnippetController::class, 'updateSnippet'])->name('snippets.update');
        Route::delete('snippets/{snippet}', [SnippetController::class, 'destroySnippet'])->name('snippets.destroy');

        // ── Bookmarks (Bookmarks: shared with the web; BookmarkPolicy) ───────────────────────────
        // {group}, not {bookmark}: that parameter has a slug binding in AppServiceProvider
        Route::get('workspaces/{workspace:slug}/bookmarks', [BookmarkController::class, 'index'])->name('bookmarks.index');
        Route::post('workspaces/{workspace:slug}/bookmarks', [BookmarkController::class, 'store'])->name('bookmarks.store');
        Route::get('bookmarks/{group}', [BookmarkController::class, 'show'])->whereNumber('group')->name('bookmarks.show');
        Route::patch('bookmarks/{group}', [BookmarkController::class, 'update'])->name('bookmarks.update');
        Route::delete('bookmarks/{group}', [BookmarkController::class, 'destroy'])->name('bookmarks.destroy');
        Route::get('bookmarks/{group}/items', [BookmarkController::class, 'items'])->whereNumber('group')->name('bookmarks.items.index');
        Route::post('bookmarks/{group}/items', [BookmarkController::class, 'storeItem'])->name('bookmarks.items.store');
        Route::patch('bookmarks/{group}/items/{item}', [BookmarkController::class, 'updateItem'])->name('bookmarks.items.update');
        Route::delete('bookmarks/{group}/items/{item}', [BookmarkController::class, 'destroyItem'])->name('bookmarks.items.destroy');

        // ── Time tracking: the focus timer, own work logs, my timesheet (WorkTimers, TimeEntries:
        // shared with the web). Rates, clients, VAT, invoices: web only.
        Route::get('me/timer', [TimerController::class, 'show'])->name('timer.show');
        Route::post('me/timer/start', [TimerController::class, 'start'])->name('timer.start');
        Route::post('me/timer/stop', [TimerController::class, 'stop'])->name('timer.stop');
        Route::post('me/timer/discard', [TimerController::class, 'discard'])->name('timer.discard');
        Route::get('me/timesheet', [WorkLogController::class, 'timesheet'])->name('timesheet.show');
        Route::get('work-logs', [WorkLogController::class, 'index'])->name('work-logs.index');
        Route::post('work-logs', [WorkLogController::class, 'store'])->middleware('throttle:120,1,app.work-logs.store')->name('work-logs.store');
        Route::get('work-logs/{log}', [WorkLogController::class, 'show'])->whereNumber('log')->name('work-logs.show');
        Route::patch('work-logs/{log}', [WorkLogController::class, 'update'])->middleware('throttle:120,1,app.work-logs.update')->name('work-logs.update');
        Route::delete('work-logs/{log}', [WorkLogController::class, 'destroy'])->name('work-logs.destroy');

        // ── Boards: read + move cards (BoardCards, WorkCompletion: shared with the web) ─────────────
        // boards and issues by id; ids on writes are checked in the controller (no route constraint).
        // Columns, swimlanes, board settings: web only.
        Route::get('projects/{project}/boards', [BoardController::class, 'index'])->whereNumber('project')->name('boards.index');
        Route::get('boards/{board}', [BoardController::class, 'show'])->whereNumber('board')->name('boards.show');
        Route::get('boards/{board}/items', [BoardController::class, 'items'])->whereNumber('board')->name('boards.items.index');
        Route::post('items/{item}/move', [BoardController::class, 'move'])->name('items.move');
        // what issue forms and filters offer (types, priorities, labels, fields, releases) + saved views
        Route::get('projects/{project}/config', [BoardController::class, 'config'])->whereNumber('project')->name('projects.config');
        Route::get('boards/{board}/views', [BoardController::class, 'views'])->whereNumber('board')->name('boards.views');

        // ── Issues: create, view, edit, assign, delete (Issues: shared with the web's issue dialog) ─────
        Route::post('boards/{board}/items', [IssueController::class, 'store'])->name('items.store');
        // timeline / calendar: a project's dated issues (reschedule with PATCH items/{item}); recurring issues: web only
        Route::get('projects/{project}/items', [IssueController::class, 'dated'])->whereNumber('project')->name('items.dated');
        Route::get('items/by-key/{key}', [IssueController::class, 'byKey'])->where('key', '[A-Za-z0-9_-]{1,64}')->name('items.by-key');
        Route::get('items/{item}', [IssueController::class, 'show'])->whereNumber('item')->name('items.show');
        Route::patch('items/{item}', [IssueController::class, 'update'])->name('items.update');
        Route::delete('items/{item}', [IssueController::class, 'destroy'])->name('items.destroy');

        // ── An issue's comments, watchers, files, history (ItemComments, ItemAttachments: shared with the web)
        // editing / deleting comments: not on the web either
        Route::get('items/{item}/comments', [IssueActivityController::class, 'comments'])->whereNumber('item')->name('items.comments.index');
        Route::post('items/{item}/comments', [IssueActivityController::class, 'storeComment'])->middleware('throttle:60,1,app.items.comments.store')->name('items.comments.store');
        Route::get('items/{item}/watchers', [IssueActivityController::class, 'watchers'])->whereNumber('item')->name('items.watchers');
        Route::post('items/{item}/watch', [IssueActivityController::class, 'watch'])->name('items.watch');
        Route::delete('items/{item}/watch', [IssueActivityController::class, 'unwatch'])->name('items.unwatch');
        Route::get('items/{item}/attachments', [IssueActivityController::class, 'attachments'])->whereNumber('item')->name('items.attachments.index');
        Route::post('items/{item}/attachments', [IssueActivityController::class, 'storeAttachment'])->middleware('throttle:30,1,app.items.attachments.store')->name('items.attachments.store');
        Route::delete('items/{item}/attachments/{attachment}', [IssueActivityController::class, 'destroyAttachment'])->name('items.attachments.destroy');
        Route::get('items/{item}/activity', [IssueActivityController::class, 'activity'])->whereNumber('item')->name('items.activity');

        // ── Subtasks + linked issues (ItemLinks: shared with the web's issue view and the MCP) ─────
        Route::get('items/{item}/children', [IssueLinkController::class, 'children'])->whereNumber('item')->name('items.children');
        Route::get('items/{item}/links', [IssueLinkController::class, 'index'])->whereNumber('item')->name('items.links.index');
        Route::post('items/{item}/links', [IssueLinkController::class, 'store'])->name('items.links.store');
        Route::delete('items/{item}/links/{link}', [IssueLinkController::class, 'destroy'])->name('items.links.destroy');

        // ── Scrum: sprints, backlog, burndown, velocity (SprintLifecycle, SprintReport: shared with the web + MCP)
        // creating / starting / completing sprints and ranking: web only
        Route::get('boards/{board}/sprints', [SprintController::class, 'index'])->whereNumber('board')->name('sprints.index');
        Route::get('boards/{board}/backlog', [SprintController::class, 'backlog'])->whereNumber('board')->name('sprints.backlog');
        Route::post('boards/{board}/backlog/items', [SprintController::class, 'moveToBacklog'])->name('sprints.backlog.move');
        Route::get('boards/{board}/velocity', [SprintController::class, 'velocity'])->whereNumber('board')->name('sprints.velocity');
        Route::get('sprints/{sprint}', [SprintController::class, 'show'])->whereNumber('sprint')->name('sprints.show');
        Route::get('sprints/{sprint}/items', [SprintController::class, 'items'])->whereNumber('sprint')->name('sprints.items');
        Route::post('sprints/{sprint}/items', [SprintController::class, 'moveToSprint'])->name('sprints.move');
        Route::get('sprints/{sprint}/burndown', [SprintController::class, 'burndown'])->whereNumber('sprint')->name('sprints.burndown');

        // ── Docs: page tree, read, create, edit, publish, move (PageWrites, PageMover: shared with the web) ──
        // pages by uuid (checked in the controller on writes); text changes need the revision (409), like the web editor
        Route::get('projects/{project}/pages', [PageController::class, 'index'])->whereNumber('project')->name('pages.index');
        Route::post('projects/{project}/pages', [PageController::class, 'store'])->middleware('throttle:60,1,app.pages.store')->name('pages.store');
        Route::get('pages/{uuid}', [PageController::class, 'show'])->whereUuid('uuid')->name('pages.show');
        Route::patch('pages/{uuid}', [PageController::class, 'update'])->middleware('throttle:120,1,app.pages.update')->name('pages.update');
        Route::post('pages/{uuid}/publish', [PageController::class, 'publish'])->name('pages.publish');
        Route::post('pages/{uuid}/move', [PageController::class, 'move'])->name('pages.move');
        // history, reviews (inline threads, decisions), links / backlinks, smart link chips (PageReviews, ContentLinkFormatter,
        // SmartLinks: shared with the web). Requesting reviews, restoring versions: web only
        Route::get('pages/{uuid}/versions', [DocReviewController::class, 'versions'])->whereUuid('uuid')->name('pages.versions.index');
        Route::get('pages/{uuid}/versions/{version}', [DocReviewController::class, 'version'])->whereUuid('uuid')->whereNumber('version')->name('pages.versions.show');
        Route::get('pages/{uuid}/reviews', [DocReviewController::class, 'reviews'])->whereUuid('uuid')->name('pages.reviews.index');
        Route::get('pages/{uuid}/reviews/{review}', [DocReviewController::class, 'review'])->whereUuid('uuid')->whereUuid('review')->name('pages.reviews.show');
        Route::post('pages/{uuid}/reviews/{review}/decision', [DocReviewController::class, 'decide'])->middleware('throttle:30,1,app.pages.reviews.decide')->name('pages.reviews.decide');
        Route::post('pages/{uuid}/reviews/{review}/comments', [DocReviewController::class, 'storeComment'])->middleware('throttle:60,1,app.pages.reviews.comments')->name('pages.reviews.comments.store');
        Route::patch('pages/{uuid}/review-threads/{thread}', [DocReviewController::class, 'resolveThread'])->name('pages.reviews.threads.update');
        Route::get('pages/{uuid}/links', [DocReviewController::class, 'links'])->whereUuid('uuid')->name('pages.links');
        Route::get('links/resolve', [LinkController::class, 'resolve'])->middleware('throttle:240,1,app.links.resolve')->name('links.resolve');
        // live co-editing for native clients: the browser's three calls (PageCollab), same throttles as the web
        Route::post('pages/{uuid}/collab-token', [PageCollabController::class, 'token'])->middleware('throttle:60,1,app.pages.collab.token')->name('pages.collab.token');
        Route::post('pages/{uuid}/collab/bootstrap', [PageCollabController::class, 'bootstrap'])->middleware('throttle:30,1,app.pages.collab.bootstrap')->name('pages.collab.bootstrap');
        Route::put('pages/{uuid}/collab/draft', [PageCollabController::class, 'save'])->middleware('throttle:180,1,app.pages.collab.save')->name('pages.collab.save');

        // ── Whiteboards: the scene, read-only (drawing, templates, stickies → issues: web / desktop webview) ──
        Route::get('whiteboards/{uuid}', [WhiteboardController::class, 'show'])->whereUuid('uuid')->name('whiteboards.show');

        // ── Gantt plans + releases, read-only (PlanReader: shared with the MCP; editing: web only) ──────
        Route::get('projects/{project}/plans', [PlanController::class, 'index'])->whereNumber('project')->name('plans.index');
        Route::get('plans/{plan}', [PlanController::class, 'show'])->whereNumber('plan')->name('plans.show');
        Route::get('projects/{project}/releases', [ReleaseController::class, 'index'])->whereNumber('project')->name('releases.index');
        Route::get('releases/{release}', [ReleaseController::class, 'show'])->whereNumber('release')->name('releases.show');

        // ── Files: uploads for rich text (EditorUploads + FileRoom: the web editor's rules and storage limit) ──
        Route::post('uploads', [UploadController::class, 'store'])->middleware('throttle:30,1,app.uploads.store')->name('uploads.store');

        // ── Connected apps + "Download my data" (account deletion: web only) ────────────────────
        Route::get('me/connected-apps', [ConnectedAppController::class, 'index'])->name('connected-apps.index');
        // {id} = device:<id> | oauth:<client uuid>, checked in the controller (a route constraint would
        // turn a malformed id into a 405 through web.php's GET fallback route)
        Route::delete('me/connected-apps/{id}', [ConnectedAppController::class, 'destroy'])->name('connected-apps.destroy');
        Route::get('me/data-export', [ConnectedAppController::class, 'export'])->name('data-export.show');
        Route::post('me/data-export', [ConnectedAppController::class, 'requestExport'])->middleware('throttle:5,1,app.data-export')->name('data-export.store');

        // ── Offline sync (ChangeFeed + the web's OfflineSync engine: same types, appliers, idempotency) ──
        Route::get('sync/changes', [SyncController::class, 'changes'])->middleware('throttle:120,1,app.sync.changes')->name('sync.changes');
        Route::post('sync', [SyncController::class, 'store'])->middleware('throttle:60,1,app.sync.store')->name('sync.store');

    });

});
