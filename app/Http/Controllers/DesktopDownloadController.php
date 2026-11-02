<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/** Public download page of Brainitive Desktop; the release URLs come from config/desktop.php. */
class DesktopDownloadController extends Controller
{
    public function __invoke(): Response
    {
        $downloads = [];
        foreach (['macos', 'windows', 'linux'] as $platform) {
            $url = config("desktop.downloads.$platform");
            // only absolute http(s) addresses become a button
            $downloads[$platform] = is_string($url) && preg_match('#^https?://#i', $url) ? $url : null;
        }

        return Inertia::render('Download', ['downloads' => $downloads])->withViewData(['meta' => [
            'title' => 'Download Brainitive Desktop',
            'description' => 'Quick capture with a global shortcut, My Day and the timer in your menu bar. Brainitive Desktop for macOS, Windows and Linux, with Brainitive Cloud or your own server.',
            'url' => url('/download'),
        ]]);
    }
}
