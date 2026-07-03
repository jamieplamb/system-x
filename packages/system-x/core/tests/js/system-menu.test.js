import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { SystemMenu, initials } from '../../resources/js/system-x/system-menu.js';

// A stub anchor button -- the SystemMenu reads its live getBoundingClientRect() for the
// anchored positioning + uses the element identity for the toggle-vs-close guard (S1/L1).
function buildAnchor(rect) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'sx-panel-user';
    btn.getBoundingClientRect = () => ({
        top: rect.top, bottom: rect.bottom, left: rect.left, right: rect.right,
        width: rect.right - rect.left, height: rect.bottom - rect.top, x: rect.left, y: rect.top,
    });
    document.body.appendChild(btn);
    return btn;
}

const SYSTEM_APPS = [
    { slug: 'appearance', title: 'Appearance', icon: 'gear', system: true },
    { slug: 'about', title: 'About system-x', icon: 'info', system: true },
    { slug: 'apps', title: 'Manage apps', icon: 'launcher', system: true },
];

describe('SystemMenu (D3 -- the anchored user dropdown)', () => {
    let anchor, opened, loggedOut, menu;

    beforeEach(() => {
        document.body.replaceChildren();
        // A top panel, button at the right end: viewport 1000 wide, button 800..840, panel 0..30.
        window.innerWidth = 1000;
        window.innerHeight = 700;
        anchor = buildAnchor({ top: 0, bottom: 30, left: 800, right: 840 });
        opened = [];
        loggedOut = 0;
        menu = new SystemMenu({
            anchor,
            panelPosition: 'top',
            systemApps: SYSTEM_APPS,
            userName: 'Demo User',
            onOpenApp: (slug) => opened.push(slug),
            onLogout: () => loggedOut++,
        });
    });

    afterEach(() => {
        menu.close();
    });

    it('opens a .sx-system-menu with a name header, an item per system app, a separator, and Log out', () => {
        menu.open();
        const el = document.querySelector('.sx-system-menu');
        expect(el).not.toBeNull();

        const header = el.querySelector('.sx-system-menu-header');
        expect(header.textContent).toBe('Demo User');

        expect(el.querySelector('[data-sx-menu="appearance"]')).not.toBeNull();
        expect(el.querySelector('[data-sx-menu="about"]')).not.toBeNull();
        expect(el.querySelector('[data-sx-menu="apps"]')).not.toBeNull();
        expect(el.querySelector('.sx-context-sep')).not.toBeNull();

        const logout = el.querySelector('[data-sx-menu="logout"]');
        expect(logout).not.toBeNull();
        expect(logout.getAttribute('dusk')).toBe('logout');
    });

    it('clicking a system-app item calls onOpenApp(slug) and closes', () => {
        menu.open();
        document.querySelector('[data-sx-menu="appearance"]').click();
        expect(opened).toEqual(['appearance']);
        expect(menu.isOpen()).toBe(false);
    });

    it('clicking Log out calls onLogout and closes', () => {
        menu.open();
        document.querySelector('[data-sx-menu="logout"]').click();
        expect(loggedOut).toBe(1);
        expect(menu.isOpen()).toBe(false);
    });

    it('dismisses on an outside mousedown', () => {
        menu.open();
        document.body.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
        expect(menu.isOpen()).toBe(false);
    });

    it('dismisses on Escape', () => {
        menu.open();
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        expect(menu.isOpen()).toBe(false);
    });

    it('renders the name via textContent (a <script> name is text, not a node) -- XSS', () => {
        const evil = new SystemMenu({
            anchor, panelPosition: 'top', systemApps: SYSTEM_APPS,
            userName: '<script>alert(1)</script>', onOpenApp: () => {}, onLogout: () => {},
        });
        evil.open();
        const header = document.querySelector('.sx-system-menu-header');
        expect(header.querySelector('script')).toBeNull();
        expect(header.textContent).toBe('<script>alert(1)</script>');
        evil.close();
    });

    it('toggle() opens when closed and closes when open', () => {
        expect(menu.isOpen()).toBe(false);
        menu.toggle();
        expect(menu.isOpen()).toBe(true);
        menu.toggle();
        expect(menu.isOpen()).toBe(false);
    });

    // L1 / S1 -- the toggle-vs-outside-close guard. The anchor is OUTSIDE the menu element, so
    // a naive outside-handler would close-on-mousedown then the button's click re-opens (it
    // never closes by its own button). The fix: the outside-handler ignores a mousedown on the
    // anchor. The button owns the toggle, so a click on the open button CLOSES it.
    it('a mousedown on the anchor while open does NOT trigger the outside-close (L1)', () => {
        menu.open();
        const e = new MouseEvent('mousedown', { bubbles: true });
        Object.defineProperty(e, 'target', { value: anchor });
        document.dispatchEvent(e);
        // The outside-handler ignored the anchor -- still open (the button's own click toggles).
        expect(menu.isOpen()).toBe(true);
    });

    it('toggling via the anchor click leaves it CLOSED on even taps (L1)', () => {
        // Emulate the panel's onUserMenu -> toggle wiring: each "click" fires a mousedown
        // (the outside-handler, ignored for the anchor) then the toggle.
        const tap = () => {
            const e = new MouseEvent('mousedown', { bubbles: true });
            Object.defineProperty(e, 'target', { value: anchor });
            document.dispatchEvent(e);
            menu.toggle();
        };
        tap(); // open
        expect(menu.isOpen()).toBe(true);
        tap(); // close
        expect(menu.isOpen()).toBe(false);
        tap(); // open
        expect(menu.isOpen()).toBe(true);
        tap(); // close
        expect(menu.isOpen()).toBe(false);
    });

    // L2 / B2 -- TOP panel opens DOWN, right-aligned to the button.
    it('TOP panel positions the menu opening down, right-aligned (L2)', () => {
        menu.open();
        const el = document.querySelector('.sx-system-menu');
        // right = innerWidth - rect.right = 1000 - 840 = 160
        expect(el.style.right).toBe('160px');
        expect(el.style.top).toBe('30px'); // rect.bottom
        expect(el.style.bottom).toBe('');
    });

    // L2 / B2 -- BOTTOM panel opens UP: anchor the menu's BOTTOM to the button's top.
    it('BOTTOM panel positions the menu opening up (bottom anchored, top auto) (L2)', () => {
        const bottomAnchor = buildAnchor({ top: 670, bottom: 700, left: 800, right: 840 });
        const bottomMenu = new SystemMenu({
            anchor: bottomAnchor, panelPosition: 'bottom', systemApps: SYSTEM_APPS,
            userName: 'Demo User', onOpenApp: () => {}, onLogout: () => {},
        });
        bottomMenu.open();
        const el = document.querySelector('.sx-system-menu');
        // right = 1000 - 840 = 160; bottom = innerHeight - rect.top = 700 - 670 = 30; top auto.
        expect(el.style.right).toBe('160px');
        expect(el.style.bottom).toBe('30px');
        expect(el.style.top).toBe('auto');
        bottomMenu.close();
    });

    // The panel edge is LIVE -- a mid-session top->bottom toggle sets <html> data-sx-panel; the menu
    // must read it at open time, not the value cached at construction (else it opens off the bottom edge).
    it('reads the LIVE <html> panel edge over the cached ctor value (the mid-session toggle)', () => {
        const bottomAnchor = buildAnchor({ top: 670, bottom: 700, left: 800, right: 840 });
        const liveMenu = new SystemMenu({
            anchor: bottomAnchor, panelPosition: 'top', systemApps: SYSTEM_APPS, // ctor says TOP...
            userName: 'Demo User', onOpenApp: () => {}, onLogout: () => {},
        });
        document.documentElement.dataset.sxPanel = 'bottom'; // ...but the live edge flipped to BOTTOM
        try {
            liveMenu.open();
            const el = document.querySelector('.sx-system-menu');
            expect(el.style.bottom).toBe('30px'); // opens UP, per the live edge
            expect(el.style.top).toBe('auto');
            liveMenu.close();
        } finally {
            delete document.documentElement.dataset.sxPanel;
        }
    });

    it('clamps right on-screen (never negative)', () => {
        // A button whose right edge is past the viewport would give a negative right -> clamp 0.
        const wide = buildAnchor({ top: 0, bottom: 30, left: 980, right: 1040 });
        const m = new SystemMenu({
            anchor: wide, panelPosition: 'top', systemApps: SYSTEM_APPS,
            userName: 'X', onOpenApp: () => {}, onLogout: () => {},
        });
        m.open();
        const el = document.querySelector('.sx-system-menu');
        expect(el.style.right).toBe('0px');
        m.close();
    });

    it('with no user name renders no header (the glyph fallback lives on the button)', () => {
        const m = new SystemMenu({
            anchor, panelPosition: 'top', systemApps: SYSTEM_APPS,
            userName: '', onOpenApp: () => {}, onLogout: () => {},
        });
        m.open();
        const el = document.querySelector('.sx-system-menu');
        expect(el.querySelector('.sx-system-menu-header')).toBeNull();
        m.close();
    });
});

describe('initials (S4 -- the greeter logic, multibyte-safe)', () => {
    it('"Demo User" -> "DU"', () => {
        expect(initials('Demo User')).toBe('DU');
    });

    it('a single word -> a single letter', () => {
        expect(initials('Madonna')).toBe('M');
    });

    it('first two words, capped at 2 (matches the greeter take(2), skips the rest)', () => {
        expect(initials('John Paul Smith')).toBe('JP');
    });

    it('is multibyte-safe (astral chars via Array.from/codePointAt, not charAt)', () => {
        // An emoji name -- charAt would split the surrogate pair into a broken half.
        const name = '\u{1F600}lice \u{1F4A9}ob';
        expect(initials(name)).toBe('\u{1F600}\u{1F4A9}');
    });

    it('uppercases each initial', () => {
        expect(initials('demo user')).toBe('DU');
    });

    it('an empty / whitespace name -> empty (the button uses the glyph)', () => {
        expect(initials('')).toBe('');
        expect(initials('   ')).toBe('');
    });
});
