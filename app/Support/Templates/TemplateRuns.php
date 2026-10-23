<?php

namespace App\Support\Templates;

use App\Models\Project;
use App\Models\ProjectTemplate;
use App\Models\ProjectTemplateRun;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * "Create a project from a template" (BRAI-72): resolves the template, keeps a run record per
 * client request_id (a retried submit returns the project that already exists) and turns
 * failures into a message — the project itself is created in one transaction, so a failure
 * never leaves a half-made project behind.
 */
class TemplateRuns
{
    /** A run still "running" after this long died with its request (timeout, crash) and may be retried. */
    public const STALE_AFTER_MINUTES = 10;

    /**
     * The templates a person can start from in this workspace, for the "New project" page.
     *
     * @return list<array<string, mixed>>
     */
    public static function catalog(Workspace $workspace, User $user): array
    {
        $builtins = array_map(fn ($template) => [
            'id' => 'builtin:'.$template['key'],
            'source' => 'builtin',
            'category' => $template['category'],
            'tags' => $template['tags'],
            'name' => $template['name'],
            'description' => $template['description'],
            'icon' => $template['icon'],
            'summary' => TemplateDocument::summary(BuiltinTemplates::document($template['key'])),
        ], BuiltinTemplates::all());

        $canDelete = $user->can('create', [ProjectTemplate::class, $workspace]);
        $saved = ProjectTemplate::where('workspace_id', $workspace->id)
            ->with(['latestVersion' => fn ($q) => $q->select(
                'project_template_versions.id', 'project_template_versions.project_template_id', 'project_template_versions.version',
                'project_template_versions.summary', 'project_template_versions.created_at',
            ), 'creator:id,name'])
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->filter(fn (ProjectTemplate $template) => $template->latestVersion !== null)
            ->map(fn (ProjectTemplate $template) => [
                'id' => 'template:'.$template->uuid,
                'source' => 'workspace',
                'category' => 'workspace',
                'tags' => [],
                'name' => $template->name,
                'description' => $template->description,
                'icon' => 'layout-template',
                'summary' => $template->latestVersion->summary,
                'version' => $template->latestVersion->version,
                'updated_at' => $template->latestVersion->created_at?->toIso8601String(),
                'created_by' => $template->creator?->name,
                'uuid' => $template->uuid,
                'can_delete' => $canDelete,
            ])->values()->all();

        return [...$builtins, ...$saved];
    }

    /**
     * Gallery categories: the built-in ones, plus "Your workspace" when it has templates.
     *
     * @param  list<array<string, mixed>>  $catalog
     * @return list<array{key: string, name: string}>
     */
    public static function categories(array $catalog): array
    {
        $categories = array_map(fn ($key, $name) => ['key' => $key, 'name' => $name], array_keys(BuiltinTemplates::CATEGORIES), BuiltinTemplates::CATEGORIES);
        if (in_array('workspace', array_column($catalog, 'category'), true)) {
            array_unshift($categories, ['key' => 'workspace', 'name' => 'Your workspace']);
        }

        return $categories;
    }

    /**
     * The document behind a catalog id ("builtin:<key>" or "template:<uuid>"), checked for the user.
     *
     * @return array{document: array, label: string}
     *
     * @throws TemplateException|AuthorizationException
     */
    public static function resolve(Workspace $workspace, User $user, string $id): array
    {
        if (str_starts_with($id, 'builtin:') && BuiltinTemplates::has(substr($id, 8))) {
            return ['document' => BuiltinTemplates::document(substr($id, 8)), 'label' => $id];
        }

        // Postgres compares uuid columns strictly: anything else would be a 500, not "not found"
        if (str_starts_with($id, 'template:') && Str::isUuid(substr($id, 9))) {
            $template = ProjectTemplate::where('workspace_id', $workspace->id)->where('uuid', substr($id, 9))->with('latestVersion')->first();
            if ($template && $template->latestVersion) {
                Gate::forUser($user)->authorize('use', $template);

                return [
                    'document' => $template->latestVersion->document,
                    'label' => "template:{$template->uuid}@{$template->latestVersion->version}",
                ];
            }
        }

        throw new TemplateException('This template no longer exists.');
    }

    /**
     * The project a completed earlier submit with this request_id created, if any. Checked
     * before validation, because the retry would otherwise fail on "name already taken".
     */
    public static function alreadyCreated(Workspace $workspace, User $user, ?string $requestId): ?Project
    {
        if (! is_string($requestId) || ! Str::isUuid($requestId)) {
            return null;
        }

        $run = ProjectTemplateRun::where('request_id', $requestId)
            ->where('user_id', $user->id)
            ->where('workspace_id', $workspace->id)
            ->where('status', ProjectTemplateRun::STATUS_COMPLETED)
            ->first();

        return $run?->project;
    }

    /**
     * @param  array{template: string, name: string, key: string, description?: string|null, start_date: string, include?: list<string>|null, request_id?: string|null}  $input
     *
     * @throws ValidationException
     */
    public static function createProject(Workspace $workspace, User $user, array $input): Project
    {
        $busy = fn () => ValidationException::withMessages(['template' => 'This project is already being created — give it a moment.']);
        $requestId = isset($input['request_id']) && Str::isUuid((string) $input['request_id']) ? (string) $input['request_id'] : null;

        $run = null;
        if ($requestId !== null) {
            $existing = ProjectTemplateRun::where('request_id', $requestId)->first();
            if ($existing && ($existing->user_id !== $user->id || $existing->workspace_id !== $workspace->id)) {
                throw ValidationException::withMessages(['template' => 'Please reload the page and try again.']);
            }
            if ($existing?->status === ProjectTemplateRun::STATUS_COMPLETED && $existing->project) {
                return $existing->project;
            }
            $run = $existing;
        }

        try {
            $resolved = self::resolve($workspace, $user, $input['template']);
        } catch (TemplateException $e) {
            throw ValidationException::withMessages(['template' => $e->getMessage()]);
        }

        if ($requestId !== null) {
            if ($run === null) {
                try {
                    $run = ProjectTemplateRun::create([
                        'request_id' => $requestId,
                        'user_id' => $user->id,
                        'workspace_id' => $workspace->id,
                        'template' => $resolved['label'],
                        'status' => ProjectTemplateRun::STATUS_RUNNING,
                    ]);
                } catch (UniqueConstraintViolationException) {
                    // A parallel submit with the same request won the race
                    throw $busy();
                }
            } else {
                // Claim a failed (or stale "running") run atomically: two parallel retries can't both start
                $claimed = ProjectTemplateRun::whereKey($run->id)
                    ->where(fn ($q) => $q->where('status', '!=', ProjectTemplateRun::STATUS_RUNNING)
                        ->orWhere('updated_at', '<', now()->subMinutes(self::STALE_AFTER_MINUTES)))
                    ->update(['status' => ProjectTemplateRun::STATUS_RUNNING, 'template' => $resolved['label'], 'error' => null, 'updated_at' => now()]);
                if ($claimed === 0) {
                    throw $busy();
                }
            }
        }

        $include = $input['include'] ?? TemplateDocument::SECTIONS;

        try {
            $project = TemplateInstantiator::create(
                $workspace,
                $user,
                TemplateDocument::only(TemplateDocument::normalize($resolved['document']), $include),
                $input,
            );
        } catch (TemplateException $e) {
            $run?->update(['status' => ProjectTemplateRun::STATUS_FAILED, 'error' => mb_substr($e->getMessage(), 0, 255)]);
            throw ValidationException::withMessages(['template' => $e->getMessage()]);
        } catch (Throwable $e) {
            $run?->update(['status' => ProjectTemplateRun::STATUS_FAILED, 'error' => 'Unexpected error']);
            // No template content in the log (a QueryException message carries the bound values)
            Log::error('Creating a project from a template failed', ['template' => $resolved['label'], 'workspace_id' => $workspace->id, 'exception' => $e::class, 'code' => $e->getCode()]);
            throw ValidationException::withMessages(['template' => 'The project could not be created from this template. Nothing was saved — please try again.']);
        }

        $run?->update(['status' => ProjectTemplateRun::STATUS_COMPLETED, 'project_id' => $project->id]);

        return $project;
    }
}
