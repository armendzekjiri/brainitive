<?php

namespace App\Support\Imports;

use ZipArchive;

/**
 * Extract an uploaded ZIP without trusting it (v1.41.0: Notion export, project archives).
 * Checked BEFORE anything is written: entry count, every name (no absolute paths, no "..", no
 * drive letters / backslash tricks, no NUL), nesting depth, symlinks (unix mode in the external
 * attributes), encryption, each declared size and the total, and the compression ratio (zip bombs).
 * While writing, the real byte count is enforced again, because declared sizes can lie.
 */
final class SafeZip
{
    public const DEFAULTS = [
        'max_entries' => 5000,
        'max_total_bytes' => 512 * 1024 * 1024,
        'max_file_bytes' => 50 * 1024 * 1024,
        'max_depth' => 20,
        'max_ratio' => 200, // uncompressed / compressed, per file
    ];

    /**
     * @param  array<string, int>  $limits  overrides of DEFAULTS
     * @return list<string> relative paths of the extracted files (directories omitted)
     *
     * @throws ImportFailed
     */
    public static function extract(string $zipPath, string $destination, array $limits = []): array
    {
        $limits += self::DEFAULTS;
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new ImportFailed('This file isn’t a readable ZIP archive.');
        }

        try {
            if ($zip->numFiles > $limits['max_entries']) {
                throw new ImportFailed("The archive has more than {$limits['max_entries']} files.");
            }

            $entries = [];
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i, ZipArchive::FL_UNCHANGED);
                $name = (string) ($stat['name'] ?? '');
                $path = self::safePath($name, $limits['max_depth']);
                if (($stat['encryption_method'] ?? 0) !== 0) {
                    throw new ImportFailed('Encrypted archives aren’t supported.');
                }
                if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000) {
                    throw new ImportFailed('The archive contains a symbolic link, which isn’t allowed.');
                }
                if (str_ends_with($name, '/')) {
                    continue; // directory entry
                }
                $size = (int) ($stat['size'] ?? 0);
                $compressed = max(1, (int) ($stat['comp_size'] ?? 0));
                if ($size > $limits['max_file_bytes']) {
                    throw new ImportFailed('"'.$path.'" is larger than the limit of '.intdiv($limits['max_file_bytes'], 1024 * 1024).' MB.');
                }
                if ($size > 1024 * 1024 && $size / $compressed > $limits['max_ratio']) {
                    throw new ImportFailed('The archive looks like a compression bomb and was refused.');
                }
                $total += $size;
                if ($total > $limits['max_total_bytes']) {
                    throw new ImportFailed('The unpacked archive would be larger than '.intdiv($limits['max_total_bytes'], 1024 * 1024).' MB.');
                }
                $entries[$i] = $path;
            }

            if (! is_dir($destination) && ! mkdir($destination, 0700, true) && ! is_dir($destination)) {
                throw new \RuntimeException('Could not create the extraction folder.');
            }
            $root = realpath($destination);

            $written = 0;
            foreach ($entries as $index => $path) {
                $target = $root.DIRECTORY_SEPARATOR.$path;
                $dir = dirname($target);
                if (! is_dir($dir)) {
                    mkdir($dir, 0700, true);
                }
                if (! str_starts_with(realpath($dir).DIRECTORY_SEPARATOR, $root.DIRECTORY_SEPARATOR) && realpath($dir) !== $root) {
                    throw new ImportFailed('The archive tries to write outside its folder.');
                }
                $in = $zip->getStream($zip->getNameIndex($index, ZipArchive::FL_UNCHANGED));
                if ($in === false) {
                    throw new ImportFailed('"'.$path.'" couldn’t be read from the archive.');
                }
                $out = fopen($target, 'xb');
                if ($out === false) {
                    fclose($in);
                    throw new ImportFailed('The archive contains "'.$path.'" twice.');
                }
                $bytes = 0;
                while (! feof($in)) {
                    $chunk = fread($in, 1024 * 1024);
                    if ($chunk === false) {
                        break;
                    }
                    $bytes += strlen($chunk);
                    $written += strlen($chunk);
                    if ($bytes > $limits['max_file_bytes'] || $written > $limits['max_total_bytes']) {
                        fclose($in);
                        fclose($out);
                        throw new ImportFailed('The archive unpacks to more data than it declares and was refused.');
                    }
                    fwrite($out, $chunk);
                }
                fclose($in);
                fclose($out);
            }

            return array_values($entries);
        } finally {
            $zip->close();
        }
    }

    /** A normalized relative path, or ImportFailed. */
    public static function safePath(string $name, int $maxDepth = self::DEFAULTS['max_depth']): string
    {
        $name = str_replace('\\', '/', $name);
        if ($name === '' || str_contains($name, "\0") || str_starts_with($name, '/') || preg_match('#^[a-zA-Z]:#', $name)) {
            throw new ImportFailed('The archive contains an unsafe file name.');
        }
        $parts = [];
        foreach (explode('/', rtrim($name, '/')) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                throw new ImportFailed('The archive contains an unsafe file name ("..").');
            }
            $parts[] = $part;
        }
        if ($parts === [] || count($parts) > $maxDepth) {
            throw new ImportFailed('The archive is nested too deeply.');
        }

        return implode('/', $parts);
    }
}
