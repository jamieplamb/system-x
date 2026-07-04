{{-- Live-demo capacity page (showcase plan). Rendered by /demo/launch when the live demo-user
     count is at/over the cap; nothing is minted. Same self-contained brand treatment as the
     landing page. --}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>system-x live demo &middot; at capacity</title>
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
            align-items: center;
            justify-content: center;
            padding: 32px 20px;
        }
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
            font-size: 22px;
            font-weight: 600;
            letter-spacing: -.01em;
        }
        .demo-card p {
            margin: 0 0 20px;
            font-size: 15px;
            line-height: 1.55;
            color: #3d3d42;
        }
        .demo-card p:last-child { margin-bottom: 0; }
        .demo-card a { color: #2f5aa6; text-decoration: none; font-weight: 600; }
        .demo-card a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <main class="demo-landing">
        <div class="demo-card">
            <h1>The demo is full right now</h1>
            <p>Everyone gets their own desktop and there are only so many to go round. Idle ones are cleaned up every few minutes, so it shouldn't be a long wait.</p>
            <p><a href="/login">Try again</a></p>
        </div>
    </main>
</body>
</html>
