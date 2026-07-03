import { fetchDesktop, sendEvent, launchWindow, closeWindow, savePref, saveGeometry, installApp, uninstallApp, fetchAudit } from './transport.js';
import { renderAuditView } from './audit.js';
import { registry } from './renderers.js';
import { reconcile, destroyTree } from './reconcile.js';
import { installDispatcher } from './dispatcher.js';
import { interceptPrefClick, cycleWallpaper, applyPref, seedPressed } from './prefs.js';
import { interceptAppAction, seedAppActions } from './manage-apps.js';
import { WindowManager } from './window-manager.js';
import { Panel } from './panel.js';
import { Launcher } from './launcher.js';
import { ContextMenu } from './context-menu.js';
import { SystemMenu } from './system-menu.js';
import { showToast } from './toast.js';

// Parse the boot payload (the <script id="sx-boot"> JSON blob the shell embeds, D2/D3) into
// the metadata the panel + launcher render from. Returns { byApp, apps } -- byApp is an
// app-slug -> {title, icon} map (the panel's per-window labels, seeded from the open windows
// + the registered apps and merged from each /wm/launch response after); apps is the ordered
// registered-app metadata list [{slug, title, icon}, ...] the launcher's grid renders.
// NULL-SAFE (B2): a missing/blank/malformed blob yields an empty map + an empty apps list --
// the panel then falls back to {title: app, icon: 'window'} per window, and the launcher
// shows an empty grid, never a broken button or a white screen.
function parseBootMeta(mount) {
    const byApp = {};
    const apps = [];
    let panel = 'top'; // the panel edge (5b-2 D4/D6) -- default 'top' if the blob omits it
    let userName = ''; // the logged-in user's name (plan system-menu D5) -- '' if the blob omits it
    let layout = []; // the reconciled launcher layout (Slice 4a) -- [] if the blob omits it
    try {
        const node = mount.querySelector('#sx-boot');
        const blob = node ? JSON.parse(node.textContent || '{}') : {};
        panel = blob.panel ?? 'top';
        userName = blob.user?.name ?? '';
        layout = Array.isArray(blob.layout) ? blob.layout : [];
        for (const w of blob.windows ?? []) {
            if (w && w.app) {
                byApp[w.app] = { title: w.title ?? w.app, icon: w.icon ?? 'window' };
            }
        }
        for (const a of blob.apps ?? []) {
            if (a && a.slug) {
                // Carry `system` through (D2) so the LAUNCHER can filter to user apps. byApp (the
                // panel's window-label lookup) keeps EVERY app regardless of the flag (S5) -- an
                // open system window must still label in the tray. The launcher self-filters.
                const meta = { slug: a.slug, title: a.title ?? a.slug, icon: a.icon ?? 'window', system: !!a.system };
                apps.push(meta);
                if (!byApp[a.slug]) {
                    byApp[a.slug] = { title: meta.title, icon: meta.icon };
                }
            }
        }
    } catch {
        // A malformed boot blob must not white-screen the desktop -- degrade to empty.
    }
    return { byApp, apps, panel, userName, layout };
}

export class DisplayServer {
    constructor(mount, desktopId) {
        this.mount = mount;
        this.desktopId = desktopId;
        this.hasConnected = false; // distinguishes the first connect from a genuine reconnect

        // The window manager owns the per-window SURFACES + all their display state
        // (geometry, z-order, focus -- D1/D3). It adopts the boot's static surfaces,
        // cascades them, and focuses the first. The display server only ever morphs the
        // CONTENT inside a surface; it asks the WM for the surface to morph into. The
        // reconnecting badge is a SIBLING of the surfaces, so a morph never tears it down.
        //
        // onClose (Task 9, D7): the WM removes the surface itself (it owns surfaces) and raises
        // this close intent; the display server owns the wire and fires the close POST. The POST
        // is fire-and-forget -- the server drops the open-row + FORGETS the bag (explicit close
        // reaps state; a reload/disconnect never calls this, it just re-reads the retained bag).
        // onChange (5b D3, Task 2): the WM's list-change seam, mirroring onClose. The WM
        // pushes its windows() list here on every open/close/focus/blur so the (coming) panel
        // can mirror it -- push, never poll. NULL-SAFE BOOT COUPLING (B2): the WM brings the
        // first window DURING construction (5a boot-focus), which fires onChange BEFORE the
        // panel exists. So when Task 4 lands the panel it MUST be built BEFORE the WindowManager
        // and the callback stays null-safe (`this.panel?.render(list)`). There is no panel yet,
        // so onChange is a no-op for now -- Task 4 swaps in the real panel render.
        // App metadata (D2): the app-slug -> {title, icon} map the panel labels windows from,
        // seeded from the boot blob and merged from each /wm/launch response (launch() below).
        this.windowMeta = parseBootMeta(mount);

        // The PANEL is constructed BEFORE the WindowManager (B2 -- LOAD-BEARING). The WM ctor
        // calls this.bring(first) on boot, and bring() notifies, so onChange fires SYNCHRONOUSLY
        // DURING `new WindowManager(...)` -- before this.wm is assigned. The panel must already
        // exist (so this.panel?.render is a real render, not a no-op), and the callback must be
        // null-safe, or the boot notify white-screens. Body-mounted, NOT inside #sx-desktop (B4):
        // #sx-desktop is overflow:hidden and carries the WM's mount-scoped pointerdown/click, so
        // a panel click in body is never misread as a window focus or a desktop blur.
        this.panel = new Panel(document.body, {
            metadataFor: (app) => this.windowMeta.byApp[app] ?? { title: app, icon: 'window' },
            onSelectWindow: (id, active) => (active ? this.wm.minimise(id) : this.wm.bring(id)),
            onLaunch: () => this.openLauncher(),
            userName: this.windowMeta.userName,
            // The tray user button hands us ITS element; toggle the SystemMenu anchored to it
            // (the toggle-vs-close guard, S1 -- the menu ignores a mousedown on its anchor).
            onUserMenu: () => this.toggleSystemMenu(),
        });
        document.body.appendChild(this.panel.el);

        // The LAUNCHER overlay (5b, D5) -- the app grid behind the panel's system-x button.
        // Body-mounted (B4), z 100002 (S6 -- above the panel 100000 + the badge 100001). It
        // appends its own overlay to document.body in its ctor. Picking a tile reuses the
        // EXISTING focus-if-open-else-launch path (openApp) -- the launcher is a nicer trigger,
        // NOT a new launch path. The launcher self-filters to USER apps (!system, plan
        // system-menu D2) -- so Appearance/About (system apps) live in the SystemMenu instead.
        this.launcher = new Launcher(document.body, {
            apps: this.windowMeta.apps,
            layout: this.windowMeta.layout,
            onPick: (slug) => this.openApp(slug),
        });

        // The SYSTEM MENU (plan system-menu D3) -- the user-icon dropdown. It lists the SYSTEM
        // apps (Appearance/About) the launcher filters out, greets the user by name, and carries
        // the Log out item. Anchored to the panel's user button (the ELEMENT, S1 -- the
        // toggle-vs-close guard needs it). byApp is UNTOUCHED (S5) -- this is a separate
        // derivation, not a mutation; an open system window still labels in the tray via byApp.
        this.systemMenu = new SystemMenu({
            anchor: this.panel.userBtn,
            panelPosition: this.windowMeta.panel ?? 'top',
            systemApps: this.windowMeta.apps.filter((a) => a.system),
            userName: this.windowMeta.userName,
            onOpenApp: (slug) => this.openApp(slug),
            onLogout: () => this.logout(),
        });

        this.wm = new WindowManager(mount, {
            onClose: (windowId) => closeWindow(windowId),
            onChange: (list) => this.panel?.render(list), // null-safe (B2): a notify before the panel exists is a no-op
            // onGeometry (Plan 5e D3): the WM raises a settle snapshot at each discrete settle point
            // (drag-end, resize-end, maximise, restore, minimise, un-minimise, a real raise); the
            // display server owns the wire and fires the fire-and-forget geometry POST. The WM stays
            // transport-agnostic -- no fetch in window-manager.js, exactly like onClose/onChange.
            onGeometry: (windowId, geometry) => saveGeometry(windowId, geometry),
            // destroySubtree (PH Task 2): the WM raises a teardown for a closed surface; the display
            // server owns the registry and runs destroyTree over it, firing each renderer's optional
            // destroy() hook. The WM stays registry-agnostic -- exactly like onClose/onChange/onGeometry.
            destroySubtree: (el) => destroyTree(el, { registry }),
            panelPosition: this.windowMeta.panel ?? 'top', // the boot panel edge (5b-2 D4/D6)
        });

        // Belt + braces (B2): paint the initial list after BOTH are built -- the boot notify
        // may already have rendered it, this guarantees it regardless.
        this.panel.render(this.wm.windows());

        // The tray clock starts ticking (Task 7, D6) -- paints now + repaints on its interval.
        this.panel.startClock();

        // ONE mount-level delegated dispatcher (D8) -- it reads the host window id AND app
        // off the surface the event bubbled through, so a single listener serves EVERY
        // window and no surface stacks its own. Renderers attach no per-element listeners,
        // so a morphed/reused element never stacks duplicates. The {app, window} split
        // (Task 6) means both ride up: `window` keys the bag, `app` names the App to run.
        installDispatcher(this.mount, (widget, event, value, window, app) => this.onEvent(widget, event, value, window, app));

        // The Appearance app's controls are client chrome (D5/D2) -- a [data-sx-pref] click
        // applies the attribute to the right root INSTANTLY (theme/accent/panel -> <html>,
        // wallpaper -> #sx-desktop) + persists fire-and-forget, WITHOUT round-tripping to an
        // App handler (Task 5 clears the events allowlist on pref buttons, so the widget-event
        // dispatcher is inert for them -- this interceptor is the only handler, no double-fire).
        // Listen on DOCUMENT: the panel/launcher/menu live in body, the windows in #sx-desktop --
        // a pref control can be in any of them. interceptPrefClick's own closest() guards it, so
        // it early-returns (touching nothing) for every non-pref click. Do NOT stopPropagation.
        document.addEventListener('click', (e) => interceptPrefClick(e, this.wm, savePref));

        // The Manage-apps toggles are client chrome too (App-install plan, D5) -- a
        // [data-sx-app-action] click installs/uninstalls the app LIVE: the launcher tile
        // appears/disappears, an uninstalled app's open surfaces close on the spot (wm.removeSurface
        // DIRECTLY, B2 -- the endpoint already did the server close), the button flips, and the
        // change persists fire-and-forget. Listen on DOCUMENT like the pref interceptor (a manage-
        // apps window lives in #sx-desktop). interceptAppAction's own closest() guards it, so it's a
        // no-op for every non-toggle click. The transport is injected so a test can stub it.
        document.addEventListener('click', (e) =>
            interceptAppAction(e, { launcher: this.launcher, wm: this.wm, transport: { installApp, uninstallApp } }),
        );

        // The desktop right-click context menu (Task 8, D7) -- body-mounted client chrome. The
        // GUARDED contextmenu listener lives on the #sx-desktop mount but fires ONLY on the bare
        // background (NOT a window surface or the panel -- a window right-click passes the native
        // menu through). Items reuse the EXISTING paths: openApp (focus-if-open-else-launch) for
        // Appearance/About, cycleWallpaper (apply + persist) for the wallpaper step.
        this.contextMenu = new ContextMenu({
            onOpenApp: (slug) => this.openApp(slug),
            onCycleWallpaper: () => this.cycleWallpaper(),
        });
        this.contextMenu.attach(mount);

        // S2 -- opening either floating menu closes the other. The display server owns both, so
        // it does the cross-close here rather than coupling the two classes (context-menu.js is
        // UNCHANGED per the plan). Wrap the context menu's open() to first close the system menu.
        const openContext = this.contextMenu.open.bind(this.contextMenu);
        this.contextMenu.open = (x, y) => {
            this.systemMenu.close();
            openContext(x, y);
        };
    }

    // Toggle the user-icon dropdown (plan system-menu D3/D4). Opening it closes the desktop
    // context menu (S2 -- one floating menu at a time). The SystemMenu's own anchor-ignore guard
    // (S1) makes a click on the open button close it (no close-then-reopen race).
    toggleSystemMenu() {
        if (!this.systemMenu.isOpen()) {
            this.contextMenu.close();
        }
        this.systemMenu.toggle();
    }

    // The context menu (Task 8) + tests call these -- the shared apply+persist path (D2/D7).
    // cycleWallpaper steps #sx-desktop's wallpaper + persists; applyPref applies a single pref
    // (instant) + persists. Both fire-and-forget, like a pref-button click.
    cycleWallpaper() {
        cycleWallpaper(savePref);
    }

    applyPref(key, value) {
        applyPref(key, value);
        savePref(key, value);
    }

    // Open the launcher overlay (the app grid behind the panel's system-x button, D5). The
    // panel's launcher button calls this; picking a tile runs openApp(slug) + closes.
    openLauncher() {
        this.launcher.open();
    }

    // Log out from the panel tray (Task 7, D6). The floating <form> is gone, so the tray
    // control is the sole logout: build a real POST to the logout URL carrying the csrf token
    // (the same token the boot blade stamps, desktop.blade.php:6) and submit it -- a full
    // navigation to /login, no fetch/JSON. A submitted form is the simplest correct CSRF
    // path (no header plumbing) and matches the server's web-guard logout redirect.
    // logoutUrl comes from window.sxConfig (packaging spec §4) so sub-path installs resolve.
    logout() {
        const token = window.sxConfig?.csrfToken
            ?? document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
            ?? '';
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = window.sxConfig?.logoutUrl ?? '/logout';
        const field = document.createElement('input');
        field.type = 'hidden';
        field.name = '_token';
        field.value = token;
        form.appendChild(field);
        document.body.appendChild(form);
        form.submit();
    }

    async boot() {
        // 1. Initial render (also the resync primitive): LOOP the ADOPTED windows (S5) --
        //    the user's open set the shell painted, whatever its size, NOT a fixed pair.
        //    Fetch each window's OWN bag via fetchDesktop(windowId) and apply its frame.
        //    Done FIRST so the windows paint even if the WS subscribe below is slow/failing.
        await this.resyncAll();

        // 2. Subscribe ONCE: BOTH "result of my own event" and "unsolicited server push"
        //    arrive here as the same inbound .desktop.rendered frame, routed by
        //    payload.window -> one render path for every window.
        //    NOTE the leading dot -- it matches broadcastAs('desktop.rendered'). Without
        //    it the listener silently never fires.
        window.Echo.private(`user.${this.desktopId}`)
            .listen('.desktop.rendered', (payload) => {
                this.applyFrame(payload);
            });

        // 3. Reconnect affordance + resync, driven off the underlying pusher state machine.
        this.bindConnectionState();
    }

    // Fetch + apply EVERY adopted window's tree. This is the boot's initial render and the
    // reconnect resync (S5): both iterate the WM's LIVE window map -- the open set the shell
    // painted PLUS any launched ULID surfaces (B4) -- so a reconnect repaints them instead
    // of leaving a window stale. The resync GET returns the BARE tree (response()->json($tree)),
    // not a wrapped {app, window, tree} frame -- so we know the window here and apply it by id
    // directly, rather than routing through applyFrame (which keys off an inbound payload.window).
    async resyncAll() {
        await Promise.all(
            this.wm.windowIds().map(async (windowId) => {
                const tree = await fetchDesktop(windowId);
                this.applyFrame({ window: windowId, tree });
            }),
        );
    }

    surfaceFor(windowId) {
        return this.wm.surfaceFor(windowId);
    }

    // Route an inbound {app, window, tree} frame to its surface; ignore a window NOT in the
    // live map (frames never mint surfaces -- a launch mints via the WM first, Task 8, then
    // its frame routes). The open set is durable server state -- the client no longer tracks
    // a count.
    applyFrame(payload) {
        const surface = this.surfaceFor(payload.window);
        if (!surface) {
            return;
        }
        reconcile(surface, payload.tree, {
            registry,
            emit: (widget, event, value, window, app) => this.onEvent(widget, event, value, window, app),
        });

        // Plan 5e: a window restored as MAXIMISED on boot had no control row when the WM applied
        // its geometry (the tree hadn't hydrated). Now the controls are painted, sync the
        // maximise/restore control to the surface's max state -- a reloaded-maximised window must
        // show RESTORE, not a dead maximise button. A no-op for every non-maximised window.
        this.wm.syncMaximiseControl(payload.window);

        // B1/D5 -- the client seeds the Appearance window's pressed-state. The App is a STATIC
        // render with NO pressed cue (no principal at render); the SERVER never seeds it. Now
        // the buttons are painted (the reconcile above ran, S4), seed from the LIVE root attr:
        // read <html>'s theme/accent/panel + #sx-desktop's wallpaper (the no-flash boot stamped
        // them, D4) + press the matching control. Guard on the appearance app OR a [data-sx-pref]
        // node existing, so it's a no-op for every other surface (and before the buttons exist).
        if (surface.dataset.app === 'appearance' || surface.querySelector?.('[data-sx-pref]')) {
            seedPressed();
        }

        // B1/D5 -- the client seeds the Manage-apps toggles, mirroring seedPressed. The App is a
        // STATIC render with no install/uninstall cue (no principal); the SERVER never seeds it.
        // Now the toggles are painted, seed each from the launcher's LIVE in-memory set
        // (launcher.hasApp -- NOT the grid DOM, which is empty while the launcher is closed, B1).
        // Guard on the manage-apps app OR a [data-sx-app-action] node existing -- a no-op for every
        // other surface (and before the toggles exist).
        if (surface.dataset.app === 'apps' || surface.querySelector?.('[data-sx-app-action]')) {
            seedAppActions(this.launcher);
        }

        // The Audit app's Raw shell is filled CLIENT-side (audit plan §7): when an 'audit' surface
        // reconciles, fetch the viewer-scoped trail and paint it. A no-op for every other surface.
        if (surface.dataset.app === 'audit') {
            renderAuditView(surface, fetchAudit);
        }
    }

    // Launch a window (Plan 5a, D7, Task 8). POST the app, then mint-or-focus by window id:
    // the server is singleton-per-app (S4), so a re-launch of an open app returns the SAME
    // window id -- a surface already exists for it, so we DON'T mint a second, we just raise
    // it. A genuinely new window gets a freshly minted surface (cascade-positioned + in the
    // map BEFORE the tree paints, the D3 first-paint case), the tree reconciled in, raised.
    async launch(app) {
        const { app: a, window, tree, title, icon } = await launchWindow(app);

        // Merge the launch response's metadata (D2) into the app map so the new window's panel
        // button labels correctly -- the boot blob seeds the open set; this covers apps launched
        // at runtime. Keyed by app slug (CORE apps are singletons, so app == window title).
        if (title !== undefined || icon !== undefined) {
            this.windowMeta.byApp[a] = { title: title ?? a, icon: icon ?? 'window' };
        }

        // Only mint when there is no surface yet -- a singleton re-launch returns an already
        // open window, so minting again would duplicate the DOM node. applyFrame routes by
        // window id either way (it no-ops for a window with no surface, so mint must run
        // first for a new one). bring() raises + focuses, new or re-launched alike.
        if (!this.surfaceFor(window)) {
            this.wm.mintSurface(window, a);
        }
        this.applyFrame({ app: a, window, tree });
        this.wm.bring(window);
    }

    // The launcher's pick trigger (D5/D8). Singleton behaviour: if a window for this app is
    // already open, FOCUS it (the mockup's openApp); otherwise launch a fresh one. CORE apps
    // (hello/notes) are singletons; the launcher that spawns duplicates is a later relaxation.
    async openApp(app) {
        for (const [windowId, surface] of this.wm.surfaces) {
            if (surface.dataset.app === app) { this.wm.bring(windowId); return; }
        }
        await this.launch(app);
    }

    async onEvent(widget, event, value, window, app) {
        // Fire-and-forget upstream. The render comes back over the WS, through the
        // .desktop.rendered listener -- NOT from this POST. State is durable server-
        // side now: the client echoes NO count. The live value (TextField string /
        // Checkbox boolean) still rides up in the existing state seam. The {app, window}
        // split (Task 6): `window` (the surface the event bubbled through) addresses that
        // window's bag; `app` names the App to run -- the server gates the event on the
        // window's open-set membership and runs `app` against it.
        const res = await sendEvent({ widget, event, value, window, app });
        if (res?.error) showToast(`Something went wrong in ${res.error.app}.`);
    }

    bindConnectionState() {
        const pusher = window.Echo.connector.pusher;

        pusher.connection.bind('state_change', ({ previous, current }) => {
            if (current === 'connected') {
                this.hideReconnecting();
                // Reverb does not replay frames missed while the socket was down, so
                // resync from the authoritative tree -- but ONLY on a genuine reconnect.
                // boot() already did the initial fetch, so the first connect must not
                // re-fetch on top of it. The resync loops EVERY adopted window (S5) -- a
                // single re-fetch here would silently leave another window stale.
                if (this.hasConnected) {
                    this.resyncAll();
                }
                this.hasConnected = true;
            } else {
                // connecting / unavailable / disconnected / failed: freeze the screen
                // (leave windows in the DOM) and show the affordance. pusher-js
                // auto-reconnects with backoff -- do NOT call connect() ourselves.
                this.showReconnecting(current);
            }
        });
    }

    showReconnecting(state) {
        let badge = this.mount.querySelector('.sx-reconnecting');
        if (!badge) {
            badge = document.createElement('div');
            badge.className = 'sx-reconnecting';
            this.mount.appendChild(badge);
        }
        badge.textContent = `Reconnecting (${state})...`;
    }

    hideReconnecting() {
        this.mount.querySelector('.sx-reconnecting')?.remove();
    }
}
