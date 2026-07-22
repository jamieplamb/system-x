# Changelog

## 1.1.1

- Fix double-click on a window titlebar not maximising. Arming a drag on the same
  press takes pointer capture on the surface, and capture retargets the resulting
  click/dblclick to the capture element -- so the handler saw the surface rather
  than the titlebar and bailed. The hit element is now resolved from the pointer
  coordinates. Covered by a browser test doing a real double-click; the previous
  unit test dispatched a synthetic event and could not catch this.

## 1.1.0

- Add the Image widget (a src/alt image node, for image cells and thumbnails) and
  the Chart widget.

## 1.0.1

- Point the package homepage and docs links at the new documentation site.

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
