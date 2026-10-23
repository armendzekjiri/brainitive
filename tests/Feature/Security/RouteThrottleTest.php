<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Laravel's plain `throttle:N,M` keys its counter on the IP (or user) alone — one counter shared
 * by every route that uses it. A visitor who opened a lead form a few times was then refused on
 * their first submit (found by the e2e suite, BRAI-96). Every throttle names its own counter.
 */
class RouteThrottleTest extends TestCase
{
    public function test_every_throttle_has_its_own_counter(): void
    {
        $shared = [];
        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && preg_match('/^throttle:\d+,\d+$/', $middleware)) {
                    $shared[] = $route->uri().' ('.$middleware.')';
                }
            }
        }

        $this->assertSame([], $shared, 'throttle without a prefix (add ",<route name>"): '.implode(', ', $shared));
    }

    public function test_opening_a_form_page_does_not_use_up_the_submit_limit(): void
    {
        foreach (range(1, 12) as $i) {
            $this->get('/f/no-such-form')->assertNotFound();
        }

        // the submit limit (10 / minute) is its own: still reachable, not 429
        $this->post('/f/no-such-form/submit')->assertNotFound();
    }
}
