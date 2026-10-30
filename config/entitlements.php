<?php

/*
 * Plan limits (Brainitive Cloud, v1.46.0). Self-hosted installs keep the "unlimited" driver: no
 * limits, no billing. The private brainitive/cloud package binds its own Entitlements before the
 * app does, so this driver is ignored there. "config" applies the fixed limits below (e2e, testing).
 */
return [
    'driver' => env('ENTITLEMENTS_DRIVER', 'unlimited'),

    'config' => [
        'enforced' => env('ENTITLEMENTS_ENFORCED', true),
        // limits apply only to workspaces whose slug starts with this ('' = every workspace)
        'only_slug_prefix' => env('ENTITLEMENTS_ONLY_SLUG_PREFIX', ''),
        'plan' => env('ENTITLEMENTS_PLAN', 'Free'),
        'members' => env('ENTITLEMENTS_MEMBERS'),
        'projects' => env('ENTITLEMENTS_PROJECTS'),
        'content_bytes' => env('ENTITLEMENTS_CONTENT_BYTES'),
        'file_bytes' => env('ENTITLEMENTS_FILE_BYTES'),
        'versions_keep_latest' => env('ENTITLEMENTS_VERSIONS_KEEP_LATEST', 0),
        'versions_keep_days' => env('ENTITLEMENTS_VERSIONS_KEEP_DAYS'),
        'free_workspaces_per_owner' => env('ENTITLEMENTS_FREE_WORKSPACES_PER_OWNER'),
        'billing_url' => env('ENTITLEMENTS_BILLING_URL'),
    ],
];
