<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="light">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

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

        <!-- Scripts -->
        @routes
        @viteReactRefresh
        @vite(['resources/js/app.tsx', "resources/js/Pages/{$page['component']}.tsx"])
        @inertiaHead

        <!-- Analytics (Nova Brain Hub): brainitive.app only, never dev / e2e / local / self-hosted installs -->
        @if (in_array(request()->getHost(), ['brainitive.app', 'www.brainitive.app'], true))
            <script defer src="https://stats.novabrainhub.de/script.js" data-website-id="e6d93341-8b10-4d6a-8629-b9599635909e"></script>
        @endif
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
