<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\AiController;
use App\Http\Controllers\BoardController;
use App\Http\Controllers\BookmarkController;
use App\Http\Controllers\CalendarConnectionController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\HelpdeskController;
use App\Http\Controllers\HelpdeskWidgetController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\MyWorkController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PageStickyNoteController;
use App\Http\Controllers\PageVersionController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\PublicPlanController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SnippetController;
use App\Http\Controllers\SprintController;
use App\Http\Controllers\TaskListController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\WhiteboardController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'canLoginWithGoogle' => (bool) config('services.google.client_id'),
        'canLoginWithGitHub' => (bool) config('services.github.client_id'),
    ]);
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
Route::get('/share/snippet-collections/{uuid}', [SnippetController::class, 'showPublicCollection'])->name('snippet-collections.public.show');

// Embed test page (same-origin; use this URL to verify iframe works)
Route::get('/embed-test/{helpdesk:uuid}', [HelpdeskWidgetController::class, 'embedTestPage'])->name('helpdesks.embed-test');

// Public helpdesk widget and portal (no auth, CORS for embedding on external sites)
Route::middleware('cors')->group(function () {
    Route::get('/widget/helpdesks/{helpdesk:uuid}', [HelpdeskWidgetController::class, 'showWidget'])->name('helpdesks.widget.show');
    Route::get('/widget/helpdesks/{helpdesk:uuid}/form', [HelpdeskWidgetController::class, 'formConfig'])->name('helpdesks.widget.form');
    Route::get('/widget/helpdesks/{helpdesk:uuid}/embed', [HelpdeskWidgetController::class, 'embed'])->name('helpdesks.widget.embed');
    Route::post('/widget/helpdesks/{helpdesk:uuid}/tickets', [TicketController::class, 'storePublic'])->name('helpdesks.tickets.store-public');
    Route::get('/widget/tickets/{ticket:uuid}', [TicketController::class, 'getPublicTicketJson'])->name('helpdesks.tickets.get-public');
    Route::post('/widget/helpdesks/{helpdesk:uuid}/tickets/{ticket:uuid}/replies', [TicketController::class, 'storePublicReply'])->name('helpdesks.tickets.replies.store-public');
    Route::post('/widget/helpdesks/{helpdesk:uuid}/tickets/{ticket:uuid}/attachments', [TicketController::class, 'storePublicAttachment'])->name('helpdesks.tickets.attachments.store-public');
    Route::get('/portal/tickets/{ticket:uuid}', [TicketController::class, 'showPublic'])->name('helpdesks.tickets.show-public');
    Route::get('/support/{publicSlug}', [HelpdeskWidgetController::class, 'supportPage'])->where('publicSlug', '[a-z0-9-]+')->name('helpdesks.support-page');
    Route::get('/portal/tickets/{ticket:uuid}/replies', [TicketController::class, 'publicReplies'])->name('helpdesks.tickets.replies.public');
});

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

    // My Work — assigned items across all workspaces
    Route::get('/my-work', [MyWorkController::class, 'index'])->name('my-work.index');

    // Calendar — personal calendar across all workspaces
    Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar.index');
    Route::patch('/calendar/view', [CalendarController::class, 'updateView'])->name('calendar.view.update');
    Route::patch('/calendar/reschedule', [CalendarController::class, 'reschedule'])->name('calendar.reschedule');

    // Calendar connections (external providers: Google, etc.)
    Route::prefix('calendar/connections')->controller(CalendarConnectionController::class)->group(function () {
        Route::get('google/redirect', 'redirectToGoogle')->name('calendar.connections.google.redirect');
        Route::get('google/callback', 'googleCallback')->name('calendar.connections.google.callback');
        Route::get('pending', 'pendingPicker')->name('calendar.connections.pending');
        Route::post('/', 'store')->name('calendar.connections.store');
        Route::patch('{connection}/toggle', 'toggle')->name('calendar.connections.toggle');
        Route::delete('{connection}', 'destroy')->name('calendar.connections.destroy');
        Route::post('{connection}/events', 'createEvent')->name('calendar.connections.events.create');
        Route::get('{connection}/events/{eventId}', 'showEvent')->name('calendar.connections.events.show')->where('eventId', '[A-Za-z0-9_-]+');
        Route::patch('{connection}/events/{eventId}', 'updateEvent')->name('calendar.connections.events.update')->where('eventId', '[A-Za-z0-9_-]+');
        Route::delete('{connection}/events/{eventId}', 'deleteEvent')->name('calendar.connections.events.destroy')->where('eventId', '[A-Za-z0-9_-]+');

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
        Route::get('users', [WorkspaceController::class, 'users'])->name('workspaces.users.index');
        Route::get('members/{user}', [WorkspaceController::class, 'memberProfile'])->name('workspaces.members.profile');

        // AI settings & generation
        Route::get('ai/settings', [AiController::class, 'getSettings'])->name('ai.settings');
        Route::post('ai/settings', [AiController::class, 'saveSettings'])->name('ai.settings.save');
        Route::post('ai/models', [AiController::class, 'fetchModels'])->name('ai.models');
        Route::post('ai/generate', [AiController::class, 'generate'])->name('ai.generate');

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
        });

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
            Route::post('boards/{board:slug}/columns/reorder', [BoardController::class, 'reorderColumns'])->name('boards.columns.reorder');
            Route::post('boards/{board:slug}/items/reorder', [BoardController::class, 'reorderItems'])->name('boards.items.reorder');
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
            Route::post('boards/{board:slug}/items/{item}/links', [BoardController::class, 'storeItemLink'])->name('boards.items.links.store');
            // No Item::links() relation (links are outgoing or incoming); the controller checks the link belongs to the item.
            Route::delete('boards/{board:slug}/items/{item}/links/{link}', [BoardController::class, 'destroyItemLink'])->name('boards.items.links.destroy')->withoutScopedBindings();
            Route::patch('boards/{board:slug}/items/{item}/move-to-board', [BoardController::class, 'moveItemToBoard'])->name('boards.items.move-to-board');
            Route::delete('boards/{board:slug}/items/{item}', [BoardController::class, 'destroyItem'])->name('boards.items.destroy');
            Route::post('boards/{board:slug}/items/{item}/comments', [BoardController::class, 'storeComment'])->name('boards.items.comments.store');
            Route::post('boards/{board:slug}/items/{item}/attachments', [BoardController::class, 'storeAttachment'])->name('boards.items.attachments.store');
            Route::delete('boards/{board:slug}/items/{item}/attachments/{attachment}', [BoardController::class, 'destroyAttachment'])->name('boards.items.attachments.destroy');

            // Task Lists (nested under projects)
            Route::post('plans', [PlanController::class, 'store'])->name('plans.store');
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
        });
    });
});

Route::middleware('auth')->group(function () {
    // Onboarding state (welcome modal + setup checklist)
    Route::post('/api/onboarding/welcome-seen', [OnboardingController::class, 'markWelcomeSeen'])->name('onboarding.welcome-seen');
    Route::post('/api/onboarding/checklist-dismissed', [OnboardingController::class, 'dismissChecklist'])->name('onboarding.checklist-dismissed');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // File uploads
    Route::post('/upload/image', [FileController::class, 'uploadImage'])->name('upload.image');
    Route::post('/upload/document', [FileController::class, 'uploadDocument'])->name('upload.document');

    // Page-level sticky notes (per user, per URL)
    Route::get('/api/page-sticky-notes', [PageStickyNoteController::class, 'index'])->name('page-sticky-notes.index');
    Route::post('/api/page-sticky-notes', [PageStickyNoteController::class, 'store'])->name('page-sticky-notes.store');
    Route::patch('/api/page-sticky-notes/{note}', [PageStickyNoteController::class, 'update'])->name('page-sticky-notes.update');
    Route::delete('/api/page-sticky-notes/{note}', [PageStickyNoteController::class, 'destroy'])->name('page-sticky-notes.destroy');

    // Notifications
    Route::get('/notifications', [NotificationController::class, 'page'])->name('notifications.page');
    Route::get('/api/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/api/notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('notifications.unread-count');
    Route::post('/api/notifications/{id}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::post('/api/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::post('/api/notifications/notify-mention', [NotificationController::class, 'notifyMention'])->name('notifications.notify-mention');

    // Browser push subscriptions
    Route::post('/api/push-subscriptions', [PushSubscriptionController::class, 'store'])->name('push-subscriptions.store');
    Route::delete('/api/push-subscriptions', [PushSubscriptionController::class, 'destroy'])->name('push-subscriptions.destroy');
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

    Route::get('audit-log', Admin\AuditLogController::class)->name('audit-log');
});

require __DIR__.'/auth.php';
