<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'SubSorter')</title>
    <style>
        :root {
            --bg: #f6f6f7; --card: #fff; --text: #1d1d20; --muted: #6b6b75; --border: #e2e2e6;
            --accent: #c4302b; --accent-hover: #a82722; --ok-bg: #e7f6ec; --ok: #1d6b3a; --err-bg: #fdecec; --err: #9b1c1c;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #141416; --card: #1e1e22; --text: #ececf0; --muted: #9a9aa5; --border: #2f2f35;
                --ok-bg: #16301f; --ok: #8fd9a8; --err-bg: #3a1a1a; --err: #f3a5a5;
            }
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
        main { max-width: 900px; margin: 0 auto; padding: 24px 16px 64px; }
        nav { display: flex; align-items: baseline; gap: 18px; margin-bottom: 20px; }
        nav h1 { font-size: 22px; margin: 0 8px 0 0; }
        nav a { color: var(--muted); text-decoration: none; }
        nav a.active, nav a:hover { color: var(--text); }
        h2 { font-size: 16px; margin: 0 0 12px; }
        .card { background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 18px; margin-bottom: 16px; }
        .row { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
        .row + .row { margin-top: 12px; }
        .spacer { flex: 1; }
        .muted { color: var(--muted); }
        .stat { font-variant-numeric: tabular-nums; font-weight: 600; }
        button, .button {
            font: inherit; border: 1px solid var(--border); background: var(--card); color: var(--text);
            padding: 7px 14px; border-radius: 7px; cursor: pointer; text-decoration: none; display: inline-block;
        }
        button:hover, .button:hover { border-color: var(--muted); }
        button.primary, .button.primary { background: var(--accent); border-color: var(--accent); color: #fff; }
        button.primary:hover, .button.primary:hover { background: var(--accent-hover); }
        button.link { border: 0; background: none; padding: 0 4px; color: var(--muted); }
        button:disabled { opacity: .5; cursor: wait; }
        input, select { font: inherit; padding: 6px 10px; border: 1px solid var(--border); border-radius: 7px; background: var(--card); color: var(--text); max-width: 100%; }
        .flash { padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; }
        .flash.ok { background: var(--ok-bg); color: var(--ok); }
        .flash.err { background: var(--err-bg); color: var(--err); }
        .error { color: var(--err); font-size: 13px; }
        a { color: inherit; }
        details summary { cursor: pointer; }
        .avatar { width: 28px; height: 28px; border-radius: 50%; flex: none; object-fit: cover; vertical-align: middle; }
        .avatar.placeholder { display: inline-flex; align-items: center; justify-content: center; background: var(--border); color: var(--muted); font-size: 13px; font-weight: 600; }
        /* Playlist colors: set --h (hue) on an element */
        :root { --pl-l: 45%; }
        @media (prefers-color-scheme: dark) { :root { --pl-l: 62%; } }
        .dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%; flex: none; background: hsl(var(--h) 65% var(--pl-l)); }
        .channel { display: inline-flex; align-items: center; gap: 10px; min-width: 0; }
        form { margin: 0; }
        footer { text-align: center; padding: 0 16px 24px; font-size: 13px; }
        footer a { color: var(--muted); text-decoration: none; }
        footer a:hover { color: var(--text); text-decoration: underline; }
        @yield('styles')
    </style>
</head>
<body>
<main>
    <nav>
        <h1>SubSorter</h1>
        <a href="{{ route('dashboard') }}" @class(['active' => request()->routeIs('dashboard')])>Videos</a>
        <a href="{{ route('channels.index') }}" @class(['active' => request()->routeIs('channels.*')])>Channels</a>
    </nav>

    @if (session('status'))
        <div class="flash ok">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="flash err">{{ session('error') }}</div>
    @endif
    @if ($errors->any())
        <div class="flash err">{{ $errors->first() }}</div>
    @endif

    @yield('content')
</main>

<footer>
    <a href="https://github.com/FeaXR" target="_blank" rel="noopener">Made by Fixr</a>
</footer>

<script>
    // Long-running actions: disable all buttons and show what's happening
    document.querySelectorAll('form[data-busy]').forEach(form => {
        form.addEventListener('submit', () => {
            document.querySelectorAll('button').forEach(b => b.disabled = true);
            form.querySelector('button').textContent = form.dataset.busy;
        });
    });
</script>
@yield('scripts')
</body>
</html>
