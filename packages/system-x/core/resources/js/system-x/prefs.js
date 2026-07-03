// The shared pref apply+persist mechanism (Plan 5b-2, D2/D3). The ONE place a pref is
// applied -- the Appearance app's controls (intercepted clicks) AND the context menu both
// call into it. APPLY is instant + client-only (set the root attribute, the CSS reskins);
// PERSIST is a fire-and-forget POST (like closeWindow). NO broadcast, NO App round-trip.

const WALLPAPER_CYCLE = { gradient: 'grid', grid: 'lines', lines: 'solid', solid: 'gradient' };

// Which root element owns each pref's attribute (D3): theme/accent/panel reskin the WHOLE
// desktop, so they live on <html> (the only common ancestor of #sx-desktop + the body-
// mounted chrome); wallpaper is the desktop BACKGROUND, so it lives on #sx-desktop.
function rootFor(key) {
    if (key === 'wallpaper') {
        return document.getElementById('sx-desktop');
    }
    return document.documentElement;
}

// The data-* attribute name for a pref key (panel -> data-sx-panel, etc.).
const ATTR = { theme: 'sxTheme', accent: 'sxAccent', wallpaper: 'sxWallpaper', panel: 'sxPanel' };

// Apply a pref INSTANTLY -- set the attribute on the right root (D3). Also reflect the
// pressed-state onto any open Appearance control for this key (so the just-clicked button
// presses in without an App re-render). Outside the morph -- the reconciler never touches
// <html>/#sx-desktop, so a frame can't clobber this (D3).
export function applyPref(key, value) {
    const root = rootFor(key);
    if (root) {
        root.dataset[ATTR[key]] = value;
    }
    reflectPressed(key, value);
}

// Mark the [data-sx-pref] control for this key+value pressed, the rest of the key's group
// not. Factored out so applyPref (live flip) AND seedPressed (window-open) share it.
function reflectPressed(key, value) {
    for (const btn of document.querySelectorAll(`[data-sx-pref^="${key}:"]`)) {
        const [, v] = btn.getAttribute('data-sx-pref').split(':');
        btn.dataset.sxPressed = v === value ? 'true' : 'false';
    }
}

// Seed the Appearance window's pressed-state from the LIVE root attribute (B1/D5). The App
// is a STATIC render -- it can't read the user's prefs (no principal at render), so the
// SERVER never seeds the cue. Instead, when the Appearance window opens, read the live truth
// off the root (<html>'s data-sx-theme/accent/panel + #sx-desktop's data-sx-wallpaper, which
// the no-flash boot stamped from the durable store, D4) and press the matching control.
// MUST run AFTER the reconciler paints the buttons (S4) -- the display server calls this once
// the appearance window's frame is applied (or guards on [data-sx-pref] presence). If the
// buttons aren't in the DOM yet, it's a safe no-op (it just finds nothing to press).
export function seedPressed() {
    const html = document.documentElement;
    const desktop = document.getElementById('sx-desktop');
    reflectPressed('theme', html.dataset.sxTheme ?? 'modern');
    reflectPressed('accent', html.dataset.sxAccent ?? 'blue');
    reflectPressed('panel', html.dataset.sxPanel ?? 'top');
    reflectPressed('wallpaper', desktop?.dataset.sxWallpaper ?? 'gradient');
}

// Step the wallpaper to the next style (the context menu's "Cycle wallpaper", D7). Reads
// the current #sx-desktop attribute (default 'gradient'), applies + returns the next.
export function cycleWallpaper(save) {
    const desktop = document.getElementById('sx-desktop');
    const current = desktop?.dataset.sxWallpaper || 'gradient';
    const next = WALLPAPER_CYCLE[current] ?? 'gradient';
    applyPref('wallpaper', next);
    save?.('wallpaper', next);
    return next;
}

// The [data-sx-pref] click interceptor (D5). Catches a pref-button click, parses key:value,
// applies instantly + persists fire-and-forget. For panel, it also drives the WM's
// setPanelPosition (D6) so the work area re-insets live. `save` is the transport POST,
// injected so a test can stub it. Does NOT stopPropagation -- a pref button is also a normal
// button, so the event flows; the widget-event dispatcher is inert for it (Task 5 clears the
// events allowlist), so there's no double-fire.
export function interceptPrefClick(e, wm, save) {
    const btn = e.target.closest?.('[data-sx-pref]') ?? (e.target.matches?.('[data-sx-pref]') ? e.target : null);
    if (!btn) {
        return false;
    }
    const [key, value] = btn.getAttribute('data-sx-pref').split(':');
    applyPref(key, value);
    if (key === 'panel') {
        wm?.setPanelPosition?.(value); // re-inset the work area for the new edge (D6)
    }
    save?.(key, value);
    return true;
}
