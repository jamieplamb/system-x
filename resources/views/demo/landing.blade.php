{{-- Live-demo landing page (showcase plan). Served in place of the greeter when demo mode is
     on. Self-contained: brand-matched inline CSS (dark radial backdrop + blue accent, echoing
     the greeter) so it always renders even if the desktop asset pipeline is unavailable, and
     no external fonts/assets. The button POSTs to /demo/launch (throttled, guest-gated). --}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>system-x live demo</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        html, body { height: 100%; margin: 0; }
        body {
            font-family: 'IBM Plex Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            color: #1d1d20;
            background: radial-gradient(135% 135% at 22% 0%, #5d7fb4 0%, #3d5079 44%, #262f47 100%);
            -webkit-font-smoothing: antialiased;
            text-rendering: optimizeLegibility;
        }
        .demo-landing {
            min-height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 32px 20px;
            gap: 22px;
        }
        .demo-brand {
            display: flex;
            align-items: center;
            gap: 9px;
            color: rgba(255, 255, 255, .82);
        }
        .demo-brand svg { display: block; }
        .demo-wordmark {
            font-size: 15px;
            font-weight: 600;
            letter-spacing: .01em;
        }
        .demo-wordmark-x { color: #a9c2ec; }
        .demo-card {
            width: 100%;
            max-width: 420px;
            background: #ffffff;
            border-radius: 12px;
            padding: 34px 32px 30px;
            box-shadow: 0 14px 40px -8px rgba(0, 0, 0, .34), 0 4px 12px -4px rgba(0, 0, 0, .22);
            text-align: center;
        }
        .demo-card h1 {
            margin: 0 0 10px;
            font-size: 26px;
            font-weight: 600;
            letter-spacing: -.01em;
        }
        .demo-card p {
            margin: 0 0 24px;
            font-size: 15px;
            line-height: 1.55;
            color: #3d3d42;
        }
        .demo-launch {
            display: inline-block;
            width: 100%;
            border: 0;
            cursor: pointer;
            padding: 12px 20px;
            border-radius: 8px;
            font: inherit;
            font-size: 15px;
            font-weight: 600;
            color: #ffffff;
            background: #2f5aa6;
            transition: background .12s ease;
        }
        .demo-launch:hover { background: #294f92; }
        .demo-launch:active { background: #1a3a72; }
        .demo-launch:focus-visible { outline: 3px solid rgba(47, 90, 166, .45); outline-offset: 2px; }
        .demo-links {
            margin: 20px 0 0;
            font-size: 13px;
            color: #6a6a72;
        }
        .demo-links a { color: #2f5aa6; text-decoration: none; }
        .demo-links a:hover { text-decoration: underline; }
        .demo-links .sep { margin: 0 8px; color: #c4c4cc; }
    </style>
</head>
<body>
    <main class="demo-landing">
        <div class="demo-brand" aria-hidden="true">
            <svg width="20" height="20" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                <rect x="3.5" y="4.5" width="25" height="23" stroke="currentColor" stroke-width="2"></rect>
                <rect x="3.5" y="4.5" width="25" height="6" fill="currentColor"></rect>
                <path d="M10 15L22 25M22 15L10 25" stroke="currentColor" stroke-width="2.6" stroke-linecap="square"></path>
            </svg>
            <span class="demo-wordmark">system<span class="demo-wordmark-x">-x</span></span>
        </div>

        <div class="demo-card">
            <h1>system-x</h1>
            <p>A desktop environment for the browser, written in PHP. Grab a throwaway desktop, poke around, close the tab. Everything you do survives a reload.</p>
            <form method="POST" action="/demo/launch">
                @csrf
                <button type="submit" class="demo-launch">Launch the demo</button>
            </form>
            <p class="demo-links">
                <a href="https://jamieplamb.github.io/system-x-docs/">Docs</a>
                <span class="sep">&middot;</span>
                <a href="https://github.com/jamieplamb/system-x">GitHub</a>
            </p>
        </div>
    </main>
</body>
</html>
