<?php

namespace SystemX\Core\Widgets;

use SystemX\Core\Wire\Node;

class Button extends Node
{
    public function __construct(string $label)
    {
        parent::__construct('button', ['label' => $label]);
    }

    public static function make(string $label): static
    {
        return new static($label);
    }

    // Sugar for on('click', ...) -- the inline-closure binding style.
    public function onClick(callable|string $handler): static
    {
        return $this->on('click', $handler);
    }

    // Sugar for the named-handler form on a button's click event.
    public function handles(string $method): static
    {
        return $this->on('click', $method);
    }

    // The pref hook (Plan 5b-2, D5): marks this button as an Appearance/context-menu pref
    // control. The JS renderer stamps it as data-sx-pref; the client interceptor catches the
    // click + applies/persists -- it does NOT round-trip to an App handler. Form: "key:value".
    // CLEARS the events allowlist (B2/D2): a plain button defaults to ['click'], which the
    // delegated dispatcher round-trips as a widget event. A pref button must NOT round-trip --
    // an empty allowlist stamps data-sx-events="" so the dispatcher's allow() filters to [] and
    // skips it; the document-level interceptor is the ONLY handler that acts on the click. This
    // is the inertness the "no App round-trip" claim depends on -- without it the click would
    // BOTH apply client-side AND broadcast a frame that races the persist + un-presses the button.
    public function pref(string $hook): static
    {
        $this->props['pref'] = $hook;
        $this->props['events'] = []; // no widget-event round-trip (B2/D2)

        return $this;
    }

    // The app-action hook (App-install plan, D4): marks this button as a Manage-apps
    // install/uninstall TOGGLE for the given app slug. The JS renderer stamps it as
    // data-sx-app-action; the client interceptor (manage-apps.js, Task 5) catches the click +
    // toggles install/uninstall -- it does NOT round-trip to an App handler. Mirrors pref():
    // CLEARS the events allowlist (an empty allowlist stamps data-sx-events="" so the dispatcher
    // skips the click), so the document-level interceptor is the ONLY handler that acts on it.
    // The title/icon ride along (App-install plan, D5) so the client interceptor can re-add the
    // launcher tile on INSTALL without re-querying the registry -- an uninstalled app is no longer
    // in the launcher's boot set, so its meta must travel with the toggle. Optional: a toggle with
    // no meta still works for uninstall (the launcher already has the tile); install needs it.
    public function appAction(string $slug, ?string $title = null, ?string $icon = null): static
    {
        $this->props['appAction'] = $slug;
        $this->props['events'] = []; // no widget-event round-trip (the client interceptor owns it)

        if ($title !== null) {
            $this->props['appTitle'] = $title;
        }
        if ($icon !== null) {
            $this->props['appIcon'] = $icon;
        }

        return $this;
    }

    // The pressed cue (Plan 5b-2, D5): the CLIENT seeds the CURRENT pref's control pressed on
    // window-open (from the live root attribute, B1) + the apply path (prefs.js) moves it on a
    // live flip. A plain visual cue -- no behaviour, no round-trip (it co-occurs with pref(),
    // whose empty allowlist already keeps the button inert to the dispatcher).
    public function pressed(bool $on = true): static
    {
        $this->props['pressed'] = $on;

        return $this;
    }
}
