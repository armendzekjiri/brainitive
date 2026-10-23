<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * macOS is case-insensitive, Linux (CI, servers, Docker) isn't — and the repo tracks both
 * resources/js/components/ and resources/js/Components/. An import whose case differs
 * from the path git tracks builds on a Mac and breaks the production build. This compares
 * every `@/…` import with `git ls-files`, case-sensitively.
 */
class ImportPathCaseTest extends TestCase
{
    public function test_frontend_imports_match_tracked_file_case(): void
    {
        $root = dirname(__DIR__, 2);
        exec('git -C '.escapeshellarg($root).' ls-files resources/js 2>/dev/null', $tracked, $code);
        if ($code !== 0 || $tracked === []) {
            $this->markTestSkipped('git is not available.');
        }

        $tracked = array_flip($tracked);
        $trackedLower = [];
        foreach (array_keys($tracked) as $path) {
            $trackedLower[strtolower($path)][] = $path;
        }

        $problems = [];
        foreach (array_keys($tracked) as $file) {
            if (! preg_match('/\.(tsx?|jsx?)$/', $file) || ! is_file("$root/$file")) {
                continue;
            }
            preg_match_all("/(?:from\\s+|import\\s*\\(\\s*|import\\s+)['\"]@\\/([^'\"]+)['\"]/", file_get_contents("$root/$file"), $m);
            foreach ($m[1] as $import) {
                $candidates = [];
                foreach (['', '.tsx', '.ts', '.jsx', '.js', '.d.ts', '/index.tsx', '/index.ts', '/index.d.ts'] as $suffix) {
                    $candidates[] = "resources/js/{$import}{$suffix}";
                }
                if (array_filter($candidates, fn ($c) => isset($tracked[$c]))) {
                    continue; // exact match
                }
                foreach ($candidates as $candidate) {
                    if (isset($trackedLower[strtolower($candidate)])) {
                        $problems[] = "$file imports @/$import, but git tracks ".implode(' / ', $trackedLower[strtolower($candidate)]);
                        break;
                    }
                }
            }
        }

        $this->assertSame([], $problems, "Import case doesn't match the tracked path (breaks on Linux):\n".implode("\n", $problems));
    }
}
