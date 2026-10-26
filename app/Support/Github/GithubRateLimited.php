<?php

namespace App\Support\Github;

use Illuminate\Support\Carbon;

/** GitHub's rate limit was hit (403 / 429); try again after `$retryAt`. */
class GithubRateLimited extends GithubApiException
{
    public function __construct(public readonly Carbon $retryAt)
    {
        parent::__construct('GitHub rate limit reached; retry after '.$retryAt->toIso8601String().'.', 429);
    }
}
