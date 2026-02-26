<?php

use App\Http\Controllers\AiController;
use App\Http\Controllers\BoardController;
use App\Http\Controllers\BookmarkController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\MyWorkController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PageStickyNoteController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SnippetController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
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
Route::get('/share/snippet-collections/{uuid}', [SnippetController::class, 'showPublicCollection'])->name('snippet-collections.public.show');

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

    // Workspaces
    Route::resource('workspaces', WorkspaceController::class);

    // Workspace member management
    Route::post('workspaces/{workspace}/members', [WorkspaceController::class, 'inviteMember'])->name('workspaces.members.invite');
    Route::patch('workspaces/{workspace}/members/{user}', [WorkspaceController::class, 'updateMemberRole'])->name('workspaces.members.update');
    Route::delete('workspaces/{workspace}/members/{user}', [WorkspaceController::class, 'removeMember'])->name('workspaces.members.remove');
    Route::delete('workspaces/{workspace}/invitations/{invitation}', [WorkspaceController::class, 'cancelInvitation'])->name('workspaces.invitations.destroy');

    // Workspace-scoped routes
    Route::prefix('workspaces/{workspace}')->group(function () {
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

        // Labels (nested under projects)
        Route::prefix('projects/{project:slug}')->group(function () {
            Route::post('labels', [ProjectController::class, 'storeLabel'])->name('projects.labels.store');
            Route::put('labels/{label}', [ProjectController::class, 'updateLabel'])->name('projects.labels.update');
            Route::delete('labels/{label}', [ProjectController::class, 'destroyLabel'])->name('projects.labels.destroy');
        });

        // Boards & Pages (nested under projects) - scoped to project
        Route::prefix('projects/{project}')->scopeBindings()->group(function () {
            Route::post('boards', [BoardController::class, 'store'])->name('boards.store');
            Route::get('boards/{board:slug}', [BoardController::class, 'show'])->name('boards.show');
            Route::get('boards/{board:slug}/edit', [BoardController::class, 'edit'])->name('boards.edit');
            Route::put('boards/{board:slug}', [BoardController::class, 'update'])->name('boards.update');
            Route::delete('boards/{board:slug}', [BoardController::class, 'destroy'])->name('boards.destroy');
            Route::post('boards/{board:slug}/columns', [BoardController::class, 'storeColumn'])->name('boards.columns.store');
            Route::post('boards/{board:slug}/columns/reorder', [BoardController::class, 'reorderColumns'])->name('boards.columns.reorder');
            Route::put('boards/{board:slug}/columns/{column}', [BoardController::class, 'updateColumn'])->name('boards.columns.update');
            Route::delete('boards/{board:slug}/columns/{column}', [BoardController::class, 'destroyColumn'])->name('boards.columns.destroy');
            Route::post('boards/{board:slug}/items', [BoardController::class, 'storeItem'])->name('boards.items.store');
            Route::get('boards/{board:slug}/items/{item}', [BoardController::class, 'showItem'])->name('boards.items.show');
            Route::patch('boards/{board:slug}/items/{item}/move', [BoardController::class, 'moveItem'])->name('boards.items.move');
            Route::patch('boards/{board:slug}/items/{item}', [BoardController::class, 'updateItem'])->name('boards.items.update');
            Route::patch('boards/{board:slug}/items/{item}/labels', [BoardController::class, 'updateItemLabels'])->name('boards.items.labels');
            Route::post('boards/{board:slug}/items/{item}/links', [BoardController::class, 'storeItemLink'])->name('boards.items.links.store');
            Route::delete('boards/{board:slug}/items/{item}/links/{link}', [BoardController::class, 'destroyItemLink'])->name('boards.items.links.destroy');
            Route::patch('boards/{board:slug}/items/{item}/move-to-board', [BoardController::class, 'moveItemToBoard'])->name('boards.items.move-to-board');
            Route::delete('boards/{board:slug}/items/{item}', [BoardController::class, 'destroyItem'])->name('boards.items.destroy');
            Route::post('boards/{board:slug}/items/{item}/comments', [BoardController::class, 'storeComment'])->name('boards.items.comments.store');
            Route::post('boards/{board:slug}/items/{item}/attachments', [BoardController::class, 'storeAttachment'])->name('boards.items.attachments.store');
            Route::delete('boards/{board:slug}/items/{item}/attachments/{attachment}', [BoardController::class, 'destroyAttachment'])->name('boards.items.attachments.destroy');

            // Pages (nested under projects)
            Route::post('pages', [PageController::class, 'store'])->name('pages.store');
            Route::get('pages/tree', [PageController::class, 'tree'])->name('pages.tree');
            Route::post('pages/reorder', [PageController::class, 'reorder'])->name('pages.reorder');
            Route::get('pages/{page}', [PageController::class, 'show'])->name('pages.show');
            Route::get('pages/{page}/excalidraw', [PageController::class, 'excalidrawEditor'])->name('pages.excalidraw');
            Route::post('pages/{page}/excalidraw/save', [PageController::class, 'saveExcalidraw'])->name('pages.excalidraw.save');
            Route::post('pages/{page}/excalidraw-embed/save', [PageController::class, 'saveExcalidrawEmbed'])->name('pages.excalidraw.embed.save');
            Route::put('pages/{page}', [PageController::class, 'update'])->name('pages.update');
            Route::post('pages/{page}/publish', [PageController::class, 'publish'])->name('pages.publish');
            Route::post('pages/{page}/unpublish', [PageController::class, 'unpublish'])->name('pages.unpublish');
            Route::post('pages/{page}/archive', [PageController::class, 'archive'])->name('pages.archive');
            Route::delete('pages/{page}', [PageController::class, 'destroy'])->name('pages.destroy');
        });
    });
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // File uploads
    Route::post('/upload/image', [App\Http\Controllers\FileController::class, 'uploadImage'])->name('upload.image');
    Route::post('/upload/document', [App\Http\Controllers\FileController::class, 'uploadDocument'])->name('upload.document');

    // Page-level sticky notes (per user, per URL)
    Route::get('/api/page-sticky-notes', [PageStickyNoteController::class, 'index'])->name('page-sticky-notes.index');
    Route::post('/api/page-sticky-notes', [PageStickyNoteController::class, 'store'])->name('page-sticky-notes.store');
    Route::patch('/api/page-sticky-notes/{note}', [PageStickyNoteController::class, 'update'])->name('page-sticky-notes.update');
    Route::delete('/api/page-sticky-notes/{note}', [PageStickyNoteController::class, 'destroy'])->name('page-sticky-notes.destroy');
});

require __DIR__.'/auth.php';
