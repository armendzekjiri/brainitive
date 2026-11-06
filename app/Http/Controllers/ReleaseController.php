<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\Project;
use App\Models\Release;
use App\Models\Workspace;
use App\Support\Entitlements\LimitGate;
use App\Support\Releases\ReleaseLifecycle;
use App\Support\Releases\ReleaseNotes;
use App\Support\SafeHtml;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Releases ("fix versions") of a project: plan, track progress, ship, and publish notes.
 */
class ReleaseController extends Controller
{
    public function index(Request $request, Workspace $workspace, Project $project): Response
    {
        $this->authorize('viewAny', [Release::class, $project]);

        $releases = ReleaseLifecycle::ordered($project)->get()
            ->map(fn (Release $r) => $this->summary($r));

        return Inertia::render('Releases/Index', [
            'workspace' => $workspace,
            'project' => $project,
            'releases' => $releases,
            'canManage' => $request->user()->can('create', [Release::class, $project]),
            'changelogUrl' => $project->changelog_token && $project->releases()->whereNotNull('published_at')->exists()
                ? route('changelog.public', $project->changelog_token) : null,
        ]);
    }

    public function show(Request $request, Workspace $workspace, Project $project, Release $release): Response
    {
        $this->scope($project, $release);
        $this->authorize('view', $release);

        $items = ReleaseLifecycle::itemsWithState($release, ['itemType:id,name,slug,icon,color', 'assignee:id,name,avatar']);

        return Inertia::render('Releases/Show', [
            'workspace' => $workspace,
            'project' => $project,
            'release' => $this->summary($release) + [
                'description' => $release->description,
                'notes' => $release->notes,
                'published_notes' => $release->published_notes,
                'public_url' => $release->isPublished() ? route('releases.public', $release->public_token) : null,
                'notes_changed' => $release->isPublished() && $release->notes !== $release->published_notes,
            ],
            'items' => $items->map(function (array $row) use ($workspace, $project) {
                $i = $row['item'];

                return [
                    'id' => $i->id,
                    'key' => $i->key,
                    'title' => $i->title,
                    'state' => $row['state'],
                    'status' => $i->column?->name,
                    'type' => $i->itemType?->only(['name', 'slug', 'icon', 'color']),
                    'assignee' => $i->assignee?->only(['id', 'name', 'avatar']),
                    'is_subtask' => $i->parent_id !== null,
                    'url' => $i->board ? route('boards.show', [$workspace->slug, $project->slug, $i->board->slug]).'#item-'.$i->id : null,
                ];
            })->values(),
            'otherReleases' => $project->releases()->where('status', Release::STATUS_UNRELEASED)->whereKeyNot($release->id)
                ->orderByRaw('release_date is null')->orderBy('release_date')->orderBy('id')->get(['id', 'name']),
            'canManage' => $request->user()->can('update', $release),
        ]);
    }

    public function store(Request $request, Workspace $workspace, Project $project): RedirectResponse
    {
        $this->authorize('create', [Release::class, $project]);
        $validated = $this->validated($request, $project);

        app(LimitGate::class)->ensureContentRoom($workspace); // content limit (Codex web review B)
        $release = $project->releases()->create($validated + ['created_by' => $request->user()->id]);

        return redirect()->route('releases.show', [$workspace->slug, $project->slug, $release->id])->with('success', "Release {$release->name} created.");
    }

    public function update(Request $request, Workspace $workspace, Project $project, Release $release): RedirectResponse
    {
        $this->scope($project, $release);
        $this->authorize('update', $release);

        $release->update($this->validated($request, $project, $release));

        return back()->with('success', 'Release updated.');
    }

    public function destroy(Workspace $workspace, Project $project, Release $release): RedirectResponse
    {
        $this->scope($project, $release);
        $this->authorize('delete', $release);

        $release->delete(); // issues keep existing, their fix version becomes empty

        return redirect()->route('releases.index', [$workspace->slug, $project->slug])->with('success', "Release {$release->name} deleted.");
    }

    /**
     * Ship it: unfinished issues go to another unreleased release or lose their fix version.
     */
    public function release(Request $request, Workspace $workspace, Project $project, Release $release): RedirectResponse
    {
        $this->scope($project, $release);
        $this->authorize('update', $release);

        $validated = $request->validate([
            'release_date' => 'required|date',
            'move_unfinished_to' => ['nullable', 'integer', Rule::exists('releases', 'id')->where('project_id', $project->id)->where('status', Release::STATUS_UNRELEASED)],
        ]);
        if (! $release->isUnreleased()) {
            return back()->withErrors(['release' => 'This release was already released.']);
        }
        if ((int) ($validated['move_unfinished_to'] ?? 0) === $release->id) {
            return back()->withErrors(['move_unfinished_to' => 'Pick another release.']);
        }

        $target = isset($validated['move_unfinished_to']) ? (int) $validated['move_unfinished_to'] : null;
        $moved = ReleaseLifecycle::ship($release, $validated['release_date'], $target);

        return back()->with('success', "{$release->name} released".($moved ? " — {$moved} unfinished issue".($moved === 1 ? '' : 's').' moved.' : '.'));
    }

    public function setStatus(Request $request, Workspace $workspace, Project $project, Release $release): RedirectResponse
    {
        $this->scope($project, $release);
        $this->authorize('update', $release);

        $status = $request->validate(['status' => 'required|in:unreleased,released,archived'])['status'];
        try {
            ReleaseLifecycle::setStatus($release, $status);
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('success', match ($status) {
            Release::STATUS_UNRELEASED => 'Release reopened.',
            Release::STATUS_ARCHIVED => 'Release archived.',
            default => 'Release restored.',
        });
    }

    public function addItems(Request $request, Workspace $workspace, Project $project, Release $release): RedirectResponse
    {
        $this->scope($project, $release);
        $this->authorize('update', $release);
        if ($release->status === Release::STATUS_ARCHIVED) {
            return back()->withErrors(['items' => 'Archived releases can’t get new issues.']);
        }

        $ids = $request->validate(['item_ids' => 'required|array|min:1|max:200', 'item_ids.*' => 'integer'])['item_ids'];
        $items = ReleaseLifecycle::assignItems($project, $release, array_map('intval', $ids));

        return back()->with('success', $items->count().' issue'.($items->count() === 1 ? '' : 's')." added to {$release->name}.");
    }

    public function removeItem(Workspace $workspace, Project $project, Release $release, Item $item): RedirectResponse
    {
        $this->scope($project, $release);
        $this->authorize('update', $release);
        abort_unless($item->release_id === $release->id, 404);

        $item->update(['release_id' => null]);

        return back();
    }

    /** Issues of the project for the "Add issues" picker, flagged when already in another release. */
    public function candidates(Request $request, Workspace $workspace, Project $project, Release $release): JsonResponse
    {
        $this->scope($project, $release);
        $this->authorize('update', $release);

        $q = trim((string) $request->query('q', ''));
        $items = $project->items()->with('release:id,name,status')
            ->where(fn ($w) => $w->whereNull('release_id')->orWhere('release_id', '!=', $release->id))
            ->when($q !== '', fn ($w) => $w->where(fn ($s) => $s->whereLike('title', '%'.$q.'%')->orWhereLike('key', '%'.$q.'%')))
            ->orderByRaw('completed_at is not null')->orderByDesc('updated_at')->limit(50)
            ->get(['id', 'key', 'title', 'release_id', 'completed_at']);

        return response()->json($items->map(fn (Item $i) => [
            'id' => $i->id,
            'key' => $i->key,
            'title' => $i->title,
            'done' => $i->completed_at !== null,
            'release' => $i->release?->only(['name', 'status']),
        ]));
    }

    public function draftNotes(Workspace $workspace, Project $project, Release $release): JsonResponse
    {
        $this->scope($project, $release);
        $this->authorize('update', $release);

        return response()->json(['html' => ReleaseNotes::draft($release)]);
    }

    public function saveNotes(Request $request, Workspace $workspace, Project $project, Release $release): RedirectResponse
    {
        $this->scope($project, $release);
        $this->authorize('update', $release);

        $notes = $request->validate(['notes' => 'nullable|string|max:200000'])['notes'];
        $release->update(['notes' => SafeHtml::clean($notes)]);

        return back()->with('success', 'Release notes saved.');
    }

    /** Publish (or publish an update of) the notes; safe to repeat — one public page per release. */
    public function publish(Workspace $workspace, Project $project, Release $release): RedirectResponse
    {
        $this->scope($project, $release);
        $this->authorize('update', $release);

        if (trim(strip_tags((string) $release->notes)) === '') {
            return back()->withErrors(['notes' => 'Write or generate the release notes before publishing.']);
        }

        DB::transaction(function () use ($project, $release) {
            $release->forceFill([
                'public_token' => $release->public_token ?? Str::random(40),
                'published_notes' => SafeHtml::clean($release->notes),
                'published_at' => $release->published_at ?? now(),
            ])->save();
            if (! $project->changelog_token) {
                $project->forceFill(['changelog_token' => Str::random(40)])->save();
            }
        });

        return back()->with('success', 'Release notes published.');
    }

    public function unpublish(Workspace $workspace, Project $project, Release $release): RedirectResponse
    {
        $this->scope($project, $release);
        $this->authorize('update', $release);

        // A new token next time, so old links stay dead.
        $release->forceFill(['published_at' => null, 'published_notes' => null, 'public_token' => null])->save();

        return back()->with('success', 'Release notes unpublished.');
    }

    private function scope(Project $project, Release $release): void
    {
        abort_unless($release->project_id === $project->id, 404);
    }

    private function validated(Request $request, Project $project, ?Release $release = null): array
    {
        return $request->validate(ReleaseLifecycle::rules($project, $release));
    }

    /** @return array<string, mixed> */
    private function summary(Release $release): array
    {
        return [
            'id' => $release->id,
            'name' => $release->name,
            'status' => $release->status,
            'start_date' => $release->start_date?->toDateString(),
            'release_date' => $release->release_date?->toDateString(),
            'released_at' => $release->released_at?->toIso8601String(),
            'overdue' => $release->isOverdue(),
            'published' => $release->isPublished(),
            'progress' => $release->progress(),
        ];
    }
}
