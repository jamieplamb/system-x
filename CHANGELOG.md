# Changelog

## 1.0.0

First public release.

- The widget toolkit: windows, labels, buttons, text fields, lists, checkboxes,
  switches, selects, radio groups, sliders, progress bars, badges, tabs,
  toolbars, dialogs, menus and tooltips, all declared in PHP and rendered by
  the browser display server.
- A window manager: drag, focus, resize from any edge, maximise, minimise, and
  snap tiling to halves and quarters. Window geometry persists per user and
  comes back on reload.
- A desktop shell: a panel with the open-window list and a live clock, a
  launcher with drag-to-group app folders, a system menu, per-user theming
  through the Appearance app, and a branded login greeter that remembers your
  look.
- Durable per-user state end to end, an audit trail of every widget event, and
  per-app crash isolation so one broken app can't take the desktop down.
- An SDK for shipping apps and custom widgets as Composer packages, with
  `system-x:make-app` and `system-x:doctor` to go with it.
