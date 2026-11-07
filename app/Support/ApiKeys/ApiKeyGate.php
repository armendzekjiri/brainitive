<?php

namespace App\Support\ApiKeys;

use App\Models\Bookmark;
use App\Models\BookmarkItem;
use App\Models\Snippet;
use App\Models\SnippetCollection;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;

/**
 * Deny-only `Gate::before` for personal API keys (Developer API, v1.52.0; registered in AppApiServiceProvider).
 * While a request runs on a key, every policy check of the key's user on a known model (or model class) is
 * refused when the model is outside the key's scope, or its area / level isn't granted: `view*` needs read,
 * `delete*` / `forceDelete` the delete flag, anything else write. It never grants — it returns false or null —
 * so the policies keep deciding as the user. This covers ids that arrive in bodies and queries (move to another
 * board, link issues, convert a capture, start a timer on an issue …) and resources the controllers resolve
 * themselves. Other users' checks in the same request are untouched (ApiKeyAccess::forUser).
 */
final class ApiKeyGate
{
    /** Workspace-wide classes: a Workspace argument next to them needs the whole workspace. */
    private const WORKSPACE_WIDE = [SnippetCollection::class, Snippet::class, Bookmark::class, BookmarkItem::class];

    /** @param  array<int, mixed>  $arguments */
    public static function before(mixed $user, string $ability, array $arguments): ?bool
    {
        if (! $user instanceof User || ($access = ApiKeyAccess::forUser($user)) === null) {
            return null;
        }

        return self::allows($access, $ability, $arguments) ? null : false;
    }

    /** @param  array<int, mixed>  $arguments */
    public static function allows(ApiKeyAccess $access, string $ability, array $arguments): bool
    {
        $level = self::level($ability);
        $wide = false;
        foreach ($arguments as $argument) {
            if (is_string($argument) && in_array($argument, self::WORKSPACE_WIDE, true)) {
                $wide = true;
            }
        }

        foreach ($arguments as $argument) {
            $class = $argument instanceof Model ? $argument::class : (is_string($argument) && class_exists($argument) ? $argument : null);
            if ($class === null || ! ApiKeyLocator::knows($class)) {
                continue;
            }

            $area = ApiKeyLocator::areaOf($class);
            if ($area !== null && ! $access->can($area, $level === 'delete' ? ApiKeyAreas::WRITE : $level)) {
                return false;
            }
            if ($level === 'delete' && $area !== null && ! $access->canDelete()) {
                return false;
            }

            if ($argument instanceof Model && $argument->exists) {
                $location = ApiKeyLocator::ofModel($argument);
                if ($location !== null && $argument instanceof Workspace && ($wide || in_array($class, self::WORKSPACE_WIDE, true))) {
                    $location = new ApiKeyLocation($location->workspaceId, workspaceWide: true);
                }
                if ($location !== null && ! $access->allows($location)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** read | write | delete of a policy ability. */
    private static function level(string $ability): string
    {
        return match (true) {
            str_starts_with($ability, 'view') => ApiKeyAreas::READ,
            str_starts_with($ability, 'delete'), $ability === 'forceDelete', $ability === 'destroy' => 'delete',
            default => ApiKeyAreas::WRITE,
        };
    }
}
