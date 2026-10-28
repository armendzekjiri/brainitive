<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="light">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title inertia>{{ $meta['title'] ?? config('app.name', 'Laravel') }}</title>

        <!-- Link previews: Open Graph + X cards (server-rendered, crawlers don't run JS) -->
        @include('partials.social-meta')

        <!-- PWA: installable on mobile and desktop -->
        <meta name="theme-color" content="#0E83DC">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">
        <meta name="apple-mobile-web-app-title" content="{{ config('app.name') }}">

        <!-- Favicons & PWA -->
        <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
        <link rel="apple-touch-icon" sizes="180x180" href="{{ file_exists(public_path('apple-touch-icon.png')) ? asset('apple-touch-icon.png') : asset('images/logo.png') }}">
        <link rel="manifest" href="{{ route('manifest') }}">

        <!-- Preload LCP image (logo) so it is discoverable immediately -->
        <link rel="preload" href="{{ asset('images/logo.png') }}" as="image">

        <!-- Prevent flash of wrong theme: apply stored preference (or system pref) before first paint -->
        <script>
            (function() {
                try {
                    var stored = localStorage.getItem('brainitive-theme');
                    var theme = (stored === 'light' || stored === 'dark' || stored === 'system') ? stored : 'system';
                    var resolved = theme;
                    if (theme === 'system') {
                        resolved = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
                    }
                    var root = document.documentElement;
                    if (resolved === 'dark') {
                        root.classList.add('dark');
                        root.classList.remove('light');
                    } else {
                        root.classList.add('light');
                        root.classList.remove('dark');
                    }
                    root.style.colorScheme = resolved;
                } catch(e) {}
            })();
        </script>

        <!-- Excalidraw fonts from our own build, not its third-party CDN fallback (BRAI-156, vite.config.js) -->
        <script>window.EXCALIDRAW_ASSET_PATH = @json(asset('build/excalidraw-assets').'/');</script>

        <!-- Analytics: the operator's self-hosted, cookieless Umami — ONLY with the visitor's consent (§ 25 (1) TDDDG).
             No tracker here: where analytics may run (brainitive.app, never dev / e2e / local / self-hosted installs,
             customers' public pages or token links — Analytics::enabledFor) the browser gets this config, asks
             (components/legal/AnalyticsConsentNotice) and loads the script only after "Allow" (lib/analytics). -->
        @if (\App\Support\Legal\Analytics::enabledFor(request()))
            <script>window.brainitiveAnalytics = @json(\App\Support\Legal\Analytics::clientConfig());</script>
        @endif

        <!-- Scripts -->
        @routes
        @viteReactRefresh
        @vite(['resources/js/app.tsx', "resources/js/Pages/{$page['component']}.tsx"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
