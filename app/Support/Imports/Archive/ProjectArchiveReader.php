<?php

namespace App\Support\Imports\Archive;

use App\Support\Imports\ImportFailed;
use App\Support\Imports\SafeZip;
use Illuminate\Support\Facades\File;
use JsonException;

/**
 * Opens an uploaded project archive (BRAI-81) and verifies it before anything is written:
 * unpacked only through SafeZip, the manifest's format and version, and the sha256 of every file.
 * A file that is missing, changed or not listed in the manifest refuses the whole archive.
 */
final class ProjectArchiveReader
{
    /** @var array<string, array> decoded modules */
    private array $modules = [];

    private function __construct(private readonly string $dir, public readonly array $manifest) {}

    /**
     * Unpack $zipPath into $dir (replacing an earlier unpack) and verify it.
     *
     * @throws ImportFailed
     */
    public static function open(string $zipPath, string $dir): self
    {
        if (is_dir($dir)) {
            File::deleteDirectory($dir);
        }
        $extracted = SafeZip::extract($zipPath, $dir, ProjectArchive::ZIP_LIMITS);

        if (! in_array('manifest.json', $extracted, true)) {
            throw new ImportFailed('This isn’t a Brainitive project export: manifest.json is missing.');
        }
        $manifest = self::decode($dir.'/manifest.json', 'manifest.json', 16 * 1024 * 1024);
        if (($manifest['format'] ?? null) !== ProjectArchive::FORMAT) {
            throw new ImportFailed('This isn’t a Brainitive project export (unknown format).');
        }
        $version = $manifest['version'] ?? null;
        if (! is_int($version) || $version < 1) {
            throw new ImportFailed('This project export has no valid format version.');
        }
        if ($version > ProjectArchive::VERSION) {
            throw new ImportFailed("This export was made by a newer version of Brainitive (format {$version}). Update this instance to restore it.");
        }

        $files = $manifest['files'] ?? null;
        if (! is_array($files) || $files === [] || array_is_list($files)) {
            throw new ImportFailed('The export’s manifest lists no files.');
        }
        foreach (ProjectArchive::MODULES as $module) {
            if (! isset($files[$module])) {
                throw new ImportFailed("The export is incomplete: {$module} is missing.");
            }
        }

        $listed = [];
        foreach ($files as $path => $hash) {
            $path = (string) $path;
            if (SafeZip::safePath($path, 4) !== $path || $path === 'manifest.json') {
                throw new ImportFailed('The export’s manifest contains an unsafe file name.');
            }
            if (! is_string($hash) || ! preg_match('/^[0-9a-f]{64}$/', $hash)) {
                throw new ImportFailed("The export’s manifest has no valid checksum for {$path}.");
            }
            $listed[$path] = true;
            $absolute = $dir.'/'.$path;
            if (! is_file($absolute)) {
                throw new ImportFailed("The export is incomplete: {$path} is missing.");
            }
            if (! hash_equals($hash, hash_file('sha256', $absolute))) {
                throw new ImportFailed("The export was changed after it was made: the checksum of {$path} doesn’t match.");
            }
        }
        foreach ($extracted as $path) {
            if ($path !== 'manifest.json' && ! isset($listed[$path])) {
                throw new ImportFailed("The export contains a file the manifest doesn’t list ({$path}).");
            }
        }

        return new self($dir, $manifest);
    }

    /**
     * A module's decoded JSON (project.json, issues.json, …).
     *
     * @throws ImportFailed
     */
    public function module(string $name): array
    {
        if (! in_array($name, ProjectArchive::MODULES, true)) {
            throw new ImportFailed("Unknown export module {$name}.");
        }

        return $this->modules[$name] ??= self::decode($this->dir.'/'.$name, $name, ProjectArchive::MAX_JSON_BYTES);
    }

    /** A list inside a module, e.g. list('issues.json', 'issues'): only arrays survive. */
    public function list(string $module, string $key): array
    {
        $value = $this->module($module)[$key] ?? [];

        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    /** Absolute path of a file the manifest lists (attachments/…, media/…), or null. */
    public function file(?string $path): ?string
    {
        if (! is_string($path) || ! isset($this->manifest['files'][$path]) || in_array($path, ProjectArchive::MODULES, true)) {
            return null;
        }

        return $this->dir.'/'.$path;
    }

    /** media ref => archive path, for every embedded file. */
    public function mediaFiles(): array
    {
        $media = [];
        foreach (array_keys($this->manifest['files']) as $path) {
            if (preg_match('#^media/(m\d{1,6})(?:\.[a-z0-9]{1,8})?$#', (string) $path, $m)) {
                $media[$m[1]] = (string) $path;
            }
        }

        return $media;
    }

    /**
     * Counts of what the archive contains (from the modules, not from the manifest's claim). Each
     * module is decoded, counted and released again, so a preview in the web request holds at most
     * one module in memory.
     */
    public function counts(): array
    {
        $count = function (string $module, string $key, ?string $nested = null): int {
            $data = $this->modules[$module] ?? self::decode($this->dir.'/'.$module, $module, ProjectArchive::MAX_JSON_BYTES);
            $list = is_array($data[$key] ?? null) ? $data[$key] : [];
            unset($data);

            return $nested === null ? count($list) : array_sum(array_map(fn ($row) => is_array($row[$nested] ?? null) ? count($row[$nested]) : 0, $list));
        };

        $counts = [
            'boards' => $count('boards.json', 'boards'),
            'sprints' => $count('boards.json', 'boards', 'sprints'),
            'releases' => $count('boards.json', 'releases'),
            'issues' => $count('issues.json', 'issues'),
            'comments' => $count('issues.json', 'issues', 'comments'),
            'attachments' => $count('issues.json', 'issues', 'attachments'),
            'links' => $count('issues.json', 'links'),
            'labels' => $count('project.json', 'labels'),
            'custom_fields' => $count('project.json', 'fields'),
            'pages' => $count('pages.json', 'pages'),
            'task_lists' => $count('task_lists.json', 'task_lists'),
            'tasks' => $count('task_lists.json', 'task_lists', 'tasks'),
            'plans' => $count('plans.json', 'plans'),
            'helpdesks' => $count('helpdesks.json', 'helpdesks'),
        ];
        if ($counts['issues'] > ProjectArchive::MAX_ISSUES || $counts['pages'] > ProjectArchive::MAX_PAGES || $counts['tasks'] > ProjectArchive::MAX_TASKS) {
            throw new ImportFailed('This export is larger than a restore allows ('.number_format(ProjectArchive::MAX_ISSUES).' issues, '.number_format(ProjectArchive::MAX_PAGES).' docs, '.number_format(ProjectArchive::MAX_TASKS).' tasks).');
        }

        return $counts;
    }

    /** @throws ImportFailed */
    private static function decode(string $absolute, string $name, int $maxBytes): array
    {
        if (filesize($absolute) > $maxBytes) {
            throw new ImportFailed("{$name} in the export is too large.");
        }
        try {
            $data = json_decode((string) file_get_contents($absolute), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ImportFailed("{$name} in the export isn’t valid JSON.");
        }
        if (! is_array($data)) {
            throw new ImportFailed("{$name} in the export isn’t readable.");
        }

        return $data;
    }
}
