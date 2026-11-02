<?php

namespace App\Support\Entitlements;

use App\Models\Workspace;
use App\Notifications\PlanLimitReachedNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Public submissions (helpdesk widget tickets and replies, lead forms, feedback portals) when the workspace is at
 * its content limit (Codex web review B): the visitor gets a friendly message the embedding page can show — never
 * the plan, usage or a 500 — and the workspace admins get one in-app notification per workspace and day.
 */
final class PublicContentRoom
{
    public const MESSAGE = 'This form can’t accept submissions right now.';

    /** null when there is room; else the response to send (422 with `message`, like a validation error). */
    public static function refusal(Workspace $workspace, string $source): ?JsonResponse
    {
        try {
            app(LimitGate::class)->ensureContentRoom($workspace);

            return null;
        } catch (LimitReached) {
            self::tellAdmins($workspace, $source);

            return response()->json(['message' => self::MESSAGE, 'errors' => ['form' => [self::MESSAGE]], 'limit_reached' => true], 422);
        }
    }

    private static function tellAdmins(Workspace $workspace, string $source): void
    {
        if (! Cache::add('plan-limit-public:'.$workspace->id.':'.now()->toDateString(), true, now()->addDay())) {
            return;
        }
        try {
            foreach ($workspace->admins()->get() as $admin) {
                $admin->notify(new PlanLimitReachedNotification($workspace, $source));
            }
        } catch (Throwable $e) {
            report($e); // the visitor's answer never depends on the notification
        }
    }
}
