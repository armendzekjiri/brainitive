<?php

namespace Tests\Feature;

use App\Support\GithubStars;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** v1.42.0: the "Star on GitHub" buttons read a cached star count; pages never call GitHub. */
class GithubStarsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_hourly_refresh_caches_the_star_count_and_pages_share_it(): void
    {
        config(['services.github.show_stars' => true, 'services.github.repository' => 'acme/brainitive']);
        Http::fake(['api.github.com/repos/acme/brainitive' => Http::response(['stargazers_count' => 1234])]);

        $this->assertSame(1234, GithubStars::refresh());
        $this->assertSame(1234, GithubStars::cached());

        $this->withoutVite()->get('/')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('githubRepo.url', 'https://github.com/acme/brainitive')
            ->where('githubRepo.stars', 1234));
    }

    public function test_a_failed_refresh_shows_no_number_and_is_not_retried_at_once(): void
    {
        config(['services.github.show_stars' => true]);
        Http::fake(['api.github.com/*' => Http::response(['message' => 'API rate limit exceeded'], 403)]);

        $this->assertNull(GithubStars::refresh());
        $this->assertNull(GithubStars::cached());
        $this->assertSame(-1, Cache::get(GithubStars::CACHE_KEY)); // remembered for 10 minutes
        Http::assertSentCount(1);
    }

    public function test_nothing_is_fetched_when_turned_off(): void
    {
        Http::fake();
        config(['services.github.show_stars' => false]);

        $this->assertNull(GithubStars::refresh());
        $this->withoutVite()->get('/')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('githubRepo.stars', null));

        Http::assertNothingSent();
    }
}
