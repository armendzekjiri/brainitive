<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\MentionNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    /**
     * Show the full notifications page (Inertia).
     */
    public function page(Request $request): Response
    {
        $notifications = $request->user()
            ->notifications()
            ->latest()
            ->paginate(50);

        // Mark all as read when the page is opened
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return Inertia::render('Notifications/Index', [
            'notifications' => $notifications,
        ]);
    }
    /**
     * Return paginated notifications for the current user.
     */
    public function index(Request $request): JsonResponse
    {
        $notifications = $request->user()
            ->notifications()
            ->latest()
            ->paginate(20);

        return response()->json($notifications);
    }

    /**
     * Return the unread notification count for the current user.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $count = $request->user()->unreadNotifications()->count();

        return response()->json(['count' => $count]);
    }

    /**
     * Mark a single notification as read.
     */
    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        return response()->json(['success' => true]);
    }

    /**
     * Mark all notifications as read.
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['success' => true]);
    }

    /**
     * Dispatch a MentionNotification immediately when a user is selected from the mention menu.
     * Called from the frontend at the exact moment the user inserts a mention — not on save.
     */
    public function notifyMention(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id'       => 'required|exists:users,id',
            'context_type'  => 'required|string|in:page,item,comment',
            'context_title' => 'required|string|max:500',
            'context_url'   => 'required|string|max:2000',
        ]);

        $actor = $request->user();
        $mentionedUser = User::findOrFail($validated['user_id']);

        // Don't notify yourself
        if ($mentionedUser->id === $actor->id) {
            return response()->json(['success' => true, 'skipped' => 'self']);
        }

        $mentionedUser->notify(new MentionNotification(
            contextType:  $validated['context_type'],
            contextTitle: $validated['context_title'],
            contextUrl:   $validated['context_url'],
            actor:        $actor,
        ));

        return response()->json(['success' => true]);
    }
}
