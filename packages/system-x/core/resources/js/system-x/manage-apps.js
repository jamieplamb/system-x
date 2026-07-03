// The Manage-apps client interceptor + seed (App-install plan, D5). Mirrors prefs.js: a
// [data-sx-app-action] toggle click is CLIENT chrome -- it installs/uninstalls the app live and
// persists fire-and-forget, WITHOUT round-tripping to an App handler (the toggle buttons clear
// the events allowlist, so the widget-event dispatcher is inert for them -- this interceptor is
// the only handler). The seed (seedAppActions) sets each toggle's installed/uninstalled state on
// window-open from the launcher's LIVE in-memory set, exactly like prefs.js seedPressed reads the
// live root attribute. The App render() is STATIC (no principal) -- the client owns the state.

// The in-flight lock (S1). install/uninstall are STATEFUL (unlike an idempotent pref), so spamming
// a toggle must NOT interleave install/uninstall POSTs into an inconsistent state. A slug is locked
// the moment its POST is fired and unlocked when it settles; a second click while locked is a no-op.
const inFlight = new Set();

// Flip a toggle button to a target state via a DIRECT DOM mutation (S4 -- like prefs.js
// reflectPressed, NOT a re-seed): data-sx-installed + the label. installed === true shows the
// "Uninstall" affordance (the app IS installed, click to remove); false shows "Install".
function reflectToggle(btn, installed) {
    btn.dataset.sxInstalled = installed ? 'true' : 'false';
    btn.textContent = installed ? 'Uninstall' : 'Install';
}

// Read the app meta (slug/title/icon) off a toggle button. The slug is the data-sx-app-action
// hook; the title/icon ride as data attrs the renderer stamped from the Manage-apps row's app
// metadata (the launcher's boot set no longer carries an uninstalled app's meta, so install must
// source it here). Falls back to the slug / 'window' so a missing attr never mints a broken tile.
function metaFor(btn, slug) {
    return {
        slug,
        title: btn.dataset.sxAppTitle ?? slug,
        icon: btn.dataset.sxAppIcon ?? 'window',
    };
}

// The [data-sx-app-action] click interceptor (D5). Catches a toggle click, reads the slug + the
// CURRENT state (launcher.hasApp -- the in-memory set, B1), and toggles install <-> uninstall. The
// ORDER + the channels matter (D5):
//   - INSTALLED -> UNINSTALL: uninstallApp POST; launcher.removeApp (the in-memory set FIRST, B1);
//     close the app's open surfaces via wm.removeSurface DIRECTLY (B2 -- the endpoint already closed
//     the server rows + forgot state; routing through wm.close() would fire a redundant /wm/close
//     403); flip the button to "Install" (a direct DOM mutation, S4).
//   - UNINSTALLED -> INSTALL: installApp POST; launcher.addApp(meta) (the meta from the row); flip
//     the button to "Uninstall".
// The in-flight lock (S1) gates the whole thing: a second click while the slug's POST is pending is
// a no-op. Returns true when it handled a toggle, false otherwise (a non-toggle click / a locked one).
export async function interceptAppAction(e, { launcher, wm, transport }) {
    const btn = e.target.closest?.('[data-sx-app-action]')
        ?? (e.target.matches?.('[data-sx-app-action]') ? e.target : null);
    if (!btn) {
        return false;
    }

    const slug = btn.getAttribute('data-sx-app-action');
    if (inFlight.has(slug)) {
        return false; // S1 -- a POST for this slug is already pending; ignore the spam click
    }
    inFlight.add(slug);

    try {
        if (launcher.hasApp(slug)) {
            // INSTALLED -> UNINSTALL. Update the in-memory set FIRST (B1), drop the painted
            // surfaces DIRECTLY (B2), then flip the button + POST.
            launcher.removeApp(slug);
            for (const [windowId, surface] of wm.surfaces) {
                if (surface.dataset.app === slug) {
                    wm.removeSurface(windowId); // B2 -- DIRECT, never wm.close()/the /wm/close POST
                }
            }
            reflectToggle(btn, false);
            await transport.uninstallApp(slug);
        } else {
            // UNINSTALLED -> INSTALL. Re-add the tile from the row's meta, flip the button, POST.
            launcher.addApp(metaFor(btn, slug));
            reflectToggle(btn, true);
            await transport.installApp(slug);
        }
    } finally {
        inFlight.delete(slug); // release the lock on settle so a later toggle works (S1)
    }

    return true;
}

// Seed every Manage-apps toggle's installed/uninstalled state on window-open (D5/B1 -- mirror
// prefs.js seedPressed). For each [data-sx-app-action] button on the surface, set its state from
// launcher.hasApp(slug) -- the IN-MEMORY set, NOT a grid-DOM query (the launcher is CLOSED while
// Manage-apps is open, so its grid DOM is empty; a DOM query would seed everything as uninstalled,
// B1). A safe no-op when no toggles are in the DOM yet (it just finds nothing to seed). The display
// server calls this AFTER the frame reconciles (the buttons are painted), guarded on the surface.
export function seedAppActions(launcher) {
    for (const btn of document.querySelectorAll('[data-sx-app-action]')) {
        const slug = btn.getAttribute('data-sx-app-action');
        reflectToggle(btn, launcher.hasApp(slug));
    }
}
