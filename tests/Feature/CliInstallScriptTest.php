<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The one-line installers of the CLI (v1.47.0) are served from the app so the address is the
 * product's own: `curl -fsSL https://brainitive.app/install.sh | sh`.
 */
class CliInstallScriptTest extends TestCase
{
    public function test_install_scripts_are_public_plain_text(): void
    {
        $sh = $this->get('/install.sh')->assertOk()->assertHeader('Content-Type', 'text/x-shellscript; charset=utf-8');
        $body = file_get_contents($sh->baseResponse->getFile()->getPathname());
        $this->assertStringStartsWith('#!/bin/sh', $body);
        $this->assertStringContainsString('releases/latest/download', $body);
        $this->assertStringContainsString('checksums.txt', $body);

        $ps1 = $this->get('/install.ps1')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=utf-8');
        $body = file_get_contents($ps1->baseResponse->getFile()->getPathname());
        $this->assertStringContainsString('brainitive.exe', $body);
        $this->assertStringContainsString('Get-FileHash', $body);

        // Public and session-free: no cookies on a `Cache-Control: public` response, no session row per curl.
        $response = $this->get('/install.sh')->assertHeader('Cache-Control', 'max-age=300, public');
        $this->assertSame([], $response->headers->getCookies(), 'the install script must not set cookies');
    }
}
