<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @yield('head')
    <title>Plex Recommender</title>
    <style>
        :root { --bg:#f5f5f4; --card:#fff; --text:#1c1917; --muted:#78716c; --line:#e7e5e4; --plex:#e5a00d; --plex-dark:#cc8b00; --ok:#15803d; --err:#b91c1c; }
        @media (prefers-color-scheme: dark) { :root { --bg:#1c1917; --card:#292524; --text:#f5f5f4; --muted:#a8a29e; --line:#44403c; } }
        * { box-sizing: border-box; }
        body { margin:0; min-height:100vh; display:grid; place-items:center; padding:16px; background:var(--bg); color:var(--text); font:16px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
        .card { width:100%; max-width:440px; background:var(--card); border:1px solid var(--line); border-radius:16px; padding:32px; }
        h1 { margin:0 0 4px; font-size:22px; }
        p { margin:0 0 16px; color:var(--muted); }
        .btn { display:inline-flex; align-items:center; justify-content:center; gap:10px; width:100%; padding:12px 16px; border:0; border-radius:10px; font:600 16px system-ui, sans-serif; cursor:pointer; }
        .btn-plex { background:var(--plex); color:#1c1917; }
        .btn-plex:hover { background:var(--plex-dark); }
        .btn-ghost { background:transparent; color:var(--muted); border:1px solid var(--line); margin-top:12px; }
        .msg { padding:10px 12px; border-radius:8px; margin-bottom:16px; font-size:14px; }
        .msg-ok { background:color-mix(in srgb, var(--ok) 12%, transparent); color:var(--ok); }
        .msg-err { background:color-mix(in srgb, var(--err) 12%, transparent); color:var(--err); }
        dl { display:grid; grid-template-columns:auto 1fr; gap:6px 16px; margin:0 0 20px; font-size:14px; }
        dt { color:var(--muted); } dd { margin:0; word-break:break-all; }
        .server { display:flex; justify-content:space-between; align-items:center; width:100%; padding:12px 14px; margin-bottom:8px; background:transparent; color:var(--text); border:1px solid var(--line); border-radius:10px; font:15px system-ui, sans-serif; cursor:pointer; text-align:left; }
        .server:hover { border-color:var(--plex); }
        .tag { font-size:12px; color:var(--muted); }
        code { font-size:13px; background:var(--bg); padding:2px 6px; border-radius:6px; }
    </style>
</head>
<body>
<main class="card">
    @if (session('status')) <div class="msg msg-ok">{{ session('status') }}</div> @endif
    @if (session('error')) <div class="msg msg-err">{{ session('error') }}</div> @endif
    @yield('content')
</main>
</body>
</html>
