<?php

namespace App\Http\Middleware;

use App\Models\Board;
use App\Models\Helpdesk;
use App\Models\Project;
use App\Models\Workspace;
use App\Support\Entitlements\LimitGate;
use App\Support\Entitlements\LimitReached;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cloud plans (v1.46.0): an upload needs room in the workspace's file storage. Adds up the request's
 * uploaded files and asks LimitGate before the controller stores anything; requests without files
 * pass straight through, so whole route groups can carry it (`file.room`).
 *
 * The workspace comes from the route (workspace, project, board, helpdesk). The editor's
 * `/upload/*` endpoints have none: there it is the `workspace` field (slug) or the workspace page
 * the upload was sent from, and only one the user belongs to.
 *
 * People outside the workspace (public helpdesk widget and support page, non-members) get a plain
 * refusal, without the plan, usage or billing link.
 */
class EnsureFileRoom
{
    public function handle(Request $request, Closure $next): Response
    {
        $bytes = self::bytes($request->allFiles());
        if ($bytes > 0 && ($workspace = $this->workspace($request))) {
            try {
                app(LimitGate::class)->ensureCanStoreFiles($workspace, $bytes);
            } catch (LimitReached $e) {
                if (! ($request->user() && $workspace->hasUser($request->user()))) {
                    return $this->refuse($request);
                }

                throw $e;
            }
        }

        return $next($request);
    }

    /** @param  array<mixed>  $files  Request::allFiles(): files and (nested) arrays of files */
    private static function bytes(array $files): int
    {
        $total = 0;
        array_walk_recursive($files, function ($file) use (&$total) {
            if ($file instanceof UploadedFile) {
                $total += (int) $file->getSize();
            }
        });

        return $total;
    }

    private function workspace(Request $request): ?Workspace
    {
        $route = $request->route();
        $bound = $route?->parameter('workspace');
        if ($bound instanceof Workspace) {
            return $bound;
        }
        if (is_string($bound) && $bound !== '') {
            return Workspace::where('slug', $bound)->first();
        }

        foreach (['helpdesk', 'board', 'project'] as $name) {
            $model = $route?->parameter($name);
            $workspace = match (true) {
                $model instanceof Helpdesk, $model instanceof Board => $model->project?->workspace,
                $model instanceof Project => $model->workspace,
                default => null,
            };
            if ($workspace) {
                return $workspace;
            }
        }

        return $this->workspaceOfUploader($request);
    }

    /** `/upload/*`: the `workspace` field, else the `/workspaces/{slug}/…` page it came from. */
    private function workspaceOfUploader(Request $request): ?Workspace
    {
        $user = $request->user();
        if (! $user) {
            return null;
        }

        $slug = $request->input('workspace');
        if (! is_string($slug) || $slug === '') {
            $referer = (string) $request->headers->get('referer');
            $sameHost = parse_url($referer, PHP_URL_HOST) === $request->getHost();
            $slug = $sameHost && preg_match('#^/workspaces/([^/?]+)#', (string) parse_url($referer, PHP_URL_PATH), $m) ? rawurldecode($m[1]) : null;
        }
        if ($slug === null) {
            return null;
        }

        $workspace = Workspace::where('slug', $slug)->first();

        return $workspace && $workspace->hasUser($user) ? $workspace : null;
    }

    private function refuse(Request $request): Response
    {
        $message = 'This file can’t be uploaded right now. Please try again later or contact the team.';

        return $request->expectsJson()
            ? response()->json(['message' => $message], 402)
            : back()->withErrors(['file' => $message]);
    }
}
