<?php

namespace App\Support\Imports\Archive;

use App\Models\ImportRun;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Imports\Importer;
use App\Support\Imports\ImportFailed;
use App\Support\Imports\ImportProgress;
use Closure;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Restore a Brainitive project export as a NEW project (v1.41.0, BRAI-81). The archive is checked
 * completely in preview() (SafeZip, manifest version, every checksum) before anything is written;
 * run() creates the project through ImportMappings, so a retry continues without duplicates.
 */
class ProjectArchiveImporter implements Importer
{
    public static function source(): string
    {
        return 'brainitive';
    }

    public static function label(): string
    {
        return 'Brainitive project export';
    }

    public static function description(): string
    {
        return 'A ZIP from Project settings → Export project. It comes back as a new project with its boards, issues, docs, task lists, plans and attachments.';
    }

    public static function accept(): string
    {
        return '.zip,application/zip';
    }

    public static function fileRules(): array
    {
        return ['extensions:zip', 'max:'.(200 * 1024)]; // 200 MB, matches docker/php.ini upload limits
    }

    public static function needsProject(): bool
    {
        return false;
    }

    public static function authorize(User $user, Workspace $workspace, ?Project $project): bool
    {
        return $user->can('create', [Project::class, $workspace]);
    }

    public function preview(ImportRun $run, string $path): array
    {
        $dir = self::workDir($path);
        try {
            $archive = ProjectArchiveReader::open($path, $dir);
            $project = $archive->module('project.json');
            $counts = $archive->counts();
            $members = self::members($run->workspace);
            $byEmail = collect($members)->filter(fn ($m) => $m['email'])->keyBy(fn ($m) => Str::lower($m['email']));

            $people = array_map(function (array $person) use ($byEmail) {
                $email = is_string($person['email'] ?? null) ? $person['email'] : null;
                $match = $email ? $byEmail->get(Str::lower($email)) : null;

                return [
                    'ref' => (string) ($person['ref'] ?? ''),
                    'name' => Str::limit((string) ($person['name'] ?? ''), 120),
                    'email' => $email ? Str::limit($email, 190) : null,
                    'member_id' => $match['id'] ?? null,
                ];
            }, $archive->list('people.json', 'people'));

            $name = Str::limit(trim((string) ($project['name'] ?? '')) ?: 'Restored project', 200, '');
            $key = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) ($project['key'] ?? '')) ?: 'PRJ');

            return [
                'format_version' => $archive->manifest['version'],
                'app_version' => is_string($archive->manifest['app_version'] ?? null) ? Str::limit($archive->manifest['app_version'], 20) : null,
                'exported_at' => is_string($archive->manifest['created_at'] ?? null) ? $archive->manifest['created_at'] : null,
                'source' => ['name' => $name, 'key' => substr($key, 0, 10)],
                'suggested' => ['name' => self::freeName($run->workspace, $name), 'key' => self::freeKey($run->workspace, $key)],
                'counts' => $counts,
                'people' => $people,
                'members' => $members,
                'omitted' => array_values(array_map(fn ($o) => [
                    'what' => Str::limit((string) ($o['what'] ?? ''), 120),
                    'count' => is_int($o['count'] ?? null) ? $o['count'] : null,
                    'why' => Str::limit((string) ($o['why'] ?? ''), 300),
                ], array_filter(is_array($archive->manifest['omitted'] ?? null) ? $archive->manifest['omitted'] : [], 'is_array'))),
            ];
        } finally {
            File::deleteDirectory($dir); // run() unpacks and verifies again
        }
    }

    public function optionRules(ImportRun $run): array
    {
        $workspaceId = $run->workspace_id;

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('projects', 'name')->where('workspace_id', $workspaceId)],
            'key' => ['required', 'string', 'max:10', 'regex:/^[A-Za-z]+$/', function (string $attribute, mixed $value, Closure $fail) use ($workspaceId) {
                if (is_string($value) && Project::withTrashed()->where('workspace_id', $workspaceId)->where('key', strtoupper($value))->exists()) {
                    $fail('This key is already used by another project.');
                }
            }],
            'people' => ['nullable', 'array'],
            'people.*' => ['nullable', 'integer', Rule::exists('workspace_user', 'user_id')->where('workspace_id', $workspaceId)],
        ];
    }

    public function run(ImportRun $run, string $path, ImportProgress $progress): array
    {
        $archive = ProjectArchiveReader::open($path, self::workDir($path));
        $archive->counts(); // limits again

        $workspace = $run->workspace;
        $user = $run->user ?? throw new ImportFailed('The person who started this restore no longer exists.');
        if (! self::authorize($user, $workspace, null)) {
            throw new ImportFailed('You can no longer create projects in this workspace.');
        }

        try {
            return (new ProjectArchiveRestorer($run, $archive, $progress, $user, $workspace, $run->options ?? []))->run();
        } finally {
            File::deleteDirectory(self::workDir($path));
        }
    }

    /** Workspace members for the people mapping. */
    private static function members(Workspace $workspace): array
    {
        return $workspace->users()->orderBy('name')->get(['users.id', 'users.name', 'users.email'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])->values()->all();
    }

    private static function workDir(string $path): string
    {
        return dirname($path).'/archive';
    }

    private static function freeName(Workspace $workspace, string $name): string
    {
        $candidate = $name;
        for ($n = 1; Project::where('workspace_id', $workspace->id)->where('name', $candidate)->exists(); $n++) {
            $candidate = $name.' (restored'.($n > 1 ? ' '.$n : '').')';
        }

        return $candidate;
    }

    private static function freeKey(Workspace $workspace, string $key): string
    {
        $taken = Project::withTrashed()->where('workspace_id', $workspace->id)->pluck('key')->flip();
        $candidates = [substr($key, 0, 10)];
        foreach (range('A', 'Z') as $a) {
            $candidates[] = substr($key, 0, 9).$a;
        }
        foreach (range('A', 'Z') as $a) {
            foreach (range('A', 'Z') as $b) {
                $candidates[] = substr($key, 0, 8).$a.$b;
            }
        }
        foreach ($candidates as $candidate) {
            if (! isset($taken[$candidate])) {
                return $candidate;
            }
        }

        return $candidates[0];
    }
}
