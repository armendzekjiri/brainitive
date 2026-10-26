<?php

namespace App\Support\Github;

use RuntimeException;

/**
 * A GitHub API call failed. The message names the endpoint and status only — never a token,
 * a code or a secret.
 */
class GithubApiException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0)
    {
        parent::__construct($message, $status);
    }

    /** 404 on the installation itself: the app was uninstalled (401 = bad app credentials instead). */
    public function accessLost(): bool
    {
        return $this->status === 404;
    }
}
