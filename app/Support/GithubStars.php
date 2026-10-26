<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The open-source repository's star count for the "Star on GitHub" buttons (v1.42.0).
 *
 * Pages only read the cache. It is refreshed hourly by the scheduler and, when it is empty, once
 * after the response (so dev without a scheduler still gets a number). Visitors' browsers never
 * talk to GitHub. Starring itself happens on github.com: GitHub only lets a site star a repository
 * with a token that can write to all of the visitor's public repositories.
 */
final class GithubStars
{
    public const CACHE_KEY = 'github:repo-stars';

    private const TTL = 2 * 3600; // two refresh periods: a missed hourly run keeps the old number

    private const FAILED_TTL = 600;

    public static function repository(): string
    {
        return (string) config('services.github.repository');
    }

    public static function url(): string
    {
        return 'https://github.com/'.self::repository();
    }

    /** @return array{url: string, stars: int|null} the shared `githubRepo` prop */
    public static function shared(): array
    {
        return ['url' => self::url(), 'stars' => self::cached()];
    }

    public static function cached(): ?int
    {
        $value = Cache::get(self::CACHE_KEY);

        if ($value === null && self::fetchingAllowed() && Cache::add(self::CACHE_KEY.':refreshing', true, 60)) {
            dispatch(fn () => GithubStars::refresh())->afterResponse();
        }

        return is_int($value) && $value >= 0 ? $value : null;
    }

    /** Ask GitHub (public API, no token). A failure is remembered for 10 minutes as "no number". */
    public static function refresh(): ?int
    {
        if (! self::fetchingAllowed()) {
            return null;
        }

        try {
            $response = Http::timeout(4)->acceptJson()
                ->withHeaders(['User-Agent' => config('app.name', 'Brainitive')])
                ->get('https://api.github.com/repos/'.self::repository());
            $stars = $response->successful() ? $response->json('stargazers_count') : null;
        } catch (Throwable) {
            $stars = null;
        }

        if (! is_int($stars)) {
            Cache::put(self::CACHE_KEY, -1, self::FAILED_TTL);

            return null;
        }

        Cache::put(self::CACHE_KEY, $stars, self::TTL);

        return $stars;
    }

    /** Off in PHPUnit (phpunit.xml) and on the e2e server; self-hosters can turn it off (GITHUB_STARS=false). */
    private static function fetchingAllowed(): bool
    {
        return (bool) config('services.github.show_stars')
            && self::repository() !== ''
            && ! app()->environment('e2e');
    }
}
