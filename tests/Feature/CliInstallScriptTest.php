<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The one-line installers of the CLI (v1.47.0). Since v1.47.1 they ship on the GitHub release next to
 * the binaries; the app's short addresses forward there, so `curl -fsSL https://brainitive.app/install.sh | sh`
 * works on every instance, whatever version it runs.
 */
class CliInstallScriptTest extends TestCase
{
    public function test_install_addresses_forward_to_the_latest_github_release(): void
    {
        config(['services.github.repository' => 'acme/brainitive']);

        $sh = $this->get('/install.sh')->assertRedirect('https://github.com/acme/brainitive/releases/latest/download/install.sh');
        $this->get('/install.ps1')->assertRedirect('https://github.com/acme/brainitive/releases/latest/download/install.ps1');

        // Public and session-free: no cookies on a `Cache-Control: public` response, no session row per curl.
        $this->assertStringContainsString('public', (string) $sh->headers->get('Cache-Control'));
        $this->assertSame([], $sh->headers->getCookies(), 'the install address must not set cookies');
    }

    public function test_the_installers_in_the_repo_download_the_release_binaries(): void
    {
        $sh = file_get_contents(base_path('cli/install.sh'));
        $this->assertStringStartsWith('#!/bin/sh', $sh);
        $this->assertStringContainsString('releases/latest/download', $sh);
        $this->assertStringContainsString('checksums.txt', $sh);

        $ps1 = file_get_contents(base_path('cli/install.ps1'));
        $this->assertStringContainsString('brainitive.exe', $ps1);
        $this->assertStringContainsString('Get-FileHash', $ps1);

        // release.yml uploads both installers next to the binaries
        $release = file_get_contents(base_path('.github/workflows/release.yml'));
        $this->assertStringContainsString('dist/install.sh dist/install.ps1', $release);
    }
}
