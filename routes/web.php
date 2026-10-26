<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\AiController;
use App\Http\Controllers\BoardBulkItemController;
use App\Http\Controllers\BoardController;
use App\Http\Controllers\BoardViewController;
use App\Http\Controllers\BookmarkController;
use App\Http\Controllers\CalendarConnectionController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\CaptureInboxController;
use App\Http\Controllers\CommandPaletteController;
use App\Http\Controllers\ContentReferenceController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\HelpdeskController;
use App\Http\Controllers\HelpdeskForwardingController;
use App\Http\Controllers\HelpdeskMailboxController;
use App\Http\Controllers\HelpdeskWidgetController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\ItemFieldController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\LeadFormController;
use App\Http\Controllers\MeetingNotesController;
use App\Http\Controllers\MyDayController;
use App\Http\Controllers\MyWorkController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PageReviewController;
use App\Http\Controllers\PageStickyNoteController;
use App\Http\Controllers\PageVersionController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\PollVoteController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectGithubController;
use App\Http\Controllers\ProjectHealthController;
use App\Http\Controllers\ProjectTemplateController;
use App\Http\Controllers\PublicLeadFormController;
use App\Http\Controllers\PublicPlanController;
use App\Http\Controllers\PublicReleaseController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\ReleaseController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\ShareLinkController;
use App\Http\Controllers\SidebarPreferencesController;
use App\Http\Controllers\SnippetController;
use App\Http\Controllers\SprintController;
use App\Http\Controllers\TaskListController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\WhiteboardController;
use App\Http\Controllers\WorkloadController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Controllers\WorkTimerController;
use App\Http\Middleware\ReadOnlySession;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'canLoginWithGoogle' => (bool) config('services.google.client_id'),
        'canLoginWithGitHub' => (bool) config('services.github.client_id'),
    ])->withViewData(['meta' => [
        // link previews of the homepage (Open Graph / X), see resources/views/partials/social-meta.blade.php
        'title' => 'Brainitive – Workspaces, Boards & Wiki',
        'description' => 'Boards, sprints, docs, whiteboards, Gantt plans, helpdesk and client billing — one workspace for the whole project, from the first workshop to the last release.',
        'url' => url('/'),
    ]]);
});

Route::get('/privacy', function () {
    return Inertia::render('Privacy');
})->name('privacy');

Route::get('/terms', function () {
    return Inertia::render('Terms');
})->name('terms');

Route::get('/cookies', function () {
    return Inertia::render('Cookies');
})->name('cookies');

Route::get('/refund', function () {
    return Inertia::render('Refund');
})->name('refund');

// PWA / Web app manifest — installable app icon on mobile and desktop
Route::get('/site.webmanifest', function () {
    $icon192 = file_exists(public_path('android-chrome-192x192.png'))
        ? asset('android-chrome-192x192.png')
        : asset('images/logo.png');
    $icon512 = file_exists(public_path('android-chrome-512x512.png'))
        ? asset('android-chrome-512x512.png')
        : asset('images/logo.png');

    return response()->json([
        'name' => config('app.name'),
        'short_name' => config('app.name'),
        'description' => 'All-in-one workspace for teams: boards, wiki, docs, whiteboard, and bookmarks.',
        'start_url' => '/',
        'scope' => '/',
        'display' => 'standalone',
        'orientation' => 'any',
        'theme_color' => '#0E83DC',
        'background_color' => '#F4F6F8',
        'icons' => [
            ['src' => $icon192, 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => $icon512, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ],
        'categories' => ['productivity', 'business'],
    ], 200, [
        'Content-Type' => 'application/manifest+json',
        'Cache-Control' => 'public, max-age=3600',
    ]);
})->name('manifest');

// Public bookmark view (view only, no auth)
Route::get('/share/bookmarks/{uuid}', [BookmarkController::class, 'showPublic'])->name('bookmarks.public.show');

// Public snippet share views (no auth)
Route::get('/share/snippets/{uuid}', [SnippetController::class, 'showPublicSnippet'])->name('snippets.public.show');
Route::get('/share/plans/{token}', [PublicPlanController::class, 'show'])->name('plans.public');
// Read-only link to one doc / issue (ShareLink); no login
Route::get('/r/{token}', [PublicReleaseController::class, 'show'])->where('token', '[A-Za-z0-9]{20,64}')->middleware('throttle:120,1,releases.public')->name('releases.public');
Route::get('/changelog/{token}', [PublicReleaseController::class, 'changelog'])->where('token', '[A-Za-z0-9]{20,64}')->middleware('throttle:120,1,changelog.public')->name('changelog.public');
Route::get('/s/{token}', [ShareLinkController::class, 'show'])->where('token', '[A-Za-z0-9]{20,64}')->middleware('throttle:120,1,share.show')->name('share.show');
Route::get('/share/snippet-collections/{uuid}', [SnippetController::class, 'showPublicCollection'])->name('snippet-collections.public.show');

// Embed test page (same-origin; use this URL to verify iframe works)
Route::get('/embed-test/{helpdesk:uuid}', [HelpdeskWidgetController::class, 'embedTestPage'])->name('helpdesks.embed-test');

// Public lead forms (BRAI-93): page to promote, iframe embed, submit + analytics (no CSRF: f/*)
Route::prefix('f/{publicSlug}')->where(['publicSlug' => '[a-z0-9-]{3,60}'])->group(function () {
    Route::get('/', [PublicLeadFormController::class, 'show'])->middleware('throttle:120,1,lead-forms.public')->name('lead-forms.public');
    Route::get('embed', [PublicLeadFormController::class, 'embed'])->middleware('throttle:120,1,lead-forms.embed')->name('lead-forms.embed');
    Route::post('submit', [PublicLeadFormController::class, 'submit'])->middleware('throttle:10,1,lead-forms.submit')->name('lead-forms.submit');
    Route::post('events', [PublicLeadFormController::class, 'event'])->middleware('throttle:120,1,lead-forms.events')->name('lead-forms.events');
});

// Public helpdesk widget and portal (no auth, CORS for embedding on external sites)
Route::middleware('cors')->group(function () {
    Route::get('/widget/helpdesks/{helpdesk:uuid}/form', [HelpdeskWidgetController::class, 'formConfig'])->name('helpdesks.widget.form');
    Route::get('/widget/helpdesks/{helpdesk:uuid}/embed', [HelpdeskWidgetController::class, 'embed'])->name('helpdesks.widget.embed');
    Route::post('/widget/helpdesks/{helpdesk:uuid}/tickets', [TicketController::class, 'storePublic'])->middleware('throttle:20,1,helpdesks.tickets.store-public')->name('helpdesks.tickets.store-public');
    Route::get('/widget/tickets/{ticket:uuid}', [TicketController::class, 'getPublicTicketJson'])->name('helpdesks.tickets.get-public');
    Route::post('/widget/helpdesks/{helpdesk:uuid}/tickets/{ticket:uuid}/replies', [TicketController::class, 'storePublicReply'])->middleware('throttle:20,1,helpdesks.tickets.replies.store-public')->name('helpdesks.tickets.replies.store-public');
    Route::post('/widget/helpdesks/{helpdesk:uuid}/tickets/{ticket:uuid}/attachments', [TicketController::class, 'storePublicAttachment'])->middleware('throttle:20,1,helpdesks.tickets.attachments.store-public')->name('helpdesks.tickets.attachments.store-public');
    Route::get('/portal/tickets/{ticket:uuid}', [TicketController::class, 'showPublic'])->name('helpdesks.tickets.show-public');
    Route::get('/support/{publicSlug}', [HelpdeskWidgetController::class, 'supportPage'])->where('publicSlug', '[a-z0-9-]+')->name('helpdesks.support-page');
    Route::get('/portal/tickets/{ticket:uuid}/replies', [TicketController::class, 'publicReplies'])->name('helpdesks.tickets.replies.public');
});

// Feedback + client portals and guest sign-in (v1.42.0) — people outside the workspace
require __DIR__.'/portal.php';

// Private calendar feed for Apple / Outlook / Google Calendar (BRAI-142): public .ics + owner JSON
require __DIR__.'/calendar-feed.php';

// Workspace invitation (guest can view; auth user with matching email can accept)
Route::get('/invite/{token}', [InvitationController::class, 'show'])->name('invitations.show');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::post('/invite/{token}/accept', [InvitationController::class, 'accept'])->name('invitations.accept');

    // Dashboard redirects to workspaces
    Route::get('/dashboard', function () {
        return redirect()->route('workspaces.index');
    })->name('dashboard');

    // Global search (bookmarks, board items, documents)
    Route::get('/search', SearchController::class)->name('search');
    Route::post('/command-palette/recent', [CommandPaletteController::class, 'recent'])->name('command-palette.recent');

    // My Work — assigned items across all workspaces
    Route::get('/my-work', [MyWorkController::class, 'index'])->name('my-work.index');
    Route::post('/my-day', [MyDayController::class, 'store'])->name('my-day.store');
    Route::delete('/my-day', [MyDayController::class, 'destroy'])->name('my-day.destroy');
    Route::post('/my-day/reorder', [MyDayController::class, 'reorder'])->name('my-day.reorder');
    Route::post('/my-day/carry-over', [MyDayController::class, 'carryOver'])->name('my-day.carry-over');
    Route::post('/work-timer', [WorkTimerController::class, 'start'])->name('work-timer.start');
    Route::post('/work-timer/stop', [WorkTimerController::class, 'stop'])->name('work-timer.stop');
    Route::delete('/work-timer', [WorkTimerController::class, 'discard'])->name('work-timer.discard');

    // Quick capture (BRAI-70): private inbox of ideas, organized later into content
    Route::get('/captures', [CaptureInboxController::class, 'index'])->name('captures.index');
    Route::post('/captures', [CaptureInboxController::class, 'store'])->middleware('throttle:60,1,captures.store')->name('captures.store');
    Route::get('/captures/destinations', [CaptureInboxController::class, 'destinations'])->middleware(ReadOnlySession::class)->name('captures.destinations');
    Route::patch('/captures/{capture}', [CaptureInboxController::class, 'update'])->name('captures.update');
    Route::delete('/captures/{capture}', [CaptureInboxController::class, 'destroy'])->name('captures.destroy');
    Route::post('/captures/{capture}/archive', [CaptureInboxController::class, 'archive'])->name('captures.archive');
    Route::post('/captures/{capture}/restore', [CaptureInboxController::class, 'restore'])->name('captures.restore');
    Route::get('/captures/{capture}/preview', [CaptureInboxController::class, 'preview'])->middleware(ReadOnlySession::class)->name('captures.preview');
    Route::post('/captures/{capture}/convert', [CaptureInboxController::class, 'convert'])->middleware('throttle:60,1,captures.convert')->name('captures.convert');

    // Share links for docs and issues (people who can edit them)
    Route::get('/share-links', [ShareLinkController::class, 'index'])->name('share-links.index');
    Route::post('/share-links', [ShareLinkController::class, 'store'])->middleware('throttle:30,1,share-links.store')->name('share-links.store');
    Route::delete('/share-links/{shareLink}', [ShareLinkController::class, 'destroy'])->name('share-links.destroy');

    // Poll votes (polls inside docs / issue descriptions)
    Route::get('/polls/{scopeType}/{scopeId}/{pollId}', [PollVoteController::class, 'show'])
        ->whereIn('scopeType', ['page', 'item'])->where('pollId', '[A-Za-z0-9:_-]{1,120}')->name('polls.show');
    Route::post('/polls/{scopeType}/{scopeId}/{pollId}', [PollVoteController::class, 'vote'])
        ->whereIn('scopeType', ['page', 'item'])->where('pollId', '[A-Za-z0-9:_-]{1,120}')->middleware('throttle:60,1,polls.vote')->name('polls.vote');

    // Calendar — personal calendar across all workspaces
    Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar.index');
    Route::patch('/calendar/view', [CalendarController::class, 'updateView'])->name('calendar.view.update');
    Route::patch('/calendar/reschedule', [CalendarController::class, 'reschedule'])->name('calendar.reschedule');

    // Calendar connections (external providers through App\Support\Calendar\CalendarProviders)
    Route::prefix('calendar/connections')->controller(CalendarConnectionController::class)->group(function () {
        Route::get('google/redirect', 'redirectToGoogle')->name('calendar.connections.google.redirect');
        Route::get('google/callback', 'googleCallback')->name('calendar.connections.google.callback');
        Route::get('pending', 'pendingPicker')->name('calendar.connections.pending');
        Route::post('/', 'store')->name('calendar.connections.store');
        // Generic connect for 'form' / 'url' providers (CalDAV, ICS links, …) — BRAI-140.
        Route::post('manual/{provider}', 'manualStore')->where('provider', '[a-z0-9_-]+')
            ->middleware('throttle:10,1')->name('calendar.connections.manual.store');
        Route::patch('{connection}/toggle', 'toggle')->name('calendar.connections.toggle');
        Route::delete('{connection}', 'destroy')->name('calendar.connections.destroy');
        Route::post('{connection}/events', 'createEvent')->name('calendar.connections.events.create');
        Route::get('{connection}/events/{eventId}', 'showEvent')->name('calendar.connections.events.show')->where('eventId', '[^/]+');
        Route::patch('{connection}/events/{eventId}', 'updateEvent')->name('calendar.connections.events.update')->where('eventId', '[^/]+');
        Route::delete('{connection}/events/{eventId}', 'deleteEvent')->name('calendar.connections.events.destroy')->where('eventId', '[^/]+');

        Route::post('{connection}/tasks', 'createTask')->name('calendar.connections.tasks.create');
        Route::get('{connection}/tasks/{taskId}', 'showTask')->name('calendar.connections.tasks.show')->where('taskId', '[A-Za-z0-9_-]+');
        Route::patch('{connection}/tasks/{taskId}', 'updateTask')->name('calendar.connections.tasks.update')->where('taskId', '[A-Za-z0-9_-]+');
        Route::delete('{connection}/tasks/{taskId}', 'deleteTask')->name('calendar.connections.tasks.destroy')->where('taskId', '[A-Za-z0-9_-]+');
    });

    // Workspaces
    Route::resource('workspaces', WorkspaceController::class);

    // Workspace member management
    Route::post('workspaces/{workspace}/members', [WorkspaceController::class, 'inviteMember'])->name('workspaces.members.invite');
    Route::patch('workspaces/{workspace}/members/{user}', [WorkspaceController::class, 'updateMemberRole'])->name('workspaces.members.update');
    Route::delete('workspaces/{workspace}/members/{user}', [WorkspaceController::class, 'removeMember'])->name('workspaces.members.remove');
    Route::delete('workspaces/{workspace}/invitations/{invitation}', [WorkspaceController::class, 'cancelInvitation'])->name('workspaces.invitations.destroy');

    // Workspace-scoped routes
    Route::prefix('workspaces/{workspace}')->group(function () {
        // Time tracking & client billing (v1.38.0)
        require __DIR__.'/billing.php';
        // Outgoing webhooks + admin insights (v1.39.0)
        require __DIR__.'/webhooks.php';
        require __DIR__.'/insights.php';
        // Bring your data: imports + project export / restore (v1.41.0)
        require __DIR__.'/imports.php';

        Route::get('users', [WorkspaceController::class, 'users'])->name('workspaces.users.index');
        Route::get('lead-form-templates', [LeadFormController::class, 'templates'])->name('lead-form-templates.index');
        Route::get('members/{user}', [WorkspaceController::class, 'memberProfile'])->name('workspaces.members.profile');

        // AI settings & generation
        Route::get('ai/settings', [AiController::class, 'getSettings'])->name('ai.settings');
        Route::post('ai/settings', [AiController::class, 'saveSettings'])->name('ai.settings.save');
        Route::post('ai/models', [AiController::class, 'fetchModels'])->name('ai.models');
        Route::post('ai/generate', [AiController::class, 'generate'])->middleware('throttle:30,1,ai.generate')->name('ai.generate');
        Route::post('projects/{project:slug}/ai/assist', [AiController::class, 'projectAssist'])->name('ai.project-assist')->scopeBindings()->middleware('throttle:20,1,ai.project-assist');
        Route::post('projects/{project:slug}/ai/save-doc', [AiController::class, 'saveProjectAssist'])->name('ai.project-assist.save')->scopeBindings();

        // Bookmarks (workspace level)
        Route::get('bookmarks', [BookmarkController::class, 'index'])->name('bookmarks.index');
        Route::get('bookmarks/list', [BookmarkController::class, 'listBookmarks'])->name('bookmarks.list');
        Route::post('bookmarks', [BookmarkController::class, 'store'])->name('bookmarks.store');
        Route::post('bookmarks/upload-icon-temp', [BookmarkController::class, 'uploadIconTemp'])->name('bookmarks.icon.upload-temp');
        Route::get('bookmarks/{bookmark}', [BookmarkController::class, 'show'])->name('bookmarks.show');
        Route::put('bookmarks/{bookmark}', [BookmarkController::class, 'update'])->name('bookmarks.update');
        Route::post('bookmarks/{bookmark}/icon', [BookmarkController::class, 'uploadIcon'])->name('bookmarks.icon.upload');
        Route::delete('bookmarks/{bookmark}', [BookmarkController::class, 'destroy'])->name('bookmarks.destroy');
        Route::post('bookmarks/{bookmark}/items', [BookmarkController::class, 'storeItem'])->name('bookmarks.items.store');
        Route::put('bookmarks/{bookmark}/items/{item}', [BookmarkController::class, 'updateItem'])->name('bookmarks.items.update');
        Route::patch('bookmarks/{bookmark}/items/{item}/toggle', [BookmarkController::class, 'toggleItem'])->name('bookmarks.items.toggle');
        Route::delete('bookmarks/{bookmark}/items/{item}', [BookmarkController::class, 'destroyItem'])->name('bookmarks.items.destroy');
        Route::post('bookmarks/{bookmark}/collaborators', [BookmarkController::class, 'inviteCollaborator'])->name('bookmarks.collaborators.invite');
        Route::put('bookmarks/{bookmark}/collaborators/{user}', [BookmarkController::class, 'updateCollaborator'])->name('bookmarks.collaborators.update');
        Route::delete('bookmarks/{bookmark}/collaborators/{user}', [BookmarkController::class, 'removeCollaborator'])->name('bookmarks.collaborators.remove');

        // Snippets (workspace level)
        Route::get('snippets', [SnippetController::class, 'index'])->name('snippets.index');
        Route::get('snippets/list', [SnippetController::class, 'listCollections'])->name('snippets.list');
        Route::post('snippets', [SnippetController::class, 'storeCollection'])->name('snippets.store');
        Route::post('snippets/upload-icon-temp', [SnippetController::class, 'uploadIconTemp'])->name('snippets.icon.upload-temp');
        Route::get('snippets/{collection}', [SnippetController::class, 'showCollection'])->name('snippets.show');
        Route::put('snippets/{collection}', [SnippetController::class, 'updateCollection'])->name('snippets.update');
        Route::delete('snippets/{collection}', [SnippetController::class, 'destroyCollection'])->name('snippets.destroy');
        Route::post('snippets/{collection}/snippets', [SnippetController::class, 'storeSnippet'])->name('snippets.snippets.store');
        Route::put('snippets/{collection}/snippets/{snippet}', [SnippetController::class, 'updateSnippet'])->name('snippets.snippets.update');
        Route::delete('snippets/{collection}/snippets/{snippet}', [SnippetController::class, 'destroySnippet'])->name('snippets.snippets.destroy');

        // Projects (nested under workspaces) - scoped to workspace
        Route::resource('projects', ProjectController::class)->scoped([
            'project' => 'slug',
        ]);
        Route::post('projects/{project:slug}/icon', [ProjectController::class, 'uploadIcon'])->name('projects.icon.upload')->scopeBindings();
        Route::get('projects/{project:slug}/move-preview', [ProjectController::class, 'movePreview'])->name('projects.move.preview')->scopeBindings();
        Route::post('projects/{project:slug}/move', [ProjectController::class, 'move'])->name('projects.move')->scopeBindings();

        Route::get('project-templates', [ProjectTemplateController::class, 'catalog'])->name('project-templates.catalog');
        Route::get('helpdesk-templates', [HelpdeskController::class, 'templates'])->name('helpdesk-templates.index');
        Route::delete('project-templates/{projectTemplate}', [ProjectTemplateController::class, 'destroy'])->name('project-templates.destroy');

        // Labels (nested under projects)
        Route::prefix('projects/{project:slug}')->group(function () {
            Route::get('labels', [ProjectController::class, 'labelsJson'])->name('projects.labels.index');
            Route::post('labels', [ProjectController::class, 'storeLabel'])->name('projects.labels.store');
            Route::put('labels/{label}', [ProjectController::class, 'updateLabel'])->name('projects.labels.update');
            Route::delete('labels/{label}', [ProjectController::class, 'destroyLabel'])->name('projects.labels.destroy');
            // Item types (project-level configuration)
            Route::post('item-types', [ProjectController::class, 'storeItemType'])->name('projects.item-types.store');
            Route::post('item-types/upload-icon', [ProjectController::class, 'uploadItemTypeIcon'])->name('projects.item-types.upload-icon');
            Route::put('item-types/{itemType}', [ProjectController::class, 'updateItemType'])->name('projects.item-types.update');
            Route::delete('item-types/{itemType}', [ProjectController::class, 'destroyItemType'])->name('projects.item-types.destroy');
            // Custom fields for board issues (the controller checks the field belongs to the project)
            // Save the project as a workspace template (BRAI-72)
            Route::get('template-preview', [ProjectTemplateController::class, 'preview'])->name('projects.templates.preview');
            Route::post('save-as-template', [ProjectTemplateController::class, 'store'])->name('projects.templates.store');
            Route::post('fields', [ItemFieldController::class, 'store'])->name('projects.fields.store');
            Route::post('fields/reorder', [ItemFieldController::class, 'reorder'])->name('projects.fields.reorder');
            Route::put('fields/{itemField}', [ItemFieldController::class, 'update'])->whereNumber('itemField')->name('projects.fields.update');
            Route::delete('fields/{itemField}', [ItemFieldController::class, 'destroy'])->whereNumber('itemField')->name('projects.fields.destroy');
        });

        // Automation, recurring issues, helpdesk SLA (v1.40.0)
        Route::prefix('projects/{project:slug}')->scopeBindings()->group(function () {
            require __DIR__.'/automation.php';
        });

        // Client portal, team side (v1.42.0, BRAI-73)
        Route::prefix('projects/{project:slug}')->scopeBindings()->group(function () {
            require __DIR__.'/client-portal.php';
        });
        // Feedback portal moderation (v1.42.0, BRAI-60) — the public side is in portal.php
        Route::prefix('projects/{project:slug}')->scopeBindings()->group(fn () => require __DIR__.'/feedback.php');

        // Project members (assign workspace users to project with role)
        Route::prefix('projects/{project:slug}')->scopeBindings()->group(function () {
            Route::get('members/search', [ProjectController::class, 'searchMembers'])->name('projects.members.search');
            Route::post('members', [ProjectController::class, 'inviteMember'])->name('projects.members.store');
            Route::patch('members/{user}', [ProjectController::class, 'updateMemberRole'])->name('projects.members.update');
            Route::delete('members/{user}', [ProjectController::class, 'removeMember'])->name('projects.members.destroy');
        });

        // Boards & Pages (nested under projects) - scoped to project
        Route::prefix('projects/{project}')->scopeBindings()->group(function () {
            Route::post('boards', [BoardController::class, 'store'])->name('boards.store');
            Route::get('boards/{board:slug}/items', [BoardController::class, 'rootItemsJson'])->name('boards.root-items');
            Route::get('boards/{board:slug}', [BoardController::class, 'show'])->name('boards.show');
            // Saved views (BRAI-78)
            Route::post('boards/{board:slug}/views', [BoardViewController::class, 'store'])->name('boards.views.store');
            Route::post('boards/{board:slug}/views/default', [BoardViewController::class, 'setDefault'])->name('boards.views.default');
            Route::get('boards/{board:slug}/views/{boardView}', [BoardViewController::class, 'open'])->whereNumber('boardView')->name('boards.views.open');
            Route::patch('boards/{board:slug}/views/{boardView}', [BoardViewController::class, 'update'])->whereNumber('boardView')->name('boards.views.update');
            Route::post('boards/{board:slug}/views/{boardView}/duplicate', [BoardViewController::class, 'duplicate'])->whereNumber('boardView')->name('boards.views.duplicate');
            Route::delete('boards/{board:slug}/views/{boardView}', [BoardViewController::class, 'destroy'])->whereNumber('boardView')->name('boards.views.destroy');
            Route::post('github', [ProjectGithubController::class, 'store'])->name('projects.github.store');
            Route::post('github/rotate', [ProjectGithubController::class, 'rotate'])->name('projects.github.rotate');
            Route::delete('github', [ProjectGithubController::class, 'destroy'])->name('projects.github.destroy');
            // GitHub App connect + merge transitions (BRAI-61)
            Route::post('github/app/connect', [ProjectGithubController::class, 'connect'])->middleware('throttle:10,1,projects.github.app.connect')->name('projects.github.app.connect');
            Route::put('github/repositories', [ProjectGithubController::class, 'repositories'])->name('projects.github.repositories');
            Route::put('github/transitions', [ProjectGithubController::class, 'transitions'])->name('projects.github.transitions');
            Route::post('github/refresh', [ProjectGithubController::class, 'refresh'])->middleware('throttle:6,1,projects.github.refresh')->name('projects.github.refresh');
            Route::patch('boards/{board:slug}/items/{item}/development/primary', [ProjectGithubController::class, 'primary'])->name('boards.items.development.primary');
            Route::get('boards/{board:slug}/backlog', [SprintController::class, 'backlog'])->name('boards.backlog');
            Route::post('boards/{board:slug}/backlog/rank', [SprintController::class, 'rank'])->name('boards.backlog.rank');
            Route::get('boards/{board:slug}/reports', [SprintController::class, 'reports'])->name('boards.reports');
            Route::post('boards/{board:slug}/sprints', [SprintController::class, 'store'])->name('boards.sprints.store');
            Route::patch('boards/{board:slug}/sprints/{sprint}', [SprintController::class, 'update'])->name('boards.sprints.update');
            Route::delete('boards/{board:slug}/sprints/{sprint}', [SprintController::class, 'destroy'])->name('boards.sprints.destroy');
            Route::post('boards/{board:slug}/sprints/{sprint}/start', [SprintController::class, 'start'])->name('boards.sprints.start');
            Route::post('boards/{board:slug}/sprints/{sprint}/complete', [SprintController::class, 'complete'])->name('boards.sprints.complete');
            Route::get('boards/{board:slug}/edit', [BoardController::class, 'edit'])->name('boards.edit');
            Route::put('boards/{board:slug}', [BoardController::class, 'update'])->name('boards.update');
            Route::delete('boards/{board:slug}', [BoardController::class, 'destroy'])->name('boards.destroy');
            Route::post('boards/{board:slug}/columns', [BoardController::class, 'storeColumn'])->name('boards.columns.store');
            Route::put('boards/{board:slug}/swimlanes', [BoardController::class, 'updateSwimlanes'])->name('boards.swimlanes.update');
            Route::post('boards/{board:slug}/columns/reorder', [BoardController::class, 'reorderColumns'])->name('boards.columns.reorder');
            Route::post('boards/{board:slug}/items/reorder', [BoardController::class, 'reorderItems'])->name('boards.items.reorder');
            // Bulk edit of the list view (BRAI-108); before items/{item} so "bulk" is never an item id
            Route::patch('boards/{board:slug}/items/bulk', [BoardBulkItemController::class, 'update'])->name('boards.items.bulk.update');
            Route::delete('boards/{board:slug}/items/bulk', [BoardBulkItemController::class, 'destroy'])->name('boards.items.bulk.destroy');
            Route::put('boards/{board:slug}/columns/{column}', [BoardController::class, 'updateColumn'])->name('boards.columns.update');
            Route::delete('boards/{board:slug}/columns/{column}', [BoardController::class, 'destroyColumn'])->name('boards.columns.destroy');
            Route::post('boards/{board:slug}/items', [BoardController::class, 'storeItem'])->name('boards.items.store');
            Route::post('boards/{board:slug}/items/{item}/duplicate', [BoardController::class, 'duplicateItem'])->name('boards.items.duplicate');
            Route::get('boards/{board:slug}/items/{item}', [BoardController::class, 'showItem'])->name('boards.items.show');
            Route::get('boards/{board:slug}/items/{item}/details', [BoardController::class, 'itemDetails'])->name('boards.items.details');
            Route::post('boards/{board:slug}/items/{item}/work-logs', [BoardController::class, 'logWork'])->name('boards.items.work-logs.store');
            Route::patch('boards/{board:slug}/items/{item}/move', [BoardController::class, 'moveItem'])->name('boards.items.move');
            Route::patch('boards/{board:slug}/items/{item}', [BoardController::class, 'updateItem'])->name('boards.items.update');
            Route::post('boards/{board:slug}/items/{item}/watch', [BoardController::class, 'toggleWatch'])->name('boards.items.watch');
            Route::patch('boards/{board:slug}/items/{item}/labels', [BoardController::class, 'updateItemLabels'])->name('boards.items.labels');
            Route::put('boards/{board:slug}/items/{item}/fields', [BoardController::class, 'updateItemFields'])->name('boards.items.fields.update');
            Route::post('boards/{board:slug}/items/{item}/links', [BoardController::class, 'storeItemLink'])->name('boards.items.links.store');
            // No Item::links() relation (links are outgoing or incoming); the controller checks the link belongs to the item.
            Route::delete('boards/{board:slug}/items/{item}/links/{link}', [BoardController::class, 'destroyItemLink'])->name('boards.items.links.destroy')->withoutScopedBindings();
            Route::patch('boards/{board:slug}/items/{item}/move-to-board', [BoardController::class, 'moveItemToBoard'])->name('boards.items.move-to-board');
            Route::delete('boards/{board:slug}/items/{item}', [BoardController::class, 'destroyItem'])->name('boards.items.destroy');
            Route::post('boards/{board:slug}/items/{item}/comments', [BoardController::class, 'storeComment'])->name('boards.items.comments.store');
            Route::post('boards/{board:slug}/items/{item}/attachments', [BoardController::class, 'storeAttachment'])->name('boards.items.attachments.store');
            Route::delete('boards/{board:slug}/items/{item}/attachments/{attachment}', [BoardController::class, 'destroyAttachment'])->name('boards.items.attachments.destroy');

            // Meeting notes → issues (BRAI-62): AI proposals, reviewed, then created once
            Route::get('meeting-notes/options', [MeetingNotesController::class, 'options'])->middleware(ReadOnlySession::class)->name('meeting-notes.options');
            Route::post('meeting-notes/extract', [MeetingNotesController::class, 'extract'])->middleware('throttle:10,1,meeting-notes.extract')->name('meeting-notes.extract');
            Route::post('meeting-notes/create', [MeetingNotesController::class, 'store'])->middleware('throttle:30,1,meeting-notes.store')->name('meeting-notes.store');

            // Linked content and backlinks between issues, docs, tickets and plans (BRAI-71)
            Route::get('references', [ContentReferenceController::class, 'index'])->middleware(ReadOnlySession::class)->name('references.index');
            Route::get('references/candidates', [ContentReferenceController::class, 'candidates'])->middleware(ReadOnlySession::class)->name('references.candidates');
            Route::post('references', [ContentReferenceController::class, 'store'])->middleware('throttle:60,1,references.store')->name('references.store');
            Route::delete('references/{contentReference}', [ContentReferenceController::class, 'destroy'])->whereNumber('contentReference')->name('references.destroy');

            // Task Lists (nested under projects)
            Route::post('plans', [PlanController::class, 'store'])->name('plans.store');
            Route::get('releases', [ReleaseController::class, 'index'])->name('releases.index');
            Route::post('releases', [ReleaseController::class, 'store'])->name('releases.store');
            Route::get('releases/{release}', [ReleaseController::class, 'show'])->whereNumber('release')->name('releases.show');
            Route::put('releases/{release}', [ReleaseController::class, 'update'])->whereNumber('release')->name('releases.update');
            Route::delete('releases/{release}', [ReleaseController::class, 'destroy'])->whereNumber('release')->name('releases.destroy');
            Route::post('releases/{release}/release', [ReleaseController::class, 'release'])->whereNumber('release')->name('releases.release');
            Route::post('releases/{release}/status', [ReleaseController::class, 'setStatus'])->whereNumber('release')->name('releases.status');
            Route::post('releases/{release}/items', [ReleaseController::class, 'addItems'])->whereNumber('release')->name('releases.items.add');
            Route::delete('releases/{release}/items/{item}', [ReleaseController::class, 'removeItem'])->whereNumber('release')->name('releases.items.remove');
            Route::get('releases/{release}/candidates', [ReleaseController::class, 'candidates'])->whereNumber('release')->name('releases.candidates');
            Route::get('releases/{release}/notes/draft', [ReleaseController::class, 'draftNotes'])->whereNumber('release')->name('releases.notes.draft');
            Route::put('releases/{release}/notes', [ReleaseController::class, 'saveNotes'])->whereNumber('release')->name('releases.notes.save');
            Route::post('releases/{release}/publish', [ReleaseController::class, 'publish'])->whereNumber('release')->name('releases.publish');
            Route::delete('releases/{release}/publish', [ReleaseController::class, 'unpublish'])->whereNumber('release')->name('releases.unpublish');
            // Project health: live metrics + saved status updates (BRAI-64)
            Route::get('health', [ProjectHealthController::class, 'show'])->name('projects.health');
            Route::post('health/narrative', [ProjectHealthController::class, 'narrative'])->middleware('throttle:10,1,projects.health.narrative')->name('projects.health.narrative');
            Route::post('health/reports', [ProjectHealthController::class, 'store'])->middleware('throttle:30,1,projects.health.reports.store')->name('projects.health.reports.store');
            Route::delete('health/reports/{healthReport}', [ProjectHealthController::class, 'destroy'])->whereNumber('healthReport')->name('projects.health.reports.destroy');
            // Workload planning: capacity, explicit allocations, overload (BRAI-75)
            Route::get('workload', [WorkloadController::class, 'index'])->name('workload.index');
            Route::get('workload/items', [WorkloadController::class, 'items'])->middleware(ReadOnlySession::class)->name('workload.items');
            Route::put('workload/capacity', [WorkloadController::class, 'updateCapacity'])->middleware('throttle:120,1,workload.capacity.update')->name('workload.capacity.update');
            Route::put('workload/overrides', [WorkloadController::class, 'updateOverride'])->middleware('throttle:120,1,workload.overrides.update')->name('workload.overrides.update');
            Route::put('workload/allocations', [WorkloadController::class, 'updateAllocation'])->middleware('throttle:240,1,workload.allocations.update')->name('workload.allocations.update');
            // {allocation} is a plain id (not model-bound), so {project} stays scoped to {workspace}; the controller looks it up inside the project
            Route::delete('workload/allocations/{allocation}', [WorkloadController::class, 'destroyAllocation'])->whereNumber('allocation')->name('workload.allocations.destroy');
            Route::get('plans/{plan:slug}', [PlanController::class, 'show'])->name('plans.show');
            Route::patch('plans/{plan:slug}', [PlanController::class, 'update'])->name('plans.update');
            Route::delete('plans/{plan:slug}', [PlanController::class, 'destroy'])->name('plans.destroy');
            Route::get('plans/{plan:slug}/document', [PlanController::class, 'document'])->name('plans.document');
            Route::put('plans/{plan:slug}/document', [PlanController::class, 'save'])->name('plans.save');
            Route::post('plans/{plan:slug}/duplicate', [PlanController::class, 'duplicate'])->name('plans.duplicate');
            Route::get('plans/{plan:slug}/issues', [PlanController::class, 'issues'])->name('plans.issues');
            Route::post('plans/{plan:slug}/issues', [PlanController::class, 'storeIssues'])->name('plans.issues.store');
            Route::post('plans/{plan:slug}/share', [PlanController::class, 'share'])->name('plans.share');
            Route::delete('plans/{plan:slug}/share', [PlanController::class, 'unshare'])->name('plans.unshare');
            Route::get('plans/{plan:slug}/versions', [PlanController::class, 'versions'])->name('plans.versions.index');
            Route::get('plans/{plan:slug}/versions/{versionNumber}', [PlanController::class, 'version'])->whereNumber('versionNumber')->name('plans.versions.show');
            Route::post('plans/{plan:slug}/versions/{versionNumber}/restore', [PlanController::class, 'restore'])->whereNumber('versionNumber')->name('plans.versions.restore');
            Route::post('task-lists', [TaskListController::class, 'store'])->name('task-lists.store');
            Route::get('task-lists/{taskList:slug}', [TaskListController::class, 'show'])->name('task-lists.show');
            Route::put('task-lists/{taskList:slug}', [TaskListController::class, 'update'])->name('task-lists.update');
            Route::delete('task-lists/{taskList:slug}', [TaskListController::class, 'destroy'])->name('task-lists.destroy');
            Route::post('task-lists/{taskList:slug}/watch', [TaskListController::class, 'toggleWatch'])->name('task-lists.watch');
            Route::post('task-lists/{taskList:slug}/items', [TaskListController::class, 'storeItem'])->name('task-lists.items.store');
            Route::post('task-lists/{taskList:slug}/items/reorder', [TaskListController::class, 'reorderItems'])->name('task-lists.items.reorder');
            Route::patch('task-lists/{taskList:slug}/items/{item}', [TaskListController::class, 'updateItem'])->name('task-lists.items.update');
            Route::delete('task-lists/{taskList:slug}/items/{item}', [TaskListController::class, 'destroyItem'])->name('task-lists.items.destroy');
            Route::post('task-lists/{taskList:slug}/items/{item}/duplicate', [TaskListController::class, 'duplicateItem'])->name('task-lists.items.duplicate');

            // Lead forms (BRAI-93), each with its own leads board
            Route::post('lead-forms', [LeadFormController::class, 'store'])->name('lead-forms.store');
            Route::get('lead-forms/{leadForm:slug}', [LeadFormController::class, 'show'])->name('lead-forms.show');
            Route::put('lead-forms/{leadForm:slug}', [LeadFormController::class, 'update'])->name('lead-forms.update');
            Route::delete('lead-forms/{leadForm:slug}', [LeadFormController::class, 'destroy'])->name('lead-forms.destroy');
            Route::post('lead-forms/{leadForm:slug}/publish', [LeadFormController::class, 'publish'])->name('lead-forms.publish');
            Route::delete('lead-forms/{leadForm:slug}/publish', [LeadFormController::class, 'unpublish'])->name('lead-forms.unpublish');
            Route::post('lead-forms/{leadForm:slug}/assets', [LeadFormController::class, 'uploadAsset'])->middleware('throttle:30,1,lead-forms.assets')->name('lead-forms.assets');
            Route::post('lead-forms/{leadForm:slug}/inbound-token', [LeadFormController::class, 'rotateInboundToken'])->name('lead-forms.inbound-token');
            Route::delete('lead-forms/{leadForm:slug}/inbound-token', [LeadFormController::class, 'revokeInboundToken'])->name('lead-forms.inbound-token.revoke');
            Route::get('lead-forms/{leadForm:slug}/analytics', [LeadFormController::class, 'analytics'])->name('lead-forms.analytics');
            Route::post('lead-forms/{leadForm:slug}/columns', [LeadFormController::class, 'storeColumn'])->name('lead-forms.columns.store');
            Route::post('lead-forms/{leadForm:slug}/columns/reorder', [LeadFormController::class, 'reorderColumns'])->name('lead-forms.columns.reorder');
            Route::put('lead-forms/{leadForm:slug}/columns/{column}', [LeadFormController::class, 'updateColumn'])->whereNumber('column')->name('lead-forms.columns.update');
            Route::delete('lead-forms/{leadForm:slug}/columns/{column}', [LeadFormController::class, 'destroyColumn'])->whereNumber('column')->name('lead-forms.columns.destroy');
            Route::patch('lead-forms/{leadForm:slug}/leads/{lead}/move', [LeadController::class, 'move'])->whereNumber('lead')->name('leads.move');
            Route::patch('lead-forms/{leadForm:slug}/leads/{lead}', [LeadController::class, 'update'])->whereNumber('lead')->name('leads.update');
            Route::delete('lead-forms/{leadForm:slug}/leads/{lead}', [LeadController::class, 'destroy'])->whereNumber('lead')->name('leads.destroy');

            // Helpdesks (nested under projects)
            Route::post('helpdesks', [HelpdeskController::class, 'store'])->name('helpdesks.store');
            Route::get('helpdesks/{helpdesk:slug}', [HelpdeskController::class, 'show'])->name('helpdesks.show');
            Route::get('helpdesks/{helpdesk:slug}/edit', [HelpdeskController::class, 'edit'])->name('helpdesks.edit');
            Route::put('helpdesks/{helpdesk:slug}', [HelpdeskController::class, 'update'])->name('helpdesks.update');
            Route::delete('helpdesks/{helpdesk:slug}', [HelpdeskController::class, 'destroy'])->name('helpdesks.destroy');
            Route::post('helpdesks/{helpdesk:slug}/columns', [HelpdeskController::class, 'storeColumn'])->name('helpdesks.columns.store');
            Route::put('helpdesks/{helpdesk:slug}/columns/{column}', [HelpdeskController::class, 'updateColumn'])->name('helpdesks.columns.update');
            Route::post('helpdesks/{helpdesk:slug}/columns/reorder', [HelpdeskController::class, 'reorderColumns'])->name('helpdesks.columns.reorder');
            Route::delete('helpdesks/{helpdesk:slug}/columns/{column}', [HelpdeskController::class, 'destroyColumn'])->name('helpdesks.columns.destroy');
            Route::post('helpdesks/{helpdesk:slug}/fields', [HelpdeskController::class, 'storeField'])->name('helpdesks.fields.store');
            Route::put('helpdesks/{helpdesk:slug}/fields/{field}', [HelpdeskController::class, 'updateField'])->name('helpdesks.fields.update');
            Route::post('helpdesks/{helpdesk:slug}/fields/reorder', [HelpdeskController::class, 'reorderFields'])->name('helpdesks.fields.reorder');
            Route::delete('helpdesks/{helpdesk:slug}/fields/{field}', [HelpdeskController::class, 'destroyField'])->name('helpdesks.fields.destroy');
            Route::post('helpdesks/{helpdesk:slug}/tickets/reorder', [HelpdeskController::class, 'reorderTickets'])->name('helpdesks.tickets.reorder');
            Route::get('helpdesks/{helpdesk:slug}/tickets/root', [HelpdeskController::class, 'rootTicketsJson'])->name('helpdesks.tickets.root');
            // Settings → Email → forwarding address (BRAI-124)
            Route::get('helpdesks/{helpdesk:slug}/email/forwarding', [HelpdeskForwardingController::class, 'show'])->middleware(ReadOnlySession::class)->name('helpdesks.forwarding.show');
            Route::post('helpdesks/{helpdesk:slug}/email/forwarding/regenerate', [HelpdeskForwardingController::class, 'regenerate'])->middleware('throttle:10,1,helpdesks.forwarding.regenerate')->name('helpdesks.forwarding.regenerate');

            // Tickets (nested under helpdesks)
            Route::post('helpdesks/{helpdesk:slug}/tickets', [TicketController::class, 'store'])->name('tickets.store');
            Route::get('helpdesks/{helpdesk:slug}/tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');
            Route::patch('helpdesks/{helpdesk:slug}/tickets/{ticket}/move', [TicketController::class, 'move'])->name('tickets.move');
            Route::patch('helpdesks/{helpdesk:slug}/tickets/{ticket}', [TicketController::class, 'update'])->name('tickets.update');
            Route::delete('helpdesks/{helpdesk:slug}/tickets/{ticket}', [TicketController::class, 'destroy'])->name('tickets.destroy');
            Route::post('helpdesks/{helpdesk:slug}/tickets/{ticket}/replies', [TicketController::class, 'storeReply'])->name('tickets.replies.store');
            Route::post('helpdesks/{helpdesk:slug}/tickets/{ticket}/attachments', [TicketController::class, 'storeAttachment'])->name('tickets.attachments.store');
            Route::delete('helpdesks/{helpdesk:slug}/tickets/{ticket}/attachments/{attachment}', [TicketController::class, 'destroyAttachment'])->name('tickets.attachments.destroy');
            Route::post('helpdesks/{helpdesk:slug}/tickets/{ticket}/watch', [TicketController::class, 'toggleWatch'])->name('tickets.watch');
            Route::patch('helpdesks/{helpdesk:slug}/tickets/{ticket}/fields', [TicketController::class, 'updateFieldValues'])->name('tickets.fields.update');

            // Email → helpdesk: connected IMAP mailbox (v1.40.0, BRAI-79)
            Route::get('helpdesks/{helpdesk:slug}/mailbox', [HelpdeskMailboxController::class, 'show'])->name('helpdesks.mailbox.show');
            Route::put('helpdesks/{helpdesk:slug}/mailbox', [HelpdeskMailboxController::class, 'update'])->name('helpdesks.mailbox.update');
            Route::delete('helpdesks/{helpdesk:slug}/mailbox', [HelpdeskMailboxController::class, 'destroy'])->name('helpdesks.mailbox.destroy');
            Route::post('helpdesks/{helpdesk:slug}/mailbox/test', [HelpdeskMailboxController::class, 'test'])->middleware('throttle:10,1,helpdesks.mailbox.test')->name('helpdesks.mailbox.test');
            Route::post('helpdesks/{helpdesk:slug}/tickets/{ticket}/replies/{reply}/resend-email', [HelpdeskMailboxController::class, 'resendReply'])->middleware('throttle:10,1,tickets.replies.resend-email')->name('tickets.replies.resend-email');
            if (app()->environment('e2e')) {
                Route::post('helpdesks/{helpdesk:slug}/mailbox/e2e-receive', [HelpdeskMailboxController::class, 'e2eReceive'])->name('helpdesks.mailbox.e2e-receive');
            }

            // Pages (nested under projects)
            Route::post('pages', [PageController::class, 'store'])->name('pages.store');
            Route::post('pages/{page}/duplicate', [PageController::class, 'duplicate'])->name('pages.duplicate');
            Route::get('pages/tree', [PageController::class, 'tree'])->name('pages.tree');
            Route::post('pages/reorder', [PageController::class, 'reorder'])->name('pages.reorder');
            Route::get('pages/{page}', [PageController::class, 'show'])->name('pages.show');
            Route::get('pages/{page}/excalidraw', [PageController::class, 'excalidrawEditor'])->name('pages.excalidraw');
            Route::post('pages/{page}/excalidraw/save', [PageController::class, 'saveExcalidraw'])->name('pages.excalidraw.save');
            Route::post('pages/{page}/excalidraw-embed/save', [PageController::class, 'saveExcalidrawEmbed'])->name('pages.excalidraw.embed.save');
            Route::get('pages/{page}/whiteboard/issues', [WhiteboardController::class, 'issues'])->name('pages.whiteboard.issues');
            Route::post('pages/{page}/whiteboard/issues', [WhiteboardController::class, 'storeIssues'])->name('pages.whiteboard.issues.store');
            Route::put('pages/{page}', [PageController::class, 'update'])->name('pages.update');
            Route::put('pages/{page}/draft', [PageController::class, 'saveDraft'])->name('pages.draft.save');
            Route::delete('pages/{page}/draft', [PageController::class, 'discardDraft'])->name('pages.draft.discard');
            Route::put('pages/{page}/publish-changes', [PageController::class, 'publishChanges'])->name('pages.publish-changes');
            Route::post('pages/{page}/presence', [PageController::class, 'presence'])->name('pages.presence');
            Route::get('pages/{page}/versions', [PageVersionController::class, 'index'])->name('pages.versions.index');
            Route::get('pages/{page}/versions/{versionNumber}', [PageVersionController::class, 'show'])->whereNumber('versionNumber')->name('pages.versions.show');
            Route::post('pages/{page}/versions/{versionNumber}/restore', [PageVersionController::class, 'restore'])->whereNumber('versionNumber')->name('pages.versions.restore');
            Route::post('pages/{page}/watch', [PageController::class, 'toggleWatch'])->name('pages.watch');
            Route::post('pages/{page}/publish', [PageController::class, 'publish'])->name('pages.publish');
            Route::post('pages/{page}/unpublish', [PageController::class, 'unpublish'])->name('pages.unpublish');
            Route::post('pages/{page}/archive', [PageController::class, 'archive'])->name('pages.archive');
            Route::delete('pages/{page}', [PageController::class, 'destroy'])->name('pages.destroy');
            // Document review (BRAI-80): review a draft snapshot, inline comments, "review required" switch
            Route::get('pages/{page}/reviews/candidates', [PageReviewController::class, 'candidates'])->middleware(ReadOnlySession::class)->name('pages.reviews.candidates');
            Route::post('pages/{page}/reviews', [PageReviewController::class, 'store'])->middleware('throttle:20,1,pages.reviews.store')->name('pages.reviews.store');
            Route::get('pages/{page}/reviews/{review}', [PageReviewController::class, 'show'])->whereUuid('review')->name('pages.reviews.show');
            Route::post('pages/{page}/reviews/{review}/decision', [PageReviewController::class, 'decide'])->whereUuid('review')->middleware('throttle:30,1,pages.reviews.decide')->name('pages.reviews.decide');
            Route::post('pages/{page}/reviews/{review}/cancel', [PageReviewController::class, 'cancel'])->whereUuid('review')->name('pages.reviews.cancel');
            Route::post('pages/{page}/reviews/{review}/comments', [PageReviewController::class, 'storeComment'])->whereUuid('review')->middleware('throttle:60,1,pages.reviews.comments.store')->name('pages.reviews.comments.store');
            Route::patch('pages/{page}/review-threads/{thread}', [PageReviewController::class, 'updateThread'])->whereUuid('thread')->name('pages.reviews.threads.update');
            Route::put('pages/{page}/review-settings', [PageReviewController::class, 'settings'])->name('pages.reviews.settings');
        });
    });
});

Route::middleware('auth')->group(function () {
    // Onboarding state (welcome modal + setup checklist)
    Route::post('/api/onboarding/welcome-seen', [OnboardingController::class, 'markWelcomeSeen'])->name('onboarding.welcome-seen');
    Route::post('/api/onboarding/checklist-dismissed', [OnboardingController::class, 'dismissChecklist'])->name('onboarding.checklist-dismissed');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::patch('/profile/preferences', [ProfileController::class, 'updatePreferences'])->name('profile.preferences');
    // Customisable sidebar (BRAI-106): hidden entries, project groups, pins
    Route::patch('/sidebar/preferences', [SidebarPreferencesController::class, 'update'])->name('sidebar.preferences');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::delete('/profile/connected-apps/{clientId}', [ProfileController::class, 'disconnectApp'])->name('profile.connected-apps.destroy');

    // File uploads
    Route::post('/upload/image', [FileController::class, 'uploadImage'])->name('upload.image');
    Route::post('/upload/document', [FileController::class, 'uploadDocument'])->name('upload.document');

    // Page-level sticky notes (per user, per URL)
    // fetched on every page load: must not write the session (it would race form submits)
    Route::get('/api/page-sticky-notes', [PageStickyNoteController::class, 'index'])->middleware(ReadOnlySession::class)->name('page-sticky-notes.index');
    Route::post('/api/page-sticky-notes', [PageStickyNoteController::class, 'store'])->name('page-sticky-notes.store');
    Route::patch('/api/page-sticky-notes/{note}', [PageStickyNoteController::class, 'update'])->name('page-sticky-notes.update');
    Route::delete('/api/page-sticky-notes/{note}', [PageStickyNoteController::class, 'destroy'])->name('page-sticky-notes.destroy');

    // Notifications (BRAI-102): inbox page + one JSON feed for the bell and the page
    Route::get('/notifications', [NotificationController::class, 'page'])->name('notifications.page');
    // fetched when the bell opens / polled every 30 s: read-only sessions (would race form submits)
    Route::get('/api/notifications', [NotificationController::class, 'index'])->middleware(ReadOnlySession::class)->name('notifications.index');
    Route::get('/api/notifications/unread-count', [NotificationController::class, 'unreadCount'])->middleware(ReadOnlySession::class)->name('notifications.unread-count');
    Route::post('/api/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::post('/api/notifications/bulk', [NotificationController::class, 'bulk'])->name('notifications.bulk');
    Route::patch('/api/notifications/preferences', [NotificationController::class, 'updatePreferences'])->name('notifications.preferences');
    Route::post('/api/notifications/{id}/read', [NotificationController::class, 'markRead'])->whereUuid('id')->name('notifications.read');
    Route::post('/api/notifications/{id}/unread', [NotificationController::class, 'markUnread'])->whereUuid('id')->name('notifications.unread');
    Route::post('/api/notifications/{id}/archive', [NotificationController::class, 'archive'])->whereUuid('id')->name('notifications.archive');
    Route::post('/api/notifications/{id}/unarchive', [NotificationController::class, 'unarchive'])->whereUuid('id')->name('notifications.unarchive');

    // Browser push subscriptions
    Route::post('/api/push-subscriptions', [PushSubscriptionController::class, 'store'])->name('push-subscriptions.store');
    Route::delete('/api/push-subscriptions', [PushSubscriptionController::class, 'destroy'])->name('push-subscriptions.destroy');

    // GitHub App setup callback (BRAI-61): GitHub returns here after install / repository changes
    Route::get('/github/app/setup', [ProjectGithubController::class, 'setup'])->middleware('throttle:20,1,github.app.setup')->name('github.app.setup');
});

// Instance admin console — accounts, workspaces (metadata only) and instance settings
Route::middleware(['auth', 'verified', 'instance.admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', Admin\OverviewController::class)->name('overview');

    Route::get('users', [Admin\UserController::class, 'index'])->name('users.index');
    Route::post('users', [Admin\UserController::class, 'store'])->name('users.store');
    Route::get('users/{user}', [Admin\UserController::class, 'show'])->name('users.show');
    Route::delete('users/{user}', [Admin\UserController::class, 'destroy'])->name('users.destroy');
    Route::post('users/{user}/password-reset-email', [Admin\UserController::class, 'sendPasswordResetEmail'])->name('users.password-reset-email');
    Route::post('users/{user}/password-reset-link', [Admin\UserController::class, 'createPasswordResetLink'])->name('users.password-reset-link');
    Route::post('users/{user}/verify-email', [Admin\UserController::class, 'verifyEmail'])->name('users.verify-email');
    Route::post('users/{user}/resend-verification', [Admin\UserController::class, 'resendVerification'])->name('users.resend-verification');
    Route::post('users/{user}/suspend', [Admin\UserController::class, 'suspend'])->name('users.suspend');
    Route::post('users/{user}/unsuspend', [Admin\UserController::class, 'unsuspend'])->name('users.unsuspend');
    Route::post('users/{user}/admin', [Admin\UserController::class, 'grantAdmin'])->name('users.admin.grant');
    Route::delete('users/{user}/admin', [Admin\UserController::class, 'revokeAdmin'])->name('users.admin.revoke');

    Route::get('workspaces', [Admin\WorkspaceController::class, 'index'])->name('workspaces.index');
    Route::get('workspaces/{managedWorkspace:id}/members', [Admin\WorkspaceController::class, 'members'])->name('workspaces.members');
    Route::post('workspaces/{managedWorkspace:id}/transfer', [Admin\WorkspaceController::class, 'transfer'])->name('workspaces.transfer');
    Route::delete('workspaces/{managedWorkspace:id}', [Admin\WorkspaceController::class, 'destroy'])->name('workspaces.destroy');

    Route::get('settings', [Admin\SettingsController::class, 'edit'])->name('settings.edit');
    Route::put('settings', [Admin\SettingsController::class, 'update'])->name('settings.update');
    Route::post('settings/test-email', [Admin\SettingsController::class, 'testEmail'])->name('settings.test-email');

    // GitHub App for repository connections (BRAI-61): created with GitHub's manifest flow
    Route::get('github-app', [Admin\GithubAppController::class, 'show'])->name('github-app.show');
    Route::post('github-app/manifest', [Admin\GithubAppController::class, 'manifest'])->middleware('throttle:10,1,admin.github-app.manifest')->name('github-app.manifest');
    Route::get('github-app/callback', [Admin\GithubAppController::class, 'callback'])->middleware('throttle:10,1,admin.github-app.callback')->name('github-app.callback');
    Route::delete('github-app', [Admin\GithubAppController::class, 'destroy'])->name('github-app.destroy');

    Route::get('audit-log', Admin\AuditLogController::class)->name('audit-log');
});

require __DIR__.'/auth.php';

// Unknown URLs: a 404 that runs through the web middleware (session + shared props), so
// the error page knows whether you're signed in.
Route::fallback(fn () => abort(404));
