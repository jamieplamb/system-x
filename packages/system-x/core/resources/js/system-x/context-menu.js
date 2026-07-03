// The desktop right-click context menu (Plan 5b-2, D7) -- body-mounted client chrome,
// ported from the design ContextMenu (docs/.../navigation/ContextMenu.jsx). A floating menu
// that opens on a BARE-DESKTOP right-click only (Open Appearance / Cycle wallpaper / --- /
// About system-x). Items route to onOpenApp(slug) / onCycleWallpaper() -- the same
// focus-if-open-else-launch + apply+persist paths the launcher + pref buttons use.
//
// GUARD (the landmine): the contextmenu listener is on .sx-desktop but fires ONLY when the
// target IS the bare background (the mount, or a node carrying the .sx-desktop class). A
// right-click on a WINDOW surface (or its content, or the panel) FAILS the guard, so the
// listener returns WITHOUT preventDefault -- the browser's native menu passes through (so a
// window's text stays selectable/copyable). preventDefault is conditional, never blanket.
//
// Mount: the floating menu is appended to document.body (B4), z 100003 -- ABOVE the panel
// (100000), the badge (100001), and the launcher (100002), so it sits over everything. It
// closes on select / outside-mousedown / Escape, or it would linger.
import { icon } from './icons.js';

export class ContextMenu {
    constructor({ onOpenApp = () => {}, onCycleWallpaper = () => {} } = {}) {
        this.onOpenApp = onOpenApp;
        this.onCycleWallpaper = onCycleWallpaper;
        this.el = null;
        this.items = [
            { id: 'appearance', label: 'Open Appearance', icon: 'gear', run: () => this.onOpenApp('appearance') },
            { id: 'cycle-wallpaper', label: 'Cycle wallpaper', icon: 'window', run: () => this.onCycleWallpaper() },
            '---',
            { id: 'about', label: 'About system-x', icon: 'info', run: () => this.onOpenApp('about') },
        ];

        // The dismiss listeners are wired ONCE in the ctor but guard on isOpen(), so they're
        // inert until a menu is open. Bound once so add/remove pairs match.
        this.onOutside = (e) => {
            if (this.isOpen() && !this.el.contains(e.target)) {
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

    attach(mount) {
        // GUARD (D7): fire ONLY on the bare desktop background -- e.target IS the .sx-desktop
        // mount (NOT a window surface or the panel). A right-click on a window fails the guard
        // and the native browser menu passes through (no preventDefault).
        mount.addEventListener('contextmenu', (e) => {
            if (e.target !== mount && !e.target.classList?.contains('sx-desktop')) {
                return;
            }
            e.preventDefault();
            this.open(e.clientX, e.clientY);
        });
    }

    isOpen() {
        return this.el !== null;
    }

    // Render the floating menu at the click coords, body-mounted at z 100003. Any already-open
    // menu is closed first so a second right-click never stacks two. Callers may pass their OWN
    // item list (the launcher's per-tile new/move items) -- with none given we fall back to the
    // desktop's default this.items, so the bare-desktop menu keeps working unchanged.
    open(x, y, items = this.items) {
        this.close();

        const menu = document.createElement('div');
        menu.className = 'sx-context-menu';
        menu.setAttribute('role', 'menu');
        menu.style.left = `${x}px`;
        menu.style.top = `${y}px`;

        for (const item of items) {
            if (item === '---') {
                const sep = document.createElement('div');
                sep.className = 'sx-context-sep';
                menu.appendChild(sep);
                continue;
            }

            const row = document.createElement('div');
            row.className = 'sx-context-item';
            row.setAttribute('role', 'menuitem');
            row.setAttribute('data-sx-menu', item.id);

            const glyph = document.createElement('span');
            glyph.className = 'sx-context-item-icon';
            glyph.appendChild(icon(item.icon ?? 'window', 14));
            row.appendChild(glyph);

            const label = document.createElement('span');
            label.className = 'sx-context-item-label';
            label.textContent = item.label;
            row.appendChild(label);

            // Select: run the item, then close. The run path (openApp / cycleWallpaper) is the
            // same one the launcher + pref buttons use -- this menu is just another trigger.
            row.addEventListener('click', () => {
                item.run();
                this.close();
            });

            menu.appendChild(row);
        }

        document.body.appendChild(menu);
        this.el = menu;
    }

    close() {
        this.el?.remove();
        this.el = null;
    }
}
