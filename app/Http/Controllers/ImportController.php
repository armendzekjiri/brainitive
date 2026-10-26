<?php

namespace App\Http\Controllers;

use App\Jobs\RunImport;
use App\Models\ImportRun;
use App\Models\Project;
use App\Models\Workspace;
use App\Support\Imports\ImportFailed;
use App\Support\Imports\ImportRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Bring your data (v1.41.0): upload → preview (dry run) → confirm → queued run → report.
 * The importers (ImportRegistry) do the source-specific work; this controller only drives the
 * lifecycle, permissions and files. Contract: docs/ai/tasks/release-v1.41.0.md.
 */
class ImportController extends Controller
{
    public function index(Request $request, Workspace $workspace): Response
    {
        $this->authorize('view', $workspace);
        $user = $request->user();

        return Inertia::render('Imports/Index', [
            'workspace' => $workspace->only('id', 'name', 'slug'),
            'sources' => ImportRegistry::catalog(),
            'projects' => $workspace->projects()->orderBy('name')->get(['id', 'name', 'slug', 'key', 'icon', 'color', 'workspace_id'])
                ->filter(fn (Project $p) => $user->can('update', $p) || $user->can('createBoards', $p))
                ->map(fn (Project $p) => $p->only('id', 'name', 'slug', 'key', 'icon', 'color'))
                ->values(),
            'preselectedProject' => $request->integer('project') ?: null,
            'canCreateProjects' => $user->can('create', [Project::class, $workspace]),
            'runs' => $this->visibleRuns($request, $workspace)->latest()->limit(20)->get()->map(fn (ImportRun $r) => $this->present($r))->values(),
        ]);
    }

    public function store(Request $request, Workspace $workspace): RedirectResponse
    {
        $this->authorize('view', $workspace);
        $source = (string) $request->input('source');
        if (! array_key_exists($source, ImportRegistry::sources())) {
            throw ValidationException::withMessages(['source' => 'Pick what you want to import.']);
        }
        $importer = ImportRegistry::for($source);

        $data = $request->validate([
            'project_id' => $importer::needsProject()
                ? ['required', 'integer', Rule::exists('projects', 'id')->where('workspace_id', $workspace->id)->whereNull('deleted_at')]
                : ['prohibited'],
            'file' => ['required', 'file', ...$importer::fileRules()],
        ], ['project_id.required' => 'Pick the project to import into.']);

        $project = isset($data['project_id']) ? Project::find($data['project_id']) : null;
        abort_unless($importer::authorize($request->user(), $workspace, $project), 403);

        $file = $request->file('file');
        $run = ImportRun::create([
            'workspace_id' => $workspace->id,
            'project_id' => $project?->id,
            'user_id' => $request->user()->id,
            'source' => $source,
            'status' => ImportRun::UPLOADED,
            'file_name' => mb_substr((string) $file->getClientOriginalName(), 0, 200),
            'file_size' => (int) $file->getSize(),
            'source_fingerprint' => hash_file('sha256', $file->getRealPath()),
        ]);
        $extension = strtolower((string) $file->getClientOriginalExtension()) ?: 'bin';
        $path = $file->storeAs('imports/'.$run->uuid, 'source.'.preg_replace('/[^a-z0-9]/', '', $extension), 'local');
        $run->forceFill(['file_path' => $path])->save();

        try {
            $preview = ImportRegistry::make($source)->preview($run, Storage::disk('local')->path($path));
            $run->forceFill(['status' => ImportRun::PREVIEWED, 'preview' => $preview])->save();
        } catch (ImportFailed $e) {
            $run->forceFill(['status' => ImportRun::FAILED, 'error' => $e->getMessage()])->save();
            RunImport::deleteFiles($run);
        } catch (Throwable $e) {
            report($e);
            $run->forceFill(['status' => ImportRun::FAILED, 'error' => 'This file couldn’t be read. Check that it is the right export and try again.'])->save();
            RunImport::deleteFiles($run);
        }

        return redirect()->route('imports.show', [$workspace->slug, $run->uuid]);
    }

    public function show(Request $request, Workspace $workspace, ImportRun $importRun): Response
    {
        $this->guard($request, $workspace, $importRun);

        return Inertia::render('Imports/Show', [
            'workspace' => $workspace->only('id', 'name', 'slug'),
            'run' => $this->present($importRun, detail: true),
            'source' => collect(ImportRegistry::catalog())->firstWhere('key', $importRun->source),
            'importedBefore' => $this->importedBefore($importRun),
        ]);
    }

    /** Confirm the preview (with the importer's options) and start the run; also "retry" after a failure. */
    public function start(Request $request, Workspace $workspace, ImportRun $importRun): RedirectResponse
    {
        $this->guard($request, $workspace, $importRun);
        if (! in_array($importRun->status, ImportRun::STARTABLE, true)) {
            throw ValidationException::withMessages(['run' => 'This import can’t be started now.']);
        }
        if ($importRun->file_path === null || ! Storage::disk('local')->exists($importRun->file_path)) {
            throw ValidationException::withMessages(['run' => 'The uploaded file is gone (uploads are kept for 7 days). Upload it again.']);
        }

        $importer = ImportRegistry::make($importRun->source);
        $project = $importRun->project;
        abort_unless($importer::authorize($request->user(), $workspace, $project), 403);

        if ($importRun->status === ImportRun::PREVIEWED) {
            $options = $request->validate(['options' => ['array'], ...collect($importer->optionRules($importRun))->mapWithKeys(fn ($rules, $key) => ["options.$key" => $rules])->all()])['options'] ?? [];
            $importRun->forceFill(['options' => $options])->save();
        }

        $importRun->forceFill(['status' => ImportRun::QUEUED, 'error' => null])->save();
        $job = RunImport::dispatch($importRun);
        if (config('queue.default') === 'sync') {
            $job->afterResponse(); // dev / small installs: don't hold the request open
        }

        return redirect()->route('imports.show', [$workspace->slug, $importRun->uuid]);
    }

    /** Progress for the polling page. */
    public function status(Request $request, Workspace $workspace, ImportRun $importRun): JsonResponse
    {
        $this->guard($request, $workspace, $importRun);

        return response()->json($this->present($importRun, detail: true));
    }

    /** Cancel / forget a run that hasn't finished, and its files. What was already created stays. */
    public function destroy(Request $request, Workspace $workspace, ImportRun $importRun): RedirectResponse
    {
        $this->guard($request, $workspace, $importRun);
        if ($importRun->status === ImportRun::RUNNING) {
            throw ValidationException::withMessages(['run' => 'This import is running. Wait until it finishes or fails.']);
        }
        RunImport::deleteFiles($importRun);
        if ($importRun->status === ImportRun::COMPLETED) {
            $importRun->forceFill(['file_path' => null])->save();
        } else {
            $importRun->forceFill(['status' => ImportRun::CANCELLED, 'file_path' => null])->save();
        }

        return redirect()->route('imports.index', $workspace->slug)->with('success', 'Import removed.');
    }

    private function guard(Request $request, Workspace $workspace, ImportRun $run): void
    {
        abort_unless($run->workspace_id === $workspace->id, 404);
        $user = $request->user();
        abort_unless($run->user_id === $user->id || $user->can('update', $workspace), 403);
    }

    private function visibleRuns(Request $request, Workspace $workspace)
    {
        $user = $request->user();

        return ImportRun::query()->where('workspace_id', $workspace->id)
            ->when(! $user->can('update', $workspace), fn ($q) => $q->where('user_id', $user->id))
            ->with('user:id,name', 'project:id,name,slug');
    }

    /** A completed earlier run of the same file (same sha256) in this workspace. */
    private function importedBefore(ImportRun $run): ?array
    {
        $earlier = ImportRun::query()->where('workspace_id', $run->workspace_id)
            ->where('source_fingerprint', $run->source_fingerprint)
            ->where('status', ImportRun::COMPLETED)
            ->where('id', '!=', $run->id)
            ->latest()->first();

        return $earlier ? ['uuid' => $earlier->uuid, 'finished_at' => $earlier->finished_at?->toIso8601String()] : null;
    }

    private function present(ImportRun $run, bool $detail = false): array
    {
        $run->loadMissing('user:id,name', 'project:id,name,slug');

        return [
            'uuid' => $run->uuid,
            'source' => $run->source,
            'status' => $run->status,
            'file_name' => $run->file_name,
            'file_size' => $run->file_size,
            'project' => $run->project?->only('id', 'name', 'slug'),
            'user' => $run->user?->only('id', 'name'),
            'progress' => ['done' => $run->progress_done, 'total' => $run->progress_total],
            'error' => $run->error,
            'created_at' => $run->created_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'result' => $run->result,
            ...($detail ? ['preview' => $run->preview, 'options' => $run->options, 'report' => $run->report] : []),
        ];
    }
}
