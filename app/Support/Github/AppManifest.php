<?php

namespace App\Support\Github;

/**
 * The GitHub App manifest an instance admin posts to github.com to create the instance's app in
 * one click (BRAI-61). Read-only permissions; `installation` / `installation_repositories` are
 * always delivered to apps, so they aren't listed as events.
 */
final class AppManifest
{
    public const PERMISSIONS = [
        'metadata' => 'read',
        'contents' => 'read', // commits on push
        'issues' => 'read',
        'pull_requests' => 'read',
        'deployments' => 'read',
    ];

    public const EVENTS = ['push', 'create', 'delete', 'pull_request', 'deployment_status'];

    /** @return array<string, mixed> */
    public static function build(string $name, bool $public): array
    {
        $callback = route('github.app.setup');

        return [
            'name' => $name,
            'url' => rtrim((string) config('app.url'), '/') ?: url('/'),
            'description' => 'Links branches, commits, pull requests and deployments to '.config('app.name').' issues.',
            'hook_attributes' => ['url' => route('webhooks.github.app'), 'active' => true],
            'redirect_url' => route('admin.github-app.callback'),
            // "Request user authorization (OAuth) during installation": GitHub sends the person back to
            // the callback with a code, which proves the installation is theirs; it also does the
            // setup URL's job, so the manifest sets no setup_url.
            'callback_urls' => [$callback],
            'request_oauth_on_install' => true,
            'public' => $public,
            'default_permissions' => self::PERMISSIONS,
            'default_events' => self::EVENTS,
        ];
    }

    /** Where the admin's browser posts the manifest (a personal account or an organization). */
    public static function postUrl(?string $organization, string $state): string
    {
        $base = $organization
            ? 'https://github.com/organizations/'.rawurlencode($organization).'/settings/apps/new'
            : 'https://github.com/settings/apps/new';

        return $base.'?state='.rawurlencode($state);
    }
}
