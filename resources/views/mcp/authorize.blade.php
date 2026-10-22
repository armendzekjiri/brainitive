<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Authorize {{ $client->name }} — {{ config('app.name', 'Brainitive') }}</title>

    <link rel="icon" type="image/svg+xml" href="/favicon.svg" />
    <link rel="shortcut icon" href="/favicon.ico" />

    {{-- Self-contained styles so this OAuth consent screen renders independently
         of the SPA's Vite build (it is not part of the React app). --}}
    <style>
        :root {
            --bg: #f5f5f4; --card: #ffffff; --text: #1c1917; --muted: #78716c;
            --border: #e7e5e4; --primary: #4f46e5; --primary-hover: #4338ca;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0c0a09; --card: #1c1917; --text: #fafaf9; --muted: #a8a29e;
                --border: #292524; --primary: #6366f1; --primary-hover: #818cf8;
            }
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            background: var(--bg); color: var(--text); padding: 1rem;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }
        .card {
            width: 100%; max-width: 28rem; background: var(--card); border: 1px solid var(--border);
            border-radius: 0.875rem; box-shadow: 0 10px 30px rgba(0,0,0,.08); overflow: hidden;
        }
        .card-body { padding: 2rem; }
        .icon-wrap {
            width: 3.5rem; height: 3.5rem; margin: 0 auto 1.25rem; border-radius: 9999px;
            background: color-mix(in srgb, var(--primary) 12%, transparent);
            display: flex; align-items: center; justify-content: center; color: var(--primary);
        }
        h1 { font-size: 1.35rem; line-height: 1.3; text-align: center; margin: 0 0 .6rem; font-weight: 650; }
        .lead { font-size: .9rem; color: var(--muted); text-align: center; margin: 0 0 1.5rem; line-height: 1.5; }
        .lead strong { color: var(--text); }
        .panel { border: 1px solid var(--border); border-radius: .625rem; padding: .9rem 1rem; margin-bottom: 1.25rem; }
        .panel .label { font-size: .75rem; color: var(--muted); margin: 0 0 .25rem; }
        .panel .value { font-size: .9rem; font-weight: 600; margin: 0; word-break: break-all; }
        .perm-title { font-size: .8rem; font-weight: 600; margin: 0 0 .6rem; }
        ul.perms { list-style: none; margin: 0 0 .5rem; padding: 0; }
        ul.perms li { display: flex; gap: .6rem; align-items: flex-start; font-size: .85rem; color: var(--muted); margin-bottom: .5rem; }
        ul.perms li .dot { margin-top: .45rem; width: .4rem; height: .4rem; border-radius: 9999px; background: var(--primary); flex: none; }
        .actions { display: flex; gap: .75rem; padding: 0 2rem 2rem; }
        .actions form { flex: 1; margin: 0; }
        button {
            width: 100%; height: 2.6rem; border-radius: .55rem; font-size: .9rem; font-weight: 600;
            cursor: pointer; border: 1px solid transparent; display: inline-flex; align-items: center; justify-content: center; gap: .4rem;
        }
        .btn-secondary { background: transparent; border-color: var(--border); color: var(--text); }
        .btn-secondary:hover { background: var(--bg); }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-primary:hover { background: var(--primary-hover); }
        button[disabled] { opacity: .6; cursor: default; }
        .spinner { width: 1rem; height: 1rem; border: 2px solid rgba(255,255,255,.4); border-top-color: #fff; border-radius: 9999px; animation: spin .7s linear infinite; display: none; }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <div class="card">
        <div class="card-body">
            <div class="icon-wrap">
                <svg width="28" height="28" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.618 5.984A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.031 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                </svg>
            </div>

            <h1>Connect {{ $client->name }} to {{ config('app.name', 'Brainitive') }}</h1>

            @php($captureOnly = collect($scopes)->pluck('id')->all() === ['capture'])
            <p class="lead">
                @if($captureOnly)
                    <strong>{{ $client->name }}</strong> will be able to save pages, links, notes and
                    screenshots to {{ config('app.name', 'Brainitive') }}, see your assigned work and search
                    titles. It can't read the content of your documents, and only reaches what your
                    account can.
                @else
                    <strong>{{ $client->name }}</strong> will be able to read and write your
                    {{ config('app.name', 'Brainitive') }} content — pages, tasks, boards, snippets and
                    bookmarks — on your behalf. It can only access what your account can access.
                @endif
            </p>

            <div class="panel">
                <p class="label">Signed in as</p>
                <p class="value">{{ $user->email }}</p>
            </div>

            @if(count($scopes) > 0)
                <p class="perm-title">This will allow it to:</p>
                <ul class="perms">
                    @foreach($scopes as $scope)
                        <li><span class="dot"></span><span>{{ $scope->description }}</span></li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div class="actions">
            <form method="POST" action="{{ route('passport.authorizations.deny') }}">
                @csrf
                @method('DELETE')
                <input type="hidden" name="state" value="">
                <input type="hidden" name="client_id" value="{{ $client->id }}">
                <input type="hidden" name="auth_token" value="{{ $authToken }}">
                <button type="submit" class="btn-secondary">Cancel</button>
            </form>

            <form method="POST" action="{{ route('passport.authorizations.approve') }}" id="authorizeForm">
                @csrf
                <input type="hidden" name="state" value="">
                <input type="hidden" name="client_id" value="{{ $client->id }}">
                <input type="hidden" name="auth_token" value="{{ $authToken }}">
                <button type="submit" class="btn-primary" id="authorizeButton">
                    <span id="authorizeText">Authorize</span>
                    <span class="spinner" id="loadingSpinner"></span>
                </button>
            </form>
        </div>
    </div>

    <script>
        document.getElementById('authorizeForm').addEventListener('submit', function () {
            var btn = document.getElementById('authorizeButton');
            btn.disabled = true;
            document.getElementById('authorizeText').textContent = 'Authorizing…';
            document.getElementById('loadingSpinner').style.display = 'inline-block';
        });
    </script>
</body>
</html>
