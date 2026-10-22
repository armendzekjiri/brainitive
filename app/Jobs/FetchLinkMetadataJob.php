<?php

namespace App\Jobs;

use App\Models\BookmarkItem;
use App\Support\OutboundUrl;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class FetchLinkMetadataJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $bookmarkItemId
    ) {}

    public function handle(): void
    {
        $item = BookmarkItem::find($this->bookmarkItemId);
        if (! $item || $item->type !== 'link' || ! $item->url) {
            return;
        }

        $url = $item->url;
        if (! Str::startsWith($url, ['http://', 'https://'])) {
            $url = 'https://'.$url;
        }

        try {
            $response = $this->fetch($url);

            if (! $response?->successful()) {
                return;
            }

            $html = $response->body();
            $title = $this->extractTitle($html);
            $faviconUrl = $this->extractFavicon($html, $url);
            $description = $this->extractDescription($html);

            $update = [];
            if ($title !== null && $title !== '') {
                $update['title'] = Str::limit($title, 500);
            }
            $metadata = $item->metadata ?? [];
            if ($faviconUrl !== null) {
                $metadata['favicon_url'] = $faviconUrl;
            }
            if ($description !== null && $description !== '') {
                $metadata['description'] = Str::limit($description, 500);
            }
            if (! empty($metadata)) {
                $update['metadata'] = $metadata;
            }

            if (! empty($update)) {
                $item->update($update);
            }
        } catch (\Throwable) {
            // Ignore failures (timeout, SSL, etc.)
        }
    }

    /**
     * GET a user-supplied URL: public addresses only, redirects followed by hand (each hop
     * checked again), at most 2 MB.
     */
    private function fetch(string $url): ?Response
    {
        for ($hop = 0; $hop <= 3; $hop++) {
            $options = OutboundUrl::options($url);
            if ($options === null) {
                return null;
            }

            $response = Http::timeout(10)
                ->connectTimeout(5)
                ->withOptions($options + ['on_headers' => function ($headers) {
                    if ((int) $headers->getHeaderLine('Content-Length') > 2_000_000) {
                        throw new \RuntimeException('Response too large');
                    }
                }])
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; Brainitive/1.0)'])
                ->get($url);

            if (! $response->redirect()) {
                return $response;
            }

            $location = $response->header('Location');
            if ($location === '') {
                return null;
            }
            $url = $this->resolveUrl($location, $this->baseUrl($url));
        }

        return null;
    }

    private function extractTitle(string $html): ?string
    {
        if (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $html, $m)) {
            return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return null;
    }

    private function extractDescription(string $html): ?string
    {
        // og:description (Open Graph)
        if (preg_match('/<meta[^>]+property=(["\'])og:description\1[^>]+content=(["\'])([^"\']*)\2/i', $html, $m)) {
            $s = trim(html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($s !== '') {
                return $s;
            }
        }
        if (preg_match('/<meta[^>]+content=(["\'])([^"\']*)\1[^>]+property=(["\'])og:description\3/i', $html, $m)) {
            $s = trim(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($s !== '') {
                return $s;
            }
        }
        // meta name="description"
        if (preg_match('/<meta[^>]+name=(["\'])description\1[^>]+content=(["\'])([^"\']*)\2/i', $html, $m)) {
            $s = trim(html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($s !== '') {
                return $s;
            }
        }
        if (preg_match('/<meta[^>]+content=(["\'])([^"\']*)\1[^>]+name=(["\'])description\3/i', $html, $m)) {
            $s = trim(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($s !== '') {
                return $s;
            }
        }
        // First paragraph as fallback
        if (preg_match('/<p[^>]*>([^<]+)<\/p>/i', $html, $m)) {
            $s = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($s !== '' && strlen($s) > 20) {
                return $s;
            }
        }

        return null;
    }

    private function extractFavicon(string $html, string $pageUrl): ?string
    {
        $base = $this->baseUrl($pageUrl);

        // Prefer link rel="icon" or rel="shortcut icon"
        if (preg_match('/<link[^>]+rel=(["\'])(?:.*?\s+)?(?:icon|shortcut\s+icon)(?:\s+.*?)?\1[^>]+href=(["\'])([^"\']+)\2/i', $html, $m)) {
            return $this->resolveUrl(trim($m[3]), $base);
        }
        if (preg_match('/<link[^>]+href=(["\'])([^"\']+)\1[^>]+rel=(["\'])(?:.*?\s+)?(?:icon|shortcut\s+icon)/i', $html, $m)) {
            return $this->resolveUrl(trim($m[2]), $base);
        }

        // Fallback: Google favicon service
        $host = parse_url($pageUrl, PHP_URL_HOST);
        if ($host) {
            return 'https://www.google.com/s2/favicons?sz=32&domain='.urlencode($host);
        }

        return null;
    }

    private function baseUrl(string $url): string
    {
        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $scheme.'://'.$host.$port.'/';
    }

    private function resolveUrl(string $href, string $base): string
    {
        $href = trim($href);
        if (Str::startsWith($href, ['http://', 'https://'])) {
            return $href;
        }
        if (Str::startsWith($href, '//')) {
            return 'https:'.$href;
        }
        if (Str::startsWith($href, '/')) {
            $parsed = parse_url($base);

            return ($parsed['scheme'] ?? 'https').'://'.($parsed['host'] ?? '').$href;
        }

        return rtrim($base, '/').'/'.ltrim($href, '/');
    }
}
