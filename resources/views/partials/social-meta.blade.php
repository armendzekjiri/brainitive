{{--
    Link previews (Open Graph + X cards), v1.39.0 BRAI-109. Server-rendered: crawlers (Facebook,
    LinkedIn, X, Slack, WhatsApp…) don't run JavaScript, so Inertia's <Head> can't provide these.
    A page passes `meta` (title, description, url) with ->withViewData(); every other page gets the
    site-wide defaults — never a page's own title, so private boards/docs don't leak into previews.
--}}
@php
    $meta = $meta ?? [];
    $siteName = config('app.name', 'Brainitive');
    $metaTitle = $meta['title'] ?? $siteName;
    $metaDescription = $meta['description'] ?? 'Boards, sprints, docs, whiteboards and Gantt plans — one workspace for the whole project.';
    $metaImage = asset('images/og-image.png').'?v='.(@filemtime(public_path('images/og-image.png')) ?: '1');
@endphp
<meta name="description" content="{{ $metaDescription }}">
@isset($meta['url'])
    <link rel="canonical" href="{{ $meta['url'] }}">
    <meta property="og:url" content="{{ $meta['url'] }}">
@endisset
<meta property="og:type" content="website">
<meta property="og:site_name" content="{{ $siteName }}">
<meta property="og:title" content="{{ $metaTitle }}">
<meta property="og:description" content="{{ $metaDescription }}">
<meta property="og:image" content="{{ $metaImage }}">
<meta property="og:image:type" content="image/png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="{{ $siteName }}: boards, docs and plans in one workspace">
<meta property="og:locale" content="en_US">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $metaTitle }}">
<meta name="twitter:description" content="{{ $metaDescription }}">
<meta name="twitter:image" content="{{ $metaImage }}">
<meta name="twitter:image:alt" content="{{ $siteName }}: boards, docs and plans in one workspace">
