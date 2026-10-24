<?php

namespace App\Support\Helpdesks\Inbound;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Writes the attachments of one received message (BRAI-124) into its temp folder
 * (InboundTempFiles) under random names, within config('services.inbound_mail') limits: per file,
 * count and total. What doesn't fit is dropped and counted in the log. Which types a ticket keeps
 * is InboundMail::receive()'s decision (the ticket attachment rules), not this class's.
 */
final class AttachmentWriter
{
    /** @var list<array{filename: string, mime: string, size: int, path: string}> */
    private array $written = [];

    private int $total = 0;

    private int $dropped = 0;

    public function __construct(private readonly string $dir) {}

    public function addBase64(string $name, string $mime, string $base64): void
    {
        // the decoded size is about 3/4 of the base64 length: refuse a huge one before decoding it
        if (! $this->fits(max(0, intdiv(strlen($base64) * 3, 4) - 2))) {
            $this->dropped++;

            return;
        }
        $bytes = base64_decode($base64, true);
        if ($bytes === false || $bytes === '' || ! $this->fits(strlen($bytes))) {
            $this->dropped++;

            return;
        }
        $path = $this->path();
        file_put_contents($path, $bytes);
        $this->keep($name, $mime, strlen($bytes), $path);
    }

    public function addUpload(UploadedFile $file): void
    {
        $size = (int) $file->getSize();
        if (! $file->isValid() || $size <= 0 || ! $this->fits($size)) {
            $this->dropped++;

            return;
        }
        $path = $this->path();
        $file->move(dirname($path), basename($path));
        $this->keep($file->getClientOriginalName(), (string) $file->getClientMimeType(), $size, $path);
    }

    /** @return list<array{filename: string, mime: string, size: int, path: string}> */
    public function finish(string $provider): array
    {
        if ($this->dropped > 0) {
            Log::info('Inbound mail: attachments dropped (limits or unreadable)', ['provider' => $provider, 'dropped' => $this->dropped]);
        }

        return $this->written;
    }

    public function dropped(): int
    {
        return $this->dropped;
    }

    private function fits(int $size): bool
    {
        $config = (array) config('services.inbound_mail');

        return $size <= (int) ($config['max_attachment_kb'] ?? 10240) * 1024
            && count($this->written) < (int) ($config['max_attachments'] ?? 10)
            && $this->total + $size <= (int) ($config['max_total_kb'] ?? 30720) * 1024;
    }

    private function path(): string
    {
        return $this->dir.DIRECTORY_SEPARATOR.Str::random(40);
    }

    private function keep(string $name, string $mime, int $size, string $path): void
    {
        $this->total += $size;
        $this->written[] = [
            'filename' => InboundPayloads::filename($name),
            'mime' => InboundPayloads::mime($mime),
            'size' => $size,
            'path' => $path,
        ];
    }
}
