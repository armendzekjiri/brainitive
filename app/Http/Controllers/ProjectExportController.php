<?php

namespace App\Http\Controllers;

use App\Jobs\ExportProject;
use App\Models\Project;
use App\Models\ProjectExport;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Project export (v1.41.0, BRAI-81): project admins queue an archive of the project, then download
 * it through a signed link that only works for them and only for 24 h. Restore is the `brainitive`
 * importer (ImportController). Contract: docs/ai/tasks/release-v1.41.0.md §4.
 */
class ProjectExportController extends Controller
{
    /** The exporter's recent exports of this project (the settings card polls it while one runs). */
    public function index(Request $request, Workspace $workspace, Project $project): JsonResponse
    {
        $this->guardProject($workspace, $project);
        $this->authorize('update', $project);

        return response()->json(['exports' => $this->recent($request, $project)]);
    }

    public function store(Request $request, Workspace $workspace, Project $project): RedirectResponse|JsonResponse
    {
        $this->guardProject($workspace, $project);
        $this->authorize('update', $project);
        $user = $request->user();

        // Old files of this project go now too (the schedule may not run on small installs)
        ProjectExport::where('project_id', $project->id)->whereNotNull('file_path')->where('expires_at', '<', now())->get()->each->expire();

        if (ProjectExport::where('project_id', $project->id)->where('user_id', $user->id)->whereIn('status', [ProjectExport::QUEUED, ProjectExport::RUNNING])->where('created_at', '>', now()->subHour())->exists()) {
            throw ValidationException::withMessages(['export' => 'An export of this project is already being prepared.']);
        }

        $export = ProjectExport::create([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'status' => ProjectExport::QUEUED,
        ]);
        $job = ExportProject::dispatch($export);
        if (config('queue.default') === 'sync') {
            $job->afterResponse(); // dev / small installs: don't hold the request open
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['exports' => $this->recent($request, $project)], 202);
        }

        return back()->with('success', 'The export is being prepared. You’ll get a notification when it’s ready.');
    }

    /**
     * The ZIP. Needs a valid signature (the link expires with the export), the exporter's own
     * session and a file that's still there. An Inertia visit (the notification bell) is turned into
     * a full page load so the browser downloads the file.
     */
    public function download(Request $request, Workspace $workspace, ProjectExport $projectExport): Response
    {
        abort_unless($projectExport->workspace_id === $workspace->id, 404);
        abort_unless($projectExport->user_id === $request->user()->id, 403);
        $project = $projectExport->project;
        abort_if($project === null, 404);
        $this->authorize('update', $project);

        abort_unless(URL::hasCorrectSignature($request, absolute: false), 403, 'Invalid download link.');
        if (! URL::signatureHasNotExpired($request) || ! $projectExport->isDownloadable() || ! Storage::disk('local')->exists((string) $projectExport->file_path)) {
            // An old link (from a notification): send them to where a new export can be started
            return redirect()->route('projects.edit', [$workspace->slug, $project->slug, 'tab' => 'general'])
                ->with('error', 'This download link has expired. Start a new export.');
        }

        if ($request->header('X-Inertia')) {
            return Inertia::location($request->fullUrl());
        }

        return Storage::disk('local')->download((string) $projectExport->file_path, basename((string) $projectExport->file_path), [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function guardProject(Workspace $workspace, Project $project): void
    {
        // the projects/{project} route group isn't scope-bound (KNOWLEDGE.md)
        abort_unless($project->workspace_id === $workspace->id, 404);
    }

    private function recent(Request $request, Project $project): array
    {
        return ProjectExport::where('project_id', $project->id)->where('user_id', $request->user()->id)
            ->with('workspace:id,slug')
            ->latest('id')->limit(5)->get()
            ->map(fn (ProjectExport $e) => $e->present())->values()->all();
    }
}
