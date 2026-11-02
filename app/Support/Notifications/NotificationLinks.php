<?php

namespace App\Support\Notifications;

use App\Models\Board;
use App\Models\Item;
use App\Models\Page;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Whether a notification's stored link may be handed to an app (App API `url`): only a page of this server,
 * and only while the user can open it now — a notification stays in the inbox after the user lost access, and
 * the app must not get a link the web would refuse. The path is matched against the web routes; the
 * workspace, project, board, issue, doc and ticket in it are checked with the web's own rules
 * (`hasWorkspaceAccess`, `hasProjectAccess`, the `view` policies). Anything that doesn't resolve → null.
 * One instance per request: lookups are remembered.
 */
final class NotificationLinks
{
    /** @var array<string, string|null> */
    private array $seen = [];

    public function __construct(private readonly User $user) {}

    /** The absolute URL to open, or null. */
    public function visible(?string $link): ?string
    {
        if ($link === null || trim($link) === '' || $link === '#') {
            return null;
        }

        return $this->seen[$link] ??= $this->resolve($link);
    }

    private function resolve(string $link): ?string
    {
        $parts = parse_url(trim($link));
        if ($parts === false || ! isset($parts['path']) || ! str_starts_with($parts['path'], '/')) {
            return null;
        }
        if (isset($parts['host']) && ! $this->isThisServer($parts)) {
            return null;
        }
        if (! isset($parts['host']) && (isset($parts['scheme']) || str_starts_with($parts['path'], '//'))) {
            return null;
        }
        $path = $parts['path'];
        if (str_starts_with($path, '/api/')) {
            return null;
        }

        $route = $this->route($path);
        if ($route === null || ! $this->canOpen($route)) {
            return null;
        }

        return url($path).(isset($parts['query']) ? '?'.$parts['query'] : '').(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }

    /** @param  array<string, mixed>  $parts */
    private function isThisServer(array $parts): bool
    {
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $host = strtolower((string) $parts['host']);
        $known = array_filter([
            parse_url((string) config('app.url'), PHP_URL_HOST),
            request()->getHost(),
        ]);

        return in_array($host, array_map('strtolower', $known), true);
    }

    private function route(string $path): ?Route
    {
        try {
            $route = app('router')->getRoutes()->match(Request::create($path, 'GET'));
        } catch (HttpException) {
            return null;
        }

        return $route->isFallback ? null : $route;
    }

    /** Every part of the page the path names must be one the user can open. */
    private function canOpen(Route $route): bool
    {
        $params = $route->parameters();
        $workspace = null;
        $project = null;
        $board = null;

        if (isset($params['workspace'])) {
            $workspace = Workspace::where('slug', (string) $params['workspace'])->first();
            if (! $workspace || ! $this->user->hasWorkspaceAccess($workspace)) {
                return false;
            }
        }
        if (isset($params['project'])) {
            $project = $workspace ? Project::where('workspace_id', $workspace->id)->where('slug', (string) $params['project'])->first() : null;
            if (! $project || ! $this->user->hasProjectAccess($project)) {
                return false;
            }
        }
        if (isset($params['board'])) {
            $board = $project ? Board::where('project_id', $project->id)->where('slug', (string) $params['board'])->first() : null;
            if (! $board || $this->user->cannot('view', $board)) {
                return false;
            }
        }
        if (isset($params['item'])) {
            $item = ctype_digit((string) $params['item']) ? Item::with('board.project')->find((int) $params['item']) : null;
            // a trashed board / project counts as gone (and must never reach a policy with null)
            if (! $item?->board?->project || ($board && $item->board_id !== $board->id) || $this->user->cannot('view', $item)) {
                return false;
            }
        }
        if (isset($params['page'])) {
            $page = Str::isUuid((string) $params['page']) ? Page::where('uuid', (string) $params['page'])->first() : null;
            if (! $page?->project || ($project && $page->project_id !== $project->id) || $this->user->cannot('view', $page)) {
                return false;
            }
        }
        if (isset($params['ticket'])) {
            $ticket = ctype_digit((string) $params['ticket']) ? Ticket::with('helpdesk.project')->find((int) $params['ticket']) : null;
            if (! $ticket?->helpdesk?->project || $this->user->cannot('view', $ticket)) {
                return false;
            }
        }

        return true;
    }
}
