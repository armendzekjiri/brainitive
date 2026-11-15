<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * GitHub #153: in the Docker image every upload (icons, logos, images) answered 404. The container runs
 * as www-data, /app/public belongs to root, so the entrypoint's `storage:link` failed — silently. The
 * image now creates the link at build time, and the entrypoint only repairs a missing one, loudly.
 */
class DockerStorageLinkTest extends TestCase
{
    private function repoFile(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/'.$path);
    }

    public function test_the_image_creates_the_storage_link_as_root(): void
    {
        $dockerfile = $this->repoFile('Dockerfile');

        $link = strpos($dockerfile, 'ln -sfn /app/storage/app/public /app/public/storage');
        $user = strpos($dockerfile, 'USER www-data');

        $this->assertNotFalse($link, 'the Dockerfile must create public/storage → storage/app/public');
        $this->assertNotFalse($user);
        $this->assertLessThan($user, $link, 'the link must be created before the image switches to www-data');
    }

    public function test_the_entrypoint_does_not_swallow_a_failed_storage_link(): void
    {
        $entrypoint = $this->repoFile('docker/entrypoint.sh');

        $this->assertStringContainsString('if [ ! -L public/storage ] && [ ! -e public/storage ]; then', $entrypoint);
        $this->assertStringContainsString('if ! php artisan storage:link; then', $entrypoint);
        $this->assertStringContainsString('WARNING: public/storage is missing', $entrypoint);
        $this->assertDoesNotMatchRegularExpression('/storage:link[^\n]*(\/dev\/null|\|\| true)/', $entrypoint);
    }
}
