<?php

namespace App\Support\Calendar;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

/**
 * A response sink that refuses to grow past $max bytes (SafeRemoteUrl). curl writes through
 * write(); returning 0 there makes curl abort the transfer at once — a chunked response without
 * Content-Length can't keep downloading until the timeout.
 */
final class CappedStream implements StreamInterface
{
    use StreamDecoratorTrait;

    public bool $exceeded = false;

    private int $written = 0;

    public function __construct(private int $max)
    {
        $this->stream = Utils::streamFor(Utils::tryFopen('php://temp', 'r+'));
    }

    public function write($string): int
    {
        if ($this->written + strlen($string) > $this->max) {
            $this->exceeded = true;

            return 0;
        }
        $this->written += strlen($string);

        return $this->stream->write($string);
    }
}
