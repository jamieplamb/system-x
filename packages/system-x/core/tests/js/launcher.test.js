import { describe, it, expect, beforeEach } from 'vitest';
import { Launcher } from '../../resources/js/system-x/launcher.js';

describe('Launcher (D5 -- the app grid, reuses openApp)', () => {
    let host, launcher, picked;
    beforeEach(() => {
        host = document.createElement('div');
        document.body.replaceChildren(host);
        picked = [];
        launcher = new Launcher(host, {
            apps: [{ slug: 'hello', title: 'Hello', icon: 'window' }, { slug: 'notes', title: 'Notes', icon: 'notes' }],
            onPick: (slug) => picked.push(slug),
        });
    });

    it('is hidden until opened', () => {
        expect(launcher.isOpen()).toBe(false);
        launcher.open();
        expect(launcher.isOpen()).toBe(true);
    });

    it('appends its overlay to its host body, above the panel (via the .sx-launcher z-tier)', () => {
        // The display server mounts the launcher on document.body (B4). The z-index now lives in
        // CSS on .sx-launcher (var(--sx-z-launcher) = 100002, above the panel 100000 + badge 100001)
        // -- the CSSOM rejects an inline var(), so it moved off the element style onto the class.
        expect(host.contains(launcher.el)).toBe(true);
        expect(launcher.el.classList.contains('sx-launcher')).toBe(true);
    });

    it('renders one tile per app with its title', () => {
        launcher.open();
        const tiles = launcher.el.querySelectorAll('[data-sx-launch]');
        expect(tiles.length).toBe(2);
        expect([...tiles].map((t) => t.textContent).join(' ')).toContain('Notes');
    });

    it('picking a tile calls onPick(slug) and closes', () => {
        launcher.open();
        launcher.el.querySelector('[data-sx-launch="notes"]').click();
        expect(picked).toEqual(['notes']);
        expect(launcher.isOpen()).toBe(false);
    });

    it('search filters tiles by title (case-insensitive)', () => {
        launcher.open();
        const search = launcher.el.querySelector('[data-sx-launcher-search]');
        search.value = 'not';
        search.dispatchEvent(new Event('input', { bubbles: true }));
        const visible = [...launcher.el.querySelectorAll('[data-sx-launch]')];
        expect(visible.map((t) => t.dataset.sxLaunch)).toEqual(['notes']);
    });

    it('a backdrop mousedown closes the overlay', () => {
        launcher.open();
        // The backdrop IS launcher.el (it carries data-sx-launcher-backdrop); a mousedown
        // landing directly on it (target === el) closes.
        expect(launcher.el.matches('[data-sx-launcher-backdrop]')).toBe(true);
        launcher.el.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
        expect(launcher.isOpen()).toBe(false);
    });

    it('a mousedown inside the panel does NOT close (only the backdrop)', () => {
        launcher.open();
        const panel = launcher.el.querySelector('[data-sx-launcher-panel]');
        panel.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
        expect(launcher.isOpen()).toBe(true);
    });

    it('Escape closes the overlay', () => {
        launcher.open();
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        expect(launcher.isOpen()).toBe(false);
    });

    it('a lone Meta tap opens the launcher when closed (start-menu toggle)', () => {
        expect(launcher.isOpen()).toBe(false);
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Meta', bubbles: true }));
        document.dispatchEvent(new KeyboardEvent('keyup', { key: 'Meta', bubbles: true }));
        expect(launcher.isOpen()).toBe(true);
    });

    it('a lone Meta tap closes the launcher when open (toggle)', () => {
        launcher.open();
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Meta', bubbles: true }));
        document.dispatchEvent(new KeyboardEvent('keyup', { key: 'Meta', bubbles: true }));
        expect(launcher.isOpen()).toBe(false);
    });

    it('Meta + another key (a combo) does NOT toggle the launcher', () => {
        // Cmd+C / Win+anything must not hijack -- the combo flag suppresses the toggle.
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Meta', bubbles: true }));
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'c', bubbles: true }));
        document.dispatchEvent(new KeyboardEvent('keyup', { key: 'c', bubbles: true }));
        document.dispatchEvent(new KeyboardEvent('keyup', { key: 'Meta', bubbles: true }));
        expect(launcher.isOpen()).toBe(false);
    });

    it('a keyup of a non-Meta key alone does nothing', () => {
        document.dispatchEvent(new KeyboardEvent('keyup', { key: 'a', bubbles: true }));
        expect(launcher.isOpen()).toBe(false);
    });

    it('a blur mid-combo resets stale state so a later lone Meta tap still toggles', () => {
        // Cmd+Tab away blurs the window mid-combo (the Meta keyup never lands here). The blur
        // reset must clear metaDown/metaCombo so the next clean tap opens.
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Meta', bubbles: true }));
        window.dispatchEvent(new Event('blur'));
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Meta', bubbles: true }));
        document.dispatchEvent(new KeyboardEvent('keyup', { key: 'Meta', bubbles: true }));
        expect(launcher.isOpen()).toBe(true);
    });

    it('filters out system apps -- the grid shows only user apps (D2)', () => {
        // The launcher owns "I show user apps only": a system:true app (Appearance/About)
        // gets NO tile; a system:false / no-system-key app (hello/notes) does. The system
        // apps live in the user-icon dropdown (Task 3), not the launcher.
        const host2 = document.createElement('div');
        document.body.replaceChildren(host2);
        const mixed = new Launcher(host2, {
            apps: [
                { slug: 'hello', title: 'Hello', icon: 'window', system: false },
                { slug: 'appearance', title: 'Appearance', icon: 'gear', system: true },
                { slug: 'apps', title: 'Manage apps', icon: 'launcher', system: true },
            ],
            onPick: () => {},
        });
        mixed.open();
        expect(mixed.el.querySelector('[data-sx-launch="hello"]')).not.toBeNull();
        expect(mixed.el.querySelector('[data-sx-launch="appearance"]')).toBeNull();
        expect(mixed.el.querySelector('[data-sx-launch="apps"]')).toBeNull();
        expect(mixed.el.querySelectorAll('[data-sx-launch]').length).toBe(1);
    });

    it('reopening clears the prior search query', () => {
        launcher.open();
        const search = launcher.el.querySelector('[data-sx-launcher-search]');
        search.value = 'not';
        search.dispatchEvent(new Event('input', { bubbles: true }));
        expect(launcher.el.querySelectorAll('[data-sx-launch]').length).toBe(1);
        launcher.close();
        launcher.open();
        expect(launcher.el.querySelectorAll('[data-sx-launch]').length).toBe(2);
    });
});

describe('Launcher live add/remove + the in-memory seed accessors (App-install plan, B1)', () => {
    let host, launcher;
    beforeEach(() => {
        host = document.createElement('div');
        document.body.replaceChildren(host);
        launcher = new Launcher(host, {
            apps: [{ slug: 'hello', title: 'Hello', icon: 'window' }, { slug: 'notes', title: 'Notes', icon: 'notes' }],
            onPick: () => {},
            // Fake transport so live add/remove don't fire the real fetch-backed saveLayout in jsdom.
            transport: { saveLayout: () => Promise.resolve() },
        });
    });

    it('hasApp / installedSlugs read the in-memory set (the seed source)', () => {
        expect(launcher.hasApp('hello')).toBe(true);
        expect(launcher.hasApp('notes')).toBe(true);
        expect(launcher.hasApp('nope')).toBe(false);
        expect(launcher.installedSlugs().sort()).toEqual(['hello', 'notes']);
    });

    it('hasApp reflects the in-memory set EVEN WHILE THE LAUNCHER IS CLOSED (B1 -- the grid DOM is empty)', () => {
        // The seed runs while Manage-apps is open, which means the launcher is CLOSED -- its grid
        // DOM is never rendered. A grid-DOM query would read empty; hasApp reads launcher.apps.
        expect(launcher.isOpen()).toBe(false);
        expect(launcher.grid.querySelectorAll('[data-sx-launch]').length).toBe(0); // grid never rendered
        expect(launcher.hasApp('hello')).toBe(true); // ...yet the in-memory set still knows
    });

    it('removeApp drops the app from the in-memory set (and the live tile when open)', () => {
        launcher.open();
        expect(launcher.grid.querySelector('[data-sx-launch="hello"]')).not.toBeNull();

        launcher.removeApp('hello');

        expect(launcher.hasApp('hello')).toBe(false);
        expect(launcher.installedSlugs()).toEqual(['notes']);
        expect(launcher.grid.querySelector('[data-sx-launch="hello"]')).toBeNull(); // tile gone live
    });

    it('addApp adds the app to the in-memory set (and the live tile when open), idempotently', () => {
        launcher.removeApp('hello');
        expect(launcher.hasApp('hello')).toBe(false);

        launcher.open();
        launcher.addApp({ slug: 'hello', title: 'Hello', icon: 'window' });

        expect(launcher.hasApp('hello')).toBe(true);
        expect(launcher.grid.querySelector('[data-sx-launch="hello"]')).not.toBeNull();

        // Idempotent: adding it again does not duplicate the in-memory entry or the tile.
        launcher.addApp({ slug: 'hello', title: 'Hello', icon: 'window' });
        expect(launcher.installedSlugs().filter((s) => s === 'hello').length).toBe(1);
        expect(launcher.grid.querySelectorAll('[data-sx-launch="hello"]').length).toBe(1);
    });

    it('removeApp while CLOSED still drops the in-memory set, and a later open reflects it', () => {
        launcher.removeApp('hello'); // launcher is closed -- no grid to touch
        expect(launcher.hasApp('hello')).toBe(false);
        launcher.open();
        expect(launcher.grid.querySelector('[data-sx-launch="hello"]')).toBeNull();
        expect(launcher.grid.querySelector('[data-sx-launch="notes"]')).not.toBeNull();
    });

    it('the empty launcher (every app removed) shows the empty-state hint, not a blank grid (D7)', () => {
        launcher.removeApp('hello');
        launcher.removeApp('notes');
        launcher.open();

        expect(launcher.grid.querySelectorAll('[data-sx-launch]').length).toBe(0);
        const hint = launcher.el.querySelector('[data-sx-launcher-empty]');
        expect(hint).not.toBeNull();
        expect(hint.textContent).toContain('Manage apps');
    });

    it('the empty-state hint disappears once an app is added back', () => {
        launcher.removeApp('hello');
        launcher.removeApp('notes');
        launcher.open();
        expect(launcher.el.querySelector('[data-sx-launcher-empty]')).not.toBeNull();

        launcher.addApp({ slug: 'hello', title: 'Hello', icon: 'window' });
        expect(launcher.el.querySelector('[data-sx-launcher-empty]')).toBeNull();
        expect(launcher.grid.querySelector('[data-sx-launch="hello"]')).not.toBeNull();
    });
});
