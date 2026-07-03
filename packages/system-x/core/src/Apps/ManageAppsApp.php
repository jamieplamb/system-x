<?php

namespace SystemX\Core\Apps;

use SystemX\Core\Runtime\App;
use SystemX\Core\Runtime\AppRegistry;
use SystemX\Core\Widgets\Button;
use SystemX\Core\Widgets\Label;
use SystemX\Core\Widgets\Stack;
use SystemX\Core\Widgets\Window;
use SystemX\Core\Wire\Node;

// The Manage-apps app (App-install plan, D4). A SYSTEM app -- it lives in the user-icon menu
// (the menu lists system apps dynamically, so it auto-appears next to Appearance/About), is
// never uninstallable, and never appears in its OWN list. It mirrors the Appearance pattern:
// a STATIC render whose toggle buttons carry a data-sx-app-action hook (Button::appAction(),
// which clears the events allowlist so the click never round-trips). render() reads NO
// principal -- it CAN'T see the per-user uninstalled set -- so it emits the toggle LAYOUT only
// (a row per USER app); the install/uninstall STATE is CLIENT-seeded on window-open (Task 5).
//
// It constructor-injects AppRegistry (apps can inject services -- the kernel container-resolves
// them; the hydrator's declaring-class gate keeps the injected field OUT of the durable bag).
// It lists every USER app (metadata() filtered system === false) -- so hello/notes, NEVER a
// system app (appearance/about/apps itself).
class ManageAppsApp extends App
{
    public function __construct(private AppRegistry $registry) {}

    public function slug(): string
    {
        return 'apps';
    }

    public function title(): string
    {
        return 'Manage apps';
    }

    public function icon(): string
    {
        return 'launcher';
    }

    // System furniture (D4): Manage-apps is the framework's own install/uninstall surface, so it
    // lives in the user-icon menu, not the launcher grid -- and is itself never uninstallable.
    public function system(): bool
    {
        return true;
    }

    public function render(): Node
    {
        // STATIC layout (D4) -- no principal, no uninstalled-set read. A row per USER app
        // (system === false); the toggle's install/uninstall label + pressed-state is seeded
        // CLIENT-side on window-open (manage-apps.js, Task 5). The App just paints the rows +
        // their data-sx-app-action hooks.
        $rows = [];
        foreach ($this->registry->metadata() as $meta) {
            if ($meta['system']) {
                continue; // system apps are never uninstallable -> never listed
            }

            $rows[] = $this->row($meta['slug'], $meta['title'], $meta['icon']);
        }

        return Window::make('Manage apps')->size(360, 320)->content($rows);
    }

    /**
     * A row = the app's title Label beside a toggle Button carrying the app-action hook + the app's
     * title/icon (so the client interceptor can re-add the launcher tile on INSTALL -- an
     * uninstalled app's meta is no longer in the launcher boot set). The toggle's label is a
     * neutral "Toggle" here; the client sets the install/uninstall label + pressed-state on seed
     * (Task 5). The row stack carries a stable id keyed by slug.
     */
    private function row(string $slug, string $title, string $icon): Node
    {
        return Stack::make()->id("apps-row-{$slug}")->content([
            Label::make($title)->id("apps-label-{$slug}"),
            Button::make('Toggle')
                ->id("apps-toggle-{$slug}")
                ->appAction($slug, $title, $icon),
        ]);
    }
}
