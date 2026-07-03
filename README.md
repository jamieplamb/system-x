# system-x

[![CI](https://github.com/jamieplamb/system-x/actions/workflows/ci.yml/badge.svg)](https://github.com/jamieplamb/system-x/actions/workflows/ci.yml)

An X11-style desktop framework for Laravel. You declare a UI in PHP as a tree of
widgets. The framework serialises it to JSON and a display server in the browser
renders it. Interactions post back to PHP, PHP returns a fresh tree, and the
browser morphs the live DOM to match, keeping focus, caret, selection and scroll
intact.

The result is a proper desktop in a browser tab: draggable windows, snap tiling,
a panel with a launcher and a live clock, per-user theming, and durable state.
Close the tab, come back tomorrow, and your desktop is where you left it.

## What's in this repo

This is the project monorepo:

- `packages/system-x/core` is the framework itself. It publishes to Packagist as
  [`system-x/core`](https://packagist.org/packages/system-x/core).
- `packages/example-todo` is a small worked example of shipping an app as its
  own Composer package.
- The Laravel app at the root is a reference host that runs the demo desktop.

A proper documentation site is on the way. Until then, the reference host below
is the best tour of what the framework does.

## Using it in your own app

```bash
composer require system-x/core
```

Wire a route to `Desktop::render()`, run the migrations, and point the app at a
Reverb server. Full install docs are coming with the docs site; in the meantime
the reference host in this repo is a working setup you can crib from.

## Running the reference host

You'll need PHP 8.3 or newer, Composer, Node, and MySQL. Local dev is set up for
[Laravel Herd](https://herd.laravel.com/), which serves the site at
https://system-x.test with nothing to start by hand, but any standard Laravel
serving setup works.

The desktop requires login. There's no anonymous mode and no public
registration. Seed and sign in with the demo user `demo@system-x.test` /
`password`. The seeder is env-guarded, so that credential only ever exists in
local and testing environments.

### Install

```bash
composer install
npm install
npm run build
```

The core package and the example app are symlinked in via path repositories, so
`composer install` wires them up automatically.

### Database

Create the database and migrate:

```bash
mysql -h 127.0.0.1 -u root -e "CREATE DATABASE IF NOT EXISTS system_x"
php artisan migrate
php artisan db:seed
```

`php artisan db:seed` runs `DemoUserSeeder`, which mints the dev login above.
It's a no-op outside `local` and `testing`, so it can never create that known
credential in production.

`.env` defaults to `DB_DATABASE=system_x` with `root` and no password, which is
Herd's local convention. The fast PHPUnit suite runs against in-memory SQLite
(see `phpunit.xml`) so it stays quick; Dusk and dev run against MySQL.

### Realtime (Reverb)

Rendered trees come down over a Reverb websocket, so the realtime path needs a
Reverb server running. On Herd, enable the managed Reverb service and the
shipped `.env` points at it already. Anywhere else, run `php artisan
reverb:start` and set the `REVERB_*` values in `.env` to match. Without Reverb
the desktop renders on boot but clicks won't repaint, which is a confusing way
to find out it's missing.

A gotcha when self-hosting: Reverb only accepts websocket connections from
origins it knows about. It defaults to the host in `APP_URL`,
and you can override it with `REVERB_ALLOWED_ORIGINS` if your page is served
from somewhere else. Get it wrong and the handshake fails silently.

### Open it

Visit https://system-x.test, sign in with the demo user, and you land on the
desktop with "Hello" and "Notes" open. Drag windows by the titlebar, resize from
any edge, snap them to halves and quarters at the screen edges, or maximise.
The top panel lists open windows; the `system-x` button opens the launcher,
where you can group apps into folders. Right-click the desktop for the context
menu, and try the Appearance app to change theme, accent, wallpaper and panel
position. Everything you change persists per user: the click counter in Hello,
your window layout, your look. Reload and it all comes back.

### Tests

There are PHP suites for the host and the package, a JS unit suite (Vitest),
and a browser end-to-end suite (Dusk).

```bash
# Everything except Dusk in one go.
composer test

# The JS unit suite on its own.
npm run test

# Browser end-to-end. Drives the real site.
php artisan dusk
```

Dusk drives https://system-x.test, so build the assets first, make sure the
site is being served, and have Reverb running. Every Dusk test logs in through
the real form and the whole suite is websocket-driven, so without Reverb it
fails wholesale. The PHPUnit suites don't need Reverb at all.

## License

MIT. See [LICENSE](LICENSE).
