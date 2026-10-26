<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Models\PageVersion;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Page history: every publish is an immutable version that can be viewed and restored.
 */
class PageVersionController extends Controller
{
    /**
     * List the versions of a page, newest first.
     */
    public function index(Workspace $workspace, Project $project, Page $page): JsonResponse
    {
        $this->authorize('view', $page);

        $versions = $page->versions()
            ->with('author:id,name,avatar')
            ->get(['id', 'page_id', 'version', 'name', 'icon', 'message', 'created_by', 'created_at']);

        return response()->json([
            'current_version' => $page->current_version,
            'versions' => $versions->map(fn (PageVersion $version) => $this->summary($version))->values(),
        ]);
    }

    /**
     * A single version including its content, for the read-only preview.
     */
    public function show(Workspace $workspace, Project $project, Page $page, int $versionNumber): JsonResponse
    {
        $this->authorize('view', $page);

        $version = $this->findVersion($page, $versionNumber);

        return response()->json($this->summary($version) + ['content' => $version->content]);
    }

    /**
     * Make an old version the current one (recorded as a new version on published pages).
     */
    public function restore(Request $request, Workspace $workspace, Project $project, Page $page, int $versionNumber): RedirectResponse
    {
        $this->authorize('update', $page);

        $version = $this->findVersion($page, $versionNumber);

        // BRAI-80: restoring on a published page publishes a new version without a review
        if ($page->isPublished() && $page->review_required) {
            return back()->withErrors(['review' => 'This page needs an approved review before publishing, so versions can\'t be restored directly. Copy the text into the draft and request a review.']);
        }

        $restored = $page->restoreVersion($version, $request->user());

        return back()->with('success', $restored
            ? "Version {$version->version} restored as version {$restored->version}."
            : "Version {$version->version} restored.");
    }

    private function findVersion(Page $page, int $versionNumber): PageVersion
    {
        return $page->versions()->where('version', $versionNumber)->firstOrFail();
    }

    /**
     * @return array{version: int, name: string, icon: string|null, message: string|null, author: array{id: int, name: string, avatar: string|null}|null, created_at: string|null}
     */
    private function summary(PageVersion $version): array
    {
        return [
            'version' => $version->version,
            'name' => $version->name,
            'icon' => $version->icon,
            'message' => $version->message,
            'author' => $version->author?->only(['id', 'name', 'avatar']),
            'created_at' => $version->created_at?->toIso8601String(),
        ];
    }
}
