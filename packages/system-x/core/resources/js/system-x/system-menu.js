// The system menu (plan system-menu, D3/D4) -- the user-icon dropdown in the panel tray.
// A body-mounted floating menu that MIRRORS the ContextMenu shape (the item/sep rendering,
// the dismiss-on-select/outside/Escape, z 100003), but ANCHORED to the tray user button
// instead of opened at a click point. It greets the user by name (a header), lists the
// SYSTEM apps (Appearance/About -- dynamic, not hardcoded), and carries the Log out item
// (logout moved here off the standalone tray button).
//
// Two landmines this class owns:
//   L1 (the toggle-vs-outside-close guard, S1): the anchor button is OUTSIDE the menu, so a
//   naive outside-mousedown handler would close-on-the-button THEN the button's click toggle
//   re-opens -- it could never close by its own button. The fix (the ignore-set): the ctor
//   takes the ANCHOR ELEMENT; the outside-handler EARLY-RETURNS for a mousedown on the anchor.
//   The button owns the toggle (a click on the open button closes it).
//   L2 (anchored positioning, BOTH panel edges, B2): the button is at the panel's right end.
//   TOP panel -> open DOWN (top = rect.bottom), right-aligned (right = innerWidth - rect.right).
//   BOTTOM panel -> open UP (bottom = innerHeight - rect.top, top = auto), or it renders off
//   the bottom edge. Clamp right on-screen either way.
import { icon } from './icons.js';

// The tray button's initials -- the greeter's logic (login.blade.php:51-54), multibyte-safe.
// Split on whitespace, take the FIRST grapheme of the FIRST TWO words (the greeter's take(2)),
// uppercase, cap 2. Aligned to the greeter so the SAME user shows the SAME initials in the
// greeter avatar + the tray button (e.g. "John Paul Smith" -> "JP", not "JS").
// Array.from / codePointAt (NOT charAt, which splits astral chars into a broken surrogate).
// An empty / whitespace name -> '' (the button then renders the user glyph fallback).
export function initials(name) {
    const parts = String(name ?? '').trim().split(/\s+/).filter(Boolean);
    if (parts.length === 0) {
        return '';
    }
    return parts
        .slice(0, 2)
        .map((part) => (Array.from(part)[0] ?? '').toUpperCase())
        .join('');
}

export class SystemMenu {
    constructor({ anchor, panelPosition = 'top', systemApps = [], userName = '', onOpenApp = () => {}, onLogout = () => {} } = {}) {
        this.anchor = anchor;
        this.panelPosition = panelPosition;
        this.systemApps = systemApps;
        this.userName = userName;
        this.onOpenApp = onOpenApp;
        this.onLogout = onLogout;
        this.el = null;

        // Wired ONCE, inert until a menu is open (they guard on isOpen()). Bound once so the
        // add/remove pairs match.
        this.onOutside = (e) => {
            if (!this.isOpen()) {
                return;
            }
            // L1: ignore a mousedown on the anchor -- the button owns the toggle, so the
            // outside-handler must never close-on-the-button (else click toggles re-open).
            if (e.target === this.anchor || this.anchor?.contains(e.target)) {
                return;
            }
            if (!this.el.contains(e.target)) {
                this.close();
            }
        };
        this.onKeydown = (e) => {
            if (this.isOpen() && e.key === 'Escape') {
                this.close();
            }
        };
        document.addEventListener('mousedown', this.onOutside);
        document.addEventListener('keydown', this.onKeydown);
    }

    isOpen() {
        return this.el !== null;
    }

    toggle() {
        if (this.isOpen()) {
            this.close();
        } else {
            this.open();
        }
    }

    open() {
        this.close();

        const menu = document.createElement('div');
        menu.className = 'sx-system-menu';
        menu.setAttribute('role', 'menu');

        // The name header -- non-interactive, via textContent (XSS-safe, NEVER innerHTML). No
        // name -> no header (the button's glyph fallback covers the anonymous case).
        if (this.userName) {
            const header = document.createElement('div');
            header.className = 'sx-system-menu-header';
            header.textContent = this.userName;
            menu.appendChild(header);
        }

        // One item per system app (dynamic, NOT hardcoded -- a future system app auto-appears).
        for (const app of this.systemApps) {
            menu.appendChild(this.buildItem({
                id: app.slug,
                label: app.title ?? app.slug,
                glyph: app.icon ?? 'window',
                run: () => this.onOpenApp(app.slug),
            }));
        }

        // The separator, then the Log out item (logout moved here, D4 -- it keeps dusk="logout").
        const sep = document.createElement('div');
        sep.className = 'sx-context-sep';
        menu.appendChild(sep);

        const logout = this.buildItem({
            id: 'logout',
            label: 'Log out',
            glyph: 'user',
            run: () => this.onLogout(),
        });
        logout.setAttribute('dusk', 'logout');
        menu.appendChild(logout);

        document.body.appendChild(menu);
        this.el = menu;
        this.position();
    }

    // Build one menu row -- mirrors the ContextMenu item shape (the .sx-context-* classes so it
    // reuses the context-menu CSS). data-sx-menu = the id, icon glyph + label, select runs + closes.
    buildItem({ id, label, glyph, run }) {
        const row = document.createElement('div');
        row.className = 'sx-context-item';
        row.setAttribute('role', 'menuitem');
        row.setAttribute('data-sx-menu', id);

        const iconWrap = document.createElement('span');
        iconWrap.className = 'sx-context-item-icon';
        iconWrap.appendChild(icon(glyph, 14));
        row.appendChild(iconWrap);

        const text = document.createElement('span');
        text.className = 'sx-context-item-label';
        text.textContent = label;
        row.appendChild(text);

        row.addEventListener('click', () => {
            run();
            this.close();
        });

        return row;
    }

    // L2 -- anchor the menu to the live button rect, branching on the panel edge. Right-aligned
    // to the button either way; opens DOWN on a top panel, UP on a bottom panel. Clamp right >= 0.
    // The edge is LIVE state -- the panel can be toggled top<->bottom mid-session (prefs.js sets
    // <html> data-sx-panel), so read it off <html> at open time, NOT a value cached at boot
    // (a cached 'top' after a live flip to bottom would open the menu off the bottom edge).
    position() {
        const rect = this.anchor.getBoundingClientRect();
        const right = Math.max(0, window.innerWidth - rect.right);
        this.el.style.right = `${right}px`;

        const edge = document.documentElement.dataset.sxPanel || this.panelPosition;
        if (edge === 'bottom') {
            // Open UP: anchor the menu's BOTTOM to the button's top, or it overflows the bottom edge.
            this.el.style.bottom = `${Math.max(0, window.innerHeight - rect.top)}px`;
            this.el.style.top = 'auto';
        } else {
            // Open DOWN: the menu's top sits at the button's bottom.
            this.el.style.top = `${rect.bottom}px`;
            this.el.style.bottom = '';
        }
    }

    close() {
        this.el?.remove();
        this.el = null;
    }
}
