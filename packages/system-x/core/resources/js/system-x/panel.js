// The desktop top panel (Plan 5b, D1/D3) -- CLIENT chrome that MIRRORS the WM's window
// list. Ported from the design Panel.jsx (which defaults to the BOTTOM; 5b CORE is TOP,
// Jamie's scope call -- same visual treatment, top edge). The panel never tracks its own
// window list: render(list) REBUILDS the running-window row from wm.windows() every call
// (the rebuild-every-render rule -- a cached/patched row could drift from the WM; it's a
// handful of buttons, cheap, and it CANNOT desync). A button click calls back into the WM
// (bring/minimise) -> the WM mutates + notifies -> the display server re-renders -- the
// panel never mutates WM state directly.
//
// Mount: the display server appends this.el to document.body (B4), NOT inside #sx-desktop
// (which is overflow:hidden and carries the WM's mount-scoped pointerdown/click listeners --
// a panel click in body can never be misread as a window focus or a desktop blur). The
// panel wires its OWN click handlers. The Panel class takes its host as a ctor arg, so a
// unit test can hand it a test root; the mount-point decision is the display server's.
import { icon } from './icons.js';
import { startClock, paintClock } from './clock.js';
import { initials } from './system-menu.js';

export class Panel {
    // metadataFor(app)   -> {title, icon} for an app slug (the D2 join: boot map + launch
    //                       merge; the display server falls back to {title: app, icon:'window'}).
    // onSelectWindow(id, active) -> a running-window button was clicked: active -> minimise,
    //                       else -> bring (the mockup's panel-click toggle, index.html:398-402).
    // onLaunch()         -> the launcher button (the system-x start button) was clicked (Task 6).
    // userName           -> the logged-in user's name (boot blob, plan system-menu D5) -- the
    //                       tray button shows their initials (or the user glyph if blank).
    // onUserMenu(btnEl)  -> the tray user button was clicked; hands the display server the
    //                       BUTTON ELEMENT (the SystemMenu anchors to it -- the toggle-vs-close
    //                       guard needs the element, S1, not just a rect). Logout moved INTO
    //                       that menu, so the button is no longer the logout trigger.
    constructor(host, { metadataFor, onSelectWindow, onLaunch = () => {}, userName = '', onUserMenu = () => {} } = {}) {
        this.host = host;
        this.metadataFor = metadataFor ?? ((app) => ({ title: app, icon: 'window' }));
        this.onSelectWindow = onSelectWindow ?? (() => {});
        this.onLaunch = onLaunch;
        this.userName = userName;
        this.onUserMenu = onUserMenu;

        this.el = document.createElement('div');
        this.el.className = 'sx-panel';

        // The left launcher button (Task 6 builds the overlay it opens). icon + "system-x".
        this.launcher = document.createElement('button');
        this.launcher.type = 'button';
        this.launcher.className = 'sx-panel-launcher';
        this.launcher.setAttribute('data-sx-launcher', '');
        this.launcher.appendChild(icon('launcher', 15));
        const launcherLabel = document.createElement('span');
        launcherLabel.textContent = 'system-x';
        this.launcher.appendChild(launcherLabel);
        this.launcher.addEventListener('click', () => this.onLaunch());
        this.el.appendChild(this.launcher);

        // The separator (a thin groove) between the launcher and the running-window row.
        const sep = document.createElement('div');
        sep.className = 'sx-panel-sep';
        this.el.appendChild(sep);

        // The running-window row -- render(list) rebuilds its contents every call.
        this.windowsRow = document.createElement('div');
        this.windowsRow.className = 'sx-panel-windows';
        this.el.appendChild(this.windowsRow);

        // The right tray: the clock (startClock ticks it) + the user-menu button. The clock
        // starts blank -- startClock() paints it. The tray is built ONCE in the ctor and NEVER
        // rebuilt -- render() only touches windowsRow (S3), so the user button (the SystemMenu's
        // stable anchor) survives every WM change.
        const tray = document.createElement('div');
        tray.className = 'sx-panel-tray';

        this.clock = document.createElement('span');
        this.clock.className = 'sx-panel-clock';
        this.clock.setAttribute('data-sx-clock', '');
        tray.appendChild(this.clock);

        // The user-menu button (plan system-menu D4) -- the SystemMenu's anchor. It shows the
        // user's INITIALS (the greeter's multibyte logic, S4) or the user glyph if no name.
        // Clicking it hands the display server THIS element (onUserMenu(btn)); the display
        // server toggles the SystemMenu anchored to it. Logout moved into that menu.
        this.userBtn = document.createElement('button');
        this.userBtn.type = 'button';
        this.userBtn.className = 'sx-panel-user';
        this.userBtn.setAttribute('data-sx-user-menu', '');
        this.userBtn.setAttribute('aria-label', 'User menu');
        this.userBtn.setAttribute('aria-haspopup', 'menu');
        this.userBtn.title = this.userName || 'User menu';
        const userInitials = initials(this.userName);
        if (userInitials) {
            const avatar = document.createElement('span');
            avatar.className = 'sx-panel-user-initials';
            avatar.textContent = userInitials;
            this.userBtn.appendChild(avatar);
        } else {
            this.userBtn.appendChild(icon('user', 14));
        }
        this.userBtn.addEventListener('click', () => this.onUserMenu(this.userBtn));
        tray.appendChild(this.userBtn);

        this.el.appendChild(tray);

        // The clock's stop fn -- null until startClock(), called + nulled by stopClock().
        this.clockStop = null;
    }

    // Start the tray clock (Task 7, D6 -- the shared clock.js util, Plan 5c Task 5). Paint at
    // ONCE then repaint on the 15s interval; the util scopes to this.el (the tray has a
    // [data-sx-clock] but NO [data-sx-date], so only the time paints). The display server calls
    // this once at boot. Idempotent: a second start clears the prior timer so we never stack two.
    startClock() {
        this.stopClock();
        this.clockStop = startClock(this.el);
    }

    // Stop the clock's interval (tidy teardown; the panel is never torn down in prod, but a
    // unit test + a re-start both rely on this clearing the handle).
    stopClock() {
        if (this.clockStop !== null) {
            this.clockStop();
            this.clockStop = null;
        }
    }

    // Paint the current time into the tray clock as HH:MM (en-GB, 24h, 2-digit) -- the design's
    // mono tray clock treatment (the .sx-panel-clock CSS is --sx-font-mono). Delegates to the
    // shared util so the format stays in lockstep with the greeter.
    renderClock() {
        paintClock(this.el);
    }

    // Rebuild the running-window row from the WM's window list (5b D3). Called by the display
    // server on every WM onChange + once at boot. Each entry {id, app, active, minimised}
    // becomes one button: its title + icon JOINED from the metadata map by app slug (D2),
    // data-sx-active = the focused one (pressed), data-sx-min = minimised (dimmed). Clicking
    // it calls onSelectWindow(id, active) -- the WM does the work, never the panel. REBUILD
    // every render (no cached buttons), so the panel can't drift from the WM.
    render(list = []) {
        this.windowsRow.replaceChildren();
        for (const w of list) {
            const meta = this.metadataFor(w.app) ?? { title: w.app, icon: 'window' };
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'sx-panel-window';
            // data-sx-panel-window is the panel button's OWN id hook (Dusk + the click).
            // Deliberately NOT data-window-id -- that selector addresses the WM's SURFACES,
            // and the panel lives in body; sharing it would make an unscoped
            // [data-window-id="notes"] match both the surface AND its panel button (a false
            // "duplicate window" -- WmLaunchTest counts exactly that).
            btn.setAttribute('data-sx-panel-window', w.id);
            btn.dataset.sxActive = w.active ? 'true' : 'false';
            btn.dataset.sxMin = w.minimised ? 'true' : 'false';
            btn.title = meta.title;

            btn.appendChild(icon(meta.icon, 13));
            const label = document.createElement('span');
            label.className = 'sx-panel-window-label';
            label.textContent = meta.title;
            btn.appendChild(label);

            // Snapshot active AT render -- the click reads the state this button was painted
            // for (active -> minimise, else -> bring), matching the WM's current focus.
            const active = w.active;
            btn.addEventListener('click', () => this.onSelectWindow(w.id, active));

            this.windowsRow.appendChild(btn);
        }
    }
}
