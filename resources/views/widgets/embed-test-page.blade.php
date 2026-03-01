<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Embed test – {{ $helpdesk->name }}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 20px; background: #f4f6f8; }
        h1 { font-size: 18px; color: #333; }
        p { color: #666; font-size: 14px; }
        .frame-wrap { background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); overflow: hidden; margin-top: 16px; }
        iframe { display: block; border: 0; }
    </style>
</head>
<body>
    <h1>Embed test: {{ $helpdesk->name }}</h1>
    <p>This page is served from the same origin as the iframe. If you see the form below, the embed works.</p>
    <div class="frame-wrap">
        <iframe src="{{ $embed_url }}" width="100%" height="500" title="Contact support"></iframe>
    </div>
</body>
</html>
