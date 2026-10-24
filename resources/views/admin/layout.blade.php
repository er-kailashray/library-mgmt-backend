<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Admin') · Librum</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #FDFCF9; --fg: #1C1917; --muted: #78716C; --card: #FFFFFF; --border: #E7E5E4; --soft: #F4F4F0;
            --accent: #E26D4D; --accent-soft: #E26D4D1A; --success: #10B981; --warning: #B45309; --danger: #EF4444; --teal: #14B8A6;
        }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #0C0A09; --fg: #FAFAF9; --muted: #A8A29E; --card: #171615; --border: #292524; --soft: #1C1917; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--fg); font-family: "Plus Jakarta Sans", system-ui, -apple-system, "Segoe UI", sans-serif; font-size: 14px; }
        a { color: inherit; }
        .topbar { position: sticky; top: 0; z-index: 10; background: var(--card); border-bottom: 1px solid var(--border); }
        .topbar-inner { max-width: 1100px; margin: 0 auto; padding: 12px 16px; display: flex; align-items: center; gap: 20px; flex-wrap: wrap; }
        .brand { display: flex; align-items: center; gap: 8px; font-weight: 800; font-size: 16px; text-decoration: none; }
        .brand-mark { width: 28px; height: 28px; border-radius: 8px; background: var(--accent); color: #fff; display: grid; place-items: center; font-size: 14px; }
        .brand small { font-weight: 600; color: var(--muted); }
        nav { display: flex; gap: 4px; flex: 1; }
        nav a { text-decoration: none; padding: 8px 12px; border-radius: 10px; font-weight: 700; color: var(--muted); }
        nav a.active { background: var(--accent-soft); color: var(--accent); }
        .signout { background: none; border: 1px solid var(--border); color: var(--fg); border-radius: 10px; padding: 8px 12px; font: inherit; font-weight: 700; cursor: pointer; }
        main { max-width: 1100px; margin: 0 auto; padding: 24px 16px 48px; }
        h1 { font-size: 22px; font-weight: 800; margin: 0 0 4px; }
        .subtitle { color: var(--muted); margin: 0 0 20px; }
        .card { background: var(--card); border: 1.5px solid var(--border); border-radius: 12px; padding: 20px; margin-bottom: 20px; }
        .card h2 { font-size: 15px; font-weight: 800; margin: 0 0 14px; padding-bottom: 10px; border-bottom: 1px solid var(--border); }
        .flash { background: #10B9811A; color: var(--success); border-radius: 10px; padding: 12px 14px; font-weight: 700; margin-bottom: 16px; }
        .error { background: #EF44441A; color: var(--danger); border-radius: 10px; padding: 12px 14px; font-weight: 700; margin-bottom: 16px; }
        .muted { color: var(--muted); }
        label { display: block; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 6px; }
        input[type=text], input[type=number], input[type=password], input[type=tel], input[type=search], select {
            width: 100%; font: inherit; font-size: 15px; color: var(--fg); background: var(--bg); border: 1.5px solid var(--border); border-radius: 10px; padding: 10px 12px;
        }
        input:focus, select:focus { outline: 2px solid var(--accent); outline-offset: 1px; }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; border: 0; border-radius: 10px; padding: 11px 16px; font: inherit; font-weight: 800; cursor: pointer; text-decoration: none; background: var(--accent); color: #fff; }
        .btn.secondary { background: none; border: 1.5px solid var(--border); color: var(--fg); }
        .btn.danger { background: var(--danger); }
        .row { display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; }
        .row > * { flex: 1; min-width: 160px; }
        .row > .shrink { flex: 0 0 auto; min-width: 0; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 12px; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); font-weight: 700; padding: 10px 12px; border-bottom: 1px solid var(--border); white-space: nowrap; }
        td { padding: 12px; border-bottom: 1px solid var(--border); vertical-align: middle; }
        tr:last-child td { border-bottom: 0; }
        tr.link-row { cursor: pointer; }
        tr.link-row:hover td { background: var(--soft); }
        .badge { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 800; white-space: nowrap; }
        .badge.trial { background: #14B8A61F; color: var(--teal); }
        .badge.active { background: #10B9811F; color: var(--success); }
        .badge.grace { background: #B453091F; color: var(--warning); }
        .badge.expired { background: #EF44441F; color: var(--danger); }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 20px; }
        .stat { background: var(--card); border: 1.5px solid var(--border); border-radius: 12px; padding: 14px 16px; text-decoration: none; }
        .stat.selected { border-color: var(--accent); background: var(--accent-soft); }
        .stat .num { font-size: 22px; font-weight: 800; }
        .stat .lbl { font-size: 12px; color: var(--muted); font-weight: 700; text-transform: uppercase; letter-spacing: .05em; }
        dl { display: grid; grid-template-columns: max-content 1fr; gap: 10px 20px; margin: 0; }
        dt { color: var(--muted); font-weight: 600; }
        dd { margin: 0; font-weight: 700; }
        .pager { display: flex; justify-content: space-between; align-items: center; margin-top: 14px; }
        .hint { font-size: 13px; color: var(--muted); margin: 8px 0 0; line-height: 1.5; }
    </style>
</head>
<body>
@auth
    <header class="topbar">
        <div class="topbar-inner">
            <a class="brand" href="{{ route('admin.libraries') }}"><span class="brand-mark">L</span> Librum <small>Admin</small></a>
            <nav>
                <a href="{{ route('admin.libraries') }}" class="{{ request()->routeIs('admin.libraries*') ? 'active' : '' }}">Libraries</a>
                <a href="{{ route('admin.settings') }}" class="{{ request()->routeIs('admin.settings*') ? 'active' : '' }}">Settings</a>
                <a href="{{ route('admin.activity') }}" class="{{ request()->routeIs('admin.activity') ? 'active' : '' }}">Activity</a>
            </nav>
            <form method="post" action="{{ route('admin.logout') }}">@csrf<button class="signout" type="submit">Sign out</button></form>
        </div>
    </header>
@endauth
<main>
    @if (session('status'))
        <div class="flash">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="error">{{ $errors->first() }}</div>
    @endif
    @yield('content')
</main>
</body>
</html>
