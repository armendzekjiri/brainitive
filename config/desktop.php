<?php

/*
 * Brainitive Desktop downloads, shown on /download. Empty = "Coming soon" (disabled button).
 * Once builds are published, only these environment values are filled in.
 */
return [
    'downloads' => [
        'macos' => env('DESKTOP_DOWNLOAD_MACOS_URL'),
        'windows' => env('DESKTOP_DOWNLOAD_WINDOWS_URL'),
        'linux' => env('DESKTOP_DOWNLOAD_LINUX_URL'),
    ],
];
