import { describe, it, expect, beforeEach, vi } from 'vitest';
import { interceptAppAction, seedAppActions } from '../../resources/js/system-x/manage-apps.js';

// A minimal launcher stub -- the interceptor only touches hasApp/addApp/removeApp (the in-memory
// set, B1). The real Launcher is unit-tested in launcher.test.js.
function fakeLauncher(installed = []) {
    const set = new Set(installed);
    return {
        set,
        hasApp: (slug) => set.has(slug),
        addApp: vi.fn((meta) => set.add(meta.slug)),
        removeApp: vi.fn((slug) => set.delete(slug)),
        installedSlugs: () => [...set],
    };
}

// A minimal WM stub -- a surfaces Map + a removeSurface spy + a close spy (which must NOT be
// called on uninstall, B2). Each surface is a bare element carrying dataset.app.
function fakeWm(surfaces = {}) {
    const map = new Map();
    for (const [id, app] of Object.entries(surfaces)) {
        const el = document.createElement('div');
        el.dataset.app = app;
        map.set(id, el);
    }
    return {
        surfaces: map,
        removeSurface: vi.fn(),
        close: vi.fn(), // B2 -- this MUST NOT be called on uninstall
    };
}

// Build a Manage-apps toggle button carrying the app-action hook + its meta (slug/title/icon).
function toggleButton(slug, { title = slug, icon = 'window' } = {}) {
    const btn = document.createElement('button');
    btn.setAttribute('data-sx-app-action', slug);
    btn.dataset.sxAppTitle = title;
    btn.dataset.sxAppIcon = icon;
    document.body.appendChild(btn);
    return btn;
}

describe('interceptAppAction (App-install plan, D5 -- the toggle interceptor)', () => {
    beforeEach(() => {
        document.body.replaceChildren();
    });

    it('an INSTALLED toggle click -> uninstall: POST + launcher.removeApp + flips the button to Install', async () => {
        const launcher = fakeLauncher(['hello']);
        const wm = fakeWm();
        const transport = { installApp: vi.fn(async () => {}), uninstallApp: vi.fn(async () => {}) };
        const btn = toggleButton('hello', { title: 'Hello', icon: 'window' });

        await interceptAppAction({ target: btn }, { launcher, wm, transport });

        expect(transport.uninstallApp).toHaveBeenCalledWith('hello');
        expect(transport.installApp).not.toHaveBeenCalled();
        expect(launcher.removeApp).toHaveBeenCalledWith('hello');
        // The button flips to the Install state via a DIRECT DOM mutation (S4), not a re-seed.
        expect(btn.dataset.sxInstalled).toBe('false');
        expect(btn.textContent).toBe('Install');
    });

    it('uninstall closes the app open surfaces via wm.removeSurface DIRECTLY (B2 -- never wm.close)', async () => {
        const launcher = fakeLauncher(['hello']);
        const wm = fakeWm({ 'win-1': 'hello', 'win-2': 'notes', 'win-3': 'hello' });
        const transport = { installApp: vi.fn(), uninstallApp: vi.fn(async () => {}) };
        const btn = toggleButton('hello');

        await interceptAppAction({ target: btn }, { launcher, wm, transport });

        // Both hello surfaces are removed via removeSurface; the notes surface is left alone.
        expect(wm.removeSurface).toHaveBeenCalledWith('win-1');
        expect(wm.removeSurface).toHaveBeenCalledWith('win-3');
        expect(wm.removeSurface).not.toHaveBeenCalledWith('win-2');
        // B2 -- the live close NEVER routes through wm.close (which would POST /wm/close -> 403).
        expect(wm.close).not.toHaveBeenCalled();
    });

    it('an UNINSTALLED toggle click -> install: POST + launcher.addApp(meta) + flips the button to Uninstall', async () => {
        const launcher = fakeLauncher([]); // hello not installed
        const wm = fakeWm();
        const transport = { installApp: vi.fn(async () => {}), uninstallApp: vi.fn() };
        const btn = toggleButton('hello', { title: 'Hello', icon: 'window' });

        await interceptAppAction({ target: btn }, { launcher, wm, transport });

        expect(transport.installApp).toHaveBeenCalledWith('hello');
        expect(transport.uninstallApp).not.toHaveBeenCalled();
        expect(launcher.addApp).toHaveBeenCalledWith({ slug: 'hello', title: 'Hello', icon: 'window' });
        expect(btn.dataset.sxInstalled).toBe('true');
        expect(btn.textContent).toBe('Uninstall');
    });

    it('a click NOT on a [data-sx-app-action] element is ignored (no POST)', async () => {
        const launcher = fakeLauncher(['hello']);
        const wm = fakeWm();
        const transport = { installApp: vi.fn(), uninstallApp: vi.fn() };
        const plain = document.createElement('button');
        document.body.appendChild(plain);

        const handled = await interceptAppAction({ target: plain }, { launcher, wm, transport });

        expect(handled).toBe(false);
        expect(transport.installApp).not.toHaveBeenCalled();
        expect(transport.uninstallApp).not.toHaveBeenCalled();
    });

    it('the in-flight lock (S1): a rapid double-click resolves to ONE POST / one consistent state', async () => {
        const launcher = fakeLauncher(['hello']);
        const wm = fakeWm();
        // A pending (never-resolving) uninstall so the lock is still held on the 2nd click.
        let resolve;
        const transport = {
            installApp: vi.fn(),
            uninstallApp: vi.fn(() => new Promise((r) => { resolve = r; })),
        };
        const btn = toggleButton('hello');

        const first = interceptAppAction({ target: btn }, { launcher, wm, transport });
        // The second click lands while the first POST is still in flight.
        const second = await interceptAppAction({ target: btn }, { launcher, wm, transport });

        expect(second).toBe(false); // the 2nd click is a no-op
        expect(transport.uninstallApp).toHaveBeenCalledTimes(1); // exactly one POST
        expect(transport.installApp).not.toHaveBeenCalled(); // no interleaved install

        resolve();
        await first;
    });

    it('the in-flight lock releases on settle so a later toggle works again', async () => {
        const launcher = fakeLauncher(['hello']);
        const wm = fakeWm();
        const transport = { installApp: vi.fn(async () => {}), uninstallApp: vi.fn(async () => {}) };
        const btn = toggleButton('hello');

        await interceptAppAction({ target: btn }, { launcher, wm, transport }); // uninstall
        // hello is now uninstalled (the stub set updated) -> the next click installs.
        await interceptAppAction({ target: btn }, { launcher, wm, transport }); // install

        expect(transport.uninstallApp).toHaveBeenCalledTimes(1);
        expect(transport.installApp).toHaveBeenCalledTimes(1);
    });
});

describe('seedAppActions (App-install plan, B1 -- seed each toggle from the IN-MEMORY set)', () => {
    beforeEach(() => {
        document.body.replaceChildren();
    });

    it('seeds each toggle installed/uninstalled from launcher.hasApp, reading the in-memory set (NOT the DOM)', () => {
        // The launcher is CLOSED (its grid DOM is empty) -- the seed MUST read launcher.hasApp,
        // not a grid-DOM query, or every toggle seeds as uninstalled (B1).
        const launcher = fakeLauncher(['hello']); // hello installed, notes NOT
        const installed = toggleButton('hello');
        const uninstalled = toggleButton('notes');

        seedAppActions(launcher);

        // An INSTALLED app -> the toggle shows the "Uninstall" state.
        expect(installed.dataset.sxInstalled).toBe('true');
        expect(installed.textContent).toBe('Uninstall');
        // An UNINSTALLED app -> the toggle shows the "Install" state.
        expect(uninstalled.dataset.sxInstalled).toBe('false');
        expect(uninstalled.textContent).toBe('Install');
    });

    it('is a safe no-op when there are no toggles in the DOM yet', () => {
        const launcher = fakeLauncher(['hello']);
        expect(() => seedAppActions(launcher)).not.toThrow();
    });
});
