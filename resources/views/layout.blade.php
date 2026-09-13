<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Your account')</title>
    <style>
        :root {
            --ground: #f5f7f8; --surface: #fff; --ink: #14191e; --muted: #5f6d77;
            --rule: #dce3e6; --accent: #0e5b54; --accent-ink: #fff; --warn: #8a5d06;
        }
        @media (prefers-color-scheme: dark) {
            :root:not([data-theme="light"]) {
                --ground: #0d1114; --surface: #151a1e; --ink: #e4eaec; --muted: #93a1a9;
                --rule: #262f35; --accent: #5ccdbc; --accent-ink: #0d1114; --warn: #d7a64a;
            }
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: flex; align-items: center;
            justify-content: center; padding: 1.5rem; background: var(--ground);
            color: var(--ink); line-height: 1.6;
            font-family: ui-sans-serif, -apple-system, "Segoe UI", system-ui, sans-serif;
        }
        .card {
            background: var(--surface); border: 1px solid var(--rule); border-radius: 8px;
            padding: 2rem; max-width: 30rem; width: 100%;
        }
        h1 { font-size: 1.35rem; margin: 0 0 .75rem; letter-spacing: -.01em; text-wrap: balance; }
        p { margin: 0 0 1rem; color: var(--muted); }
        p strong, .date { color: var(--ink); font-weight: 600; }
        .notice {
            border-left: 3px solid var(--warn); padding: .6rem .9rem; margin: 0 0 1.25rem;
            font-size: .92rem;
        }
        form { margin: 1.5rem 0 0; }
        button {
            font: inherit; font-weight: 600; cursor: pointer; width: 100%;
            background: var(--accent); color: var(--accent-ink); border: 0;
            border-radius: 6px; padding: .7rem 1.2rem;
        }
        button:hover { opacity: .9; }
        button:focus-visible { outline: 2px solid var(--ink); outline-offset: 2px; }
        .muted { font-size: .85rem; margin: 1rem 0 0; }
    </style>
</head>
<body>
    <main class="card">
        @yield('body')
    </main>
</body>
</html>
