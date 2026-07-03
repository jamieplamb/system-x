<?php

namespace SystemX\Core\Apps;

use SystemX\Core\Runtime\App;
use SystemX\Core\Widgets\Button;
use SystemX\Core\Widgets\Label;
use SystemX\Core\Widgets\Stack;
use SystemX\Core\Widgets\Window;
use SystemX\Core\Wire\Node;

// The Appearance app (Plan 5b-2, D5). A REAL App -- registered, so the D2 metadata seam
// gives it a launcher tile + panel button for free. It is a STATIC render (B1): it does NOT
// inject PreferencesService and reads NO principal -- the App contract gives render() no
// StateKey (App.php has no principal()), and the launch path renders via renderFromBag(
// $app, []) with no principal (WmController.php:47). So it emits the control LAYOUT only;
// the PRESSED-state is CLIENT-seeded on window-open from the live root attribute (prefs.js,
// D5). The controls are plain Buttons with a data-sx-pref hook; pref() ALSO clears the events
// allowlist so the click never round-trips (B2) -- a CLIENT interceptor catches it (apply +
// persist), no App handlers here. (The mockup composes Appearance from buttons too,
// ui_kits/desktop/index.html:159-188.)
class AppearanceApp extends App
{
    // The control sets (in lockstep with PreferencesService::ALLOWED + the token files).
    private const THEMES = ['modern', 'dark', 'pewter', 'nextstep', 'onyx'];

    private const ACCENTS = ['blue', 'teal', 'violet', 'green', 'amber', 'graphite'];

    private const WALLPAPERS = ['gradient', 'grid', 'lines', 'solid'];

    private const PANELS = ['top', 'bottom'];

    public function slug(): string
    {
        return 'appearance';
    }

    public function title(): string
    {
        return 'Appearance';
    }

    public function icon(): string
    {
        return 'gear';
    }

    // System furniture (plan system-menu, D1): Appearance is the framework's own settings app,
    // so it lives in the user-icon menu, not the launcher grid.
    public function system(): bool
    {
        return true;
    }

    public function render(): Node
    {
        // STATIC layout (B1) -- no prefs read, no principal. The pressed-state is seeded
        // CLIENT-side on window-open (prefs.js reads the live <html>/#sx-desktop attribute the
        // no-flash boot stamped, D4/D5). The App just paints the buttons + their hooks.
        return Window::make('Appearance')->size(344, 300)->content([
            $this->section('Theme', self::THEMES, 'theme'),
            $this->section('Accent', self::ACCENTS, 'accent'),
            $this->section('Wallpaper', self::WALLPAPERS, 'wallpaper'),
            $this->section('Taskbar', self::PANELS, 'panel'),
        ]);
    }

    /**
     * A section = a heading Label above a SEPARATE inner row Stack of the option buttons.
     * The outer Stack stacks the heading over the row (the default column flow); the INNER
     * Stack is flowed HORIZONTALLY by the appearance-scoped CSS, keyed off its id
     * (data-sx-id="appearance-{key}-row"), so the options read as one wrapping row.
     *
     * @param  array<int, string>  $values
     */
    private function section(string $label, array $values, string $key): Node
    {
        $buttons = [];
        foreach ($values as $value) {
            // pref() sets the data-sx-pref hook + clears the events allowlist (D5/B2 -- no
            // round-trip). NO pressed() here: the pressed-state is owned by the client, seeded
            // on window-open + moved on a live flip (prefs.js), so the server emits no cue.
            $buttons[] = Button::make(ucfirst($value))
                ->id("pref-{$key}-{$value}")
                ->pref("{$key}:{$value}");
        }

        return Stack::make()->content([
            Label::make($label)->id("appearance-{$key}-label"),
            Stack::make()->content($buttons)->id("appearance-{$key}-row"),
        ]);
    }
}
