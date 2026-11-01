<?php

namespace Tests\Feature\AppApi\Concerns;

/**
 * In production every request starts with fresh guards. In a test the app (and the `app` request
 * guard with the user it resolved) lives on between requests, so a revoked token would still pass
 * and the second request would have no device on it. Forget the guards before each request.
 * Don't combine with actingAs(): that user is forgotten too.
 */
trait ForgetsGuardsBetweenRequests
{
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }
}
