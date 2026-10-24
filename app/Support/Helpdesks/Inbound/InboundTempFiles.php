<?php

namespace App\Support\Helpdesks\Inbound;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Temp folders for attachments of received mail (BRAI-124): one per message under
 * storage/app/inbound-mail/, written by the webhook, read by InboundMail::receive() (which moves what
 * it keeps), deleted by ProcessInboundMail afterwards. Not the system temp dir: a queue worker
 * on another process (or after a restart) must still find the files.
 */
final class InboundTempFiles
{
    public static function root(): string
    {
        return storage_path('app/inbound-mail');
    }

    public static function newDir(): string
    {
        $dir = self::root().DIRECTORY_SEPARATOR.Str::uuid();
        File::ensureDirectoryExists($dir, 0700);

        return $dir;
    }

    /** Deletes a folder made by newDir(); anything outside the root is left alone. */
    public static function delete(?string $dir): void
    {
        if ($dir === null || ! is_dir($dir)) {
            return;
        }
        $root = realpath(self::root());
        $real = realpath($dir);
        if ($root === false || $real === false || ! str_starts_with($real, $root.DIRECTORY_SEPARATOR)) {
            return;
        }
        File::deleteDirectory($real);
    }
}
