import { describe, it, expect } from 'vitest';
import { Launcher } from '../../resources/js/system-x/launcher.js';

function makeLauncher(apps, layout) {
    document.body.innerHTML = '';
    // Fake transport so live add/remove don't fire the real fetch-backed saveLayout in jsdom.
    return new Launcher(document.body, { apps, onPick() {}, layout, transport: { saveLayout: () => Promise.resolve() } });
}

describe('launcher layout render', () => {
    const apps = [
        { slug: 'hello', title: 'Hello', icon: 'window' },
        { slug: 'notes', title: 'Notes', icon: 'notes' },
        { slug: 'controls', title: 'Controls', icon: 'gear' },
    ];

    it('a flat layout renders one root app-tile per app, in layout order', () => {
        const l = makeLauncher(apps, [
            { type: 'app', slug: 'notes' },
            { type: 'app', slug: 'hello' },
            { type: 'app', slug: 'controls' },
        ]);
        l.open();
        const tiles = [...l.grid.querySelectorAll(':scope > .sx-launcher-tile[data-sx-launch]')];
        expect(tiles.map((t) => t.dataset.sxLaunch)).toEqual(['notes', 'hello', 'controls']);
    });

    it('with no layout given, falls back to all apps at root (today behaviour)', () => {
        const l = makeLauncher(apps, undefined);
        l.open();
        const tiles = [...l.grid.querySelectorAll(':scope > .sx-launcher-tile[data-sx-launch]')];
        expect(tiles.map((t) => t.dataset.sxLaunch)).toEqual(['hello', 'notes', 'controls']);
    });

    it('a folder item renders a folder tile (data-sx-folder), not a launch tile', () => {
        const l = makeLauncher(apps, [
            { type: 'folder', id: 'f1', name: 'Tools', apps: ['notes', 'controls'] },
            { type: 'app', slug: 'hello' },
        ]);
        l.open();
        expect(l.grid.querySelector('[data-sx-folder="f1"]')).toBeTruthy();
        const rootTiles = l.grid.querySelectorAll(':scope > .sx-launcher-tile[data-sx-launch]');
        expect(rootTiles.length).toBe(1);
    });

    it('removeApp of an in-folder app drops it from the folder (kept empty, not dissolved)', () => {
        const l = makeLauncher(apps, [
            { type: 'folder', id: 'f1', name: 'Tools', apps: ['notes'] },
            { type: 'app', slug: 'hello' },
        ]);
        l.removeApp('notes');
        expect(l.layout.find((i) => i.id === 'f1').apps).toEqual([]); // kept, empty
        expect(l.apps.some((a) => a.slug === 'notes')).toBe(false);
    });

    it('addApp of a new slug appends a root app item', () => {
        const l = makeLauncher(apps, [{ type: 'app', slug: 'hello' }]);
        l.addApp({ slug: 'notes', title: 'Notes', icon: 'notes' });
        expect(l.layout.filter((i) => i.type === 'app' && i.slug === 'notes').length).toBe(1);
    });
});

describe('launcher folder shelf', () => {
    const apps = [
        { slug: 'hello', title: 'Hello', icon: 'window' },
        { slug: 'notes', title: 'Notes', icon: 'notes' },
        { slug: 'controls', title: 'Controls', icon: 'gear' },
    ];
    const layout = [
        { type: 'folder', id: 'f1', name: 'Tools', apps: ['notes', 'controls'] },
        { type: 'app', slug: 'hello' },
    ];

    it('clicking a folder tile opens a shelf with its apps', () => {
        document.body.innerHTML = '';
        const l = new Launcher(document.body, { apps, onPick() {}, layout });
        l.open();
        l.grid.querySelector('[data-sx-folder="f1"]').click();
        const shelf = l.grid.querySelector('.sx-launcher-shelf');
        expect(shelf).toBeTruthy();
        expect([...shelf.querySelectorAll('[data-sx-launch]')].map((t) => t.dataset.sxLaunch))
            .toEqual(['notes', 'controls']);
    });

    it('an open shelf does NOT change the root-tile count', () => {
        document.body.innerHTML = '';
        const l = new Launcher(document.body, { apps, onPick() {}, layout });
        l.open();
        const rootSel = ':scope > .sx-launcher-tile[data-sx-launch]';
        const before = l.grid.querySelectorAll(rootSel).length; // hello only = 1
        l.grid.querySelector('[data-sx-folder="f1"]').click();
        expect(l.grid.querySelectorAll(rootSel).length).toBe(before);
    });

    it('clicking the open folder again closes the shelf', () => {
        document.body.innerHTML = '';
        const l = new Launcher(document.body, { apps, onPick() {}, layout });
        l.open();
        const folderTile = () => l.grid.querySelector('[data-sx-folder="f1"]');
        folderTile().click();
        expect(l.grid.querySelector('.sx-launcher-shelf')).toBeTruthy();
        folderTile().click(); // re-click closes
        expect(l.grid.querySelector('.sx-launcher-shelf')).toBeFalsy();
    });

    it('Escape closes an open shelf but not the launcher', () => {
        document.body.innerHTML = '';
        const l = new Launcher(document.body, { apps, onPick() {}, layout });
        l.open();
        l.grid.querySelector('[data-sx-folder="f1"]').click();
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        expect(l.grid.querySelector('.sx-launcher-shelf')).toBeFalsy();
        expect(l.isOpen()).toBe(true);
    });
});

describe('launcher search + persist', () => {
    const apps = [
        { slug: 'hello', title: 'Hello', icon: 'window' },
        { slug: 'notes', title: 'Notes', icon: 'notes' },
        { slug: 'controls', title: 'Controls', icon: 'gear' },
    ];
    const layout = [
        { type: 'folder', id: 'f1', name: 'Tools', apps: ['notes', 'controls'] },
        { type: 'app', slug: 'hello' },
    ];

    it('typing a query collapses an open shelf and flattens in-folder apps', () => {
        document.body.innerHTML = '';
        const l = new Launcher(document.body, { apps, onPick() {}, layout });
        l.open();
        l.grid.querySelector('[data-sx-folder="f1"]').click();     // shelf open
        l.search.value = 'con';
        l.search.dispatchEvent(new Event('input'));
        expect(l.grid.querySelector('.sx-launcher-shelf')).toBeFalsy(); // collapsed
        expect(l.openFolderId).toBe(null);
        // 'controls' (in the folder) surfaces as a flat launch-tile during search.
        expect(l.grid.querySelector('[data-sx-launch="controls"]')).toBeTruthy();
        // No folder tiles while searching.
        expect(l.grid.querySelector('[data-sx-folder]')).toBeFalsy();
    });

    it('clearing the query returns to the folder view', () => {
        document.body.innerHTML = '';
        const l = new Launcher(document.body, { apps, onPick() {}, layout });
        l.open();
        l.search.value = 'con'; l.search.dispatchEvent(new Event('input'));
        l.search.value = ''; l.search.dispatchEvent(new Event('input'));
        expect(l.grid.querySelector('[data-sx-folder="f1"]')).toBeTruthy();
    });

    it('persist() coalesces concurrent saves to the latest doc (single-flight)', async () => {
        const saved = [];
        let release;
        const gate = new Promise((r) => { release = r; });
        document.body.innerHTML = '';
        const l = new Launcher(document.body, {
            apps, onPick() {}, layout: [{ type: 'app', slug: 'hello' }],
            transport: { saveLayout: (d) => { saved.push(structuredClone(d)); return gate; } },
        });
        l.layout = [{ type: 'app', slug: 'a' }]; l.persist();  // save #1 starts, in-flight
        l.layout = [{ type: 'app', slug: 'b' }]; l.persist();  // queued (latest)
        l.layout = [{ type: 'app', slug: 'c' }]; l.persist();  // supersedes the queued one
        release();
        await Promise.resolve(); await Promise.resolve(); await Promise.resolve();
        // Exactly two POSTs: the first in-flight doc, then ONE coalesced re-save of the latest.
        expect(saved.length).toBe(2);
        expect(saved.at(-1)).toEqual([{ type: 'app', slug: 'c' }]);
    });
});

describe('launcher folder mutations', () => {
    const apps = [
        { slug: 'hello', title: 'Hello', icon: 'window' },
        { slug: 'notes', title: 'Notes', icon: 'notes' },
        { slug: 'controls', title: 'Controls', icon: 'gear' },
    ];

    it('newFolderFrom seeds a 1-app folder from the app and persists the doc', () => {
        const saved = [];
        document.body.innerHTML = '';
        const l = new Launcher(document.body, {
            apps, onPick() {}, layout: [{ type: 'app', slug: 'hello' }, { type: 'app', slug: 'notes' }],
            transport: { saveLayout: (d) => { saved.push(structuredClone(d)); return Promise.resolve(); } },
        });
        l.open();
        l.newFolderFrom('hello', 'Stuff');
        const folder = l.layout.find((i) => i.type === 'folder');
        expect(folder).toBeTruthy();
        expect(folder.apps).toEqual(['hello']);
        expect(folder.name).toBe('Stuff');
        // hello left root; notes still a root app.
        expect(l.layout.some((i) => i.type === 'app' && i.slug === 'hello')).toBe(false);
        expect(saved.at(-1)).toEqual(l.layout);
    });

    it('moveTo relocates an app into a folder and keeps the emptied source folder', () => {
        document.body.innerHTML = '';
        const l = new Launcher(document.body, {
            apps, onPick() {},
            layout: [
                { type: 'folder', id: 'f1', name: 'A', apps: ['notes'] },
                { type: 'folder', id: 'f2', name: 'B', apps: ['controls'] },
                { type: 'app', slug: 'hello' },
            ],
            transport: { saveLayout: () => Promise.resolve() },
        });
        l.open();
        l.moveTo('notes', 'f2'); // f1 empties -> kept empty; notes joins f2
        expect(l.layout.find((i) => i.id === 'f1').apps).toEqual([]); // kept, empty
        const f2 = l.layout.find((i) => i.id === 'f2');
        expect(f2.apps).toContain('notes');
    });

    it('moveTo null returns an app to Home (root)', () => {
        document.body.innerHTML = '';
        const l = new Launcher(document.body, {
            apps, onPick() {},
            layout: [{ type: 'folder', id: 'f1', name: 'A', apps: ['notes', 'controls'] }],
            transport: { saveLayout: () => Promise.resolve() },
        });
        l.open();
        l.moveTo('notes', null);
        expect(l.layout.some((i) => i.type === 'app' && i.slug === 'notes')).toBe(true);
        // f1 still has controls (not dissolved).
        expect(l.layout.find((i) => i.id === 'f1').apps).toEqual(['controls']);
    });

    it('renameFolder updates the name and persists', () => {
        const saved = [];
        document.body.innerHTML = '';
        const l = new Launcher(document.body, {
            apps, onPick() {},
            layout: [{ type: 'folder', id: 'f1', name: 'Old', apps: ['notes'] }],
            transport: { saveLayout: (d) => { saved.push(structuredClone(d)); return Promise.resolve(); } },
        });
        l.open();
        l.renameFolder('f1', 'New');
        expect(l.layout.find((i) => i.id === 'f1').name).toBe('New');
        expect(saved.length).toBeGreaterThan(0);
    });

    it('context menu opens with dynamic items when a tile is right-clicked', () => {
        document.body.innerHTML = '';
        const l = new Launcher(document.body, { apps, onPick() {}, layout: [{ type: 'app', slug: 'hello' }] });
        l.open();
        const tile = l.grid.querySelector('[data-sx-launch="hello"]');
        tile.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, clientX: 10, clientY: 10 }));
        const menu = document.querySelector('.sx-context-menu');
        expect(menu).toBeTruthy();
        // A "New folder" item is present for a root app.
        expect([...menu.querySelectorAll('.sx-context-item-label')].some((n) => /new folder/i.test(n.textContent))).toBe(true);
    });
});

describe('launcher persists on live install/uninstall', () => {
    const apps = [
        { slug: 'hello', title: 'Hello', icon: 'window' },
        { slug: 'notes', title: 'Notes', icon: 'notes' },
    ];

    it('uninstalling an in-folder app persists a doc with it removed (folder kept empty)', () => {
        const saved = [];
        document.body.innerHTML = '';
        const l = new Launcher(document.body, {
            apps, onPick() {},
            layout: [
                { type: 'folder', id: 'f1', name: 'T', apps: ['notes'] },
                { type: 'app', slug: 'hello' },
            ],
            transport: { saveLayout: (d) => { saved.push(structuredClone(d)); return Promise.resolve(); } },
        });
        l.removeApp('notes');
        expect(saved.length).toBeGreaterThan(0);
        const doc = saved.at(-1);
        const f = doc.find((i) => i.type === 'folder');
        expect(f).toBeTruthy();
        expect(f.apps).toEqual([]);       // emptied but kept
        expect(doc.some((i) => i.type === 'app' && i.slug === 'notes')).toBe(false);
    });

    it('re-installing an app persists a doc with it appended at root', () => {
        const saved = [];
        document.body.innerHTML = '';
        const l = new Launcher(document.body, {
            apps: [{ slug: 'hello', title: 'Hello', icon: 'window' }], onPick() {},
            layout: [{ type: 'app', slug: 'hello' }],
            transport: { saveLayout: (d) => { saved.push(structuredClone(d)); return Promise.resolve(); } },
        });
        l.addApp({ slug: 'notes', title: 'Notes', icon: 'notes' });
        expect(saved.length).toBeGreaterThan(0);
        expect(saved.at(-1).some((i) => i.type === 'app' && i.slug === 'notes')).toBe(true);
    });
});

describe('launcher folder id seeding', () => {
    it('seeds the folder id counter from the persisted layout so a new folder does not collide', () => {
        document.body.innerHTML = '';
        const apps = [
            { slug: 'hello', title: 'Hello', icon: 'window' },
            { slug: 'notes', title: 'Notes', icon: 'notes' },
        ];
        const l = new Launcher(document.body, {
            apps, onPick() {},
            layout: [
                { type: 'folder', id: 'f_1', name: 'Existing', apps: ['hello'] },
                { type: 'app', slug: 'notes' },
            ],
            transport: { saveLayout: () => Promise.resolve() },
        });
        l.newFolderFrom('notes', 'New');
        const ids = l.layout.filter((i) => i.type === 'folder').map((i) => i.id);
        expect(new Set(ids).size).toBe(ids.length); // no duplicate ids
        expect(ids).toContain('f_2');               // seeded past f_1
    });
});

describe('launcher explicit-container folders', () => {
    const apps = [
        { slug: 'hello', title: 'Hello', icon: 'window' },
        { slug: 'notes', title: 'Notes', icon: 'notes' },
    ];

    it('moving the last app out of a folder keeps the folder (empty), does not dissolve', () => {
        document.body.innerHTML = '';
        const l = new Launcher(document.body, {
            apps, onPick() {},
            layout: [{ type: 'folder', id: 'f1', name: 'T', apps: ['notes'] }],
            transport: { saveLayout: () => Promise.resolve() },
        });
        l.moveTo('notes', null);
        const folder = l.layout.find((i) => i.type === 'folder' && i.id === 'f1');
        expect(folder).toBeTruthy();
        expect(folder.apps).toEqual([]);
    });

    it('deleteFolder returns its apps to root and removes the folder', () => {
        document.body.innerHTML = '';
        const l = new Launcher(document.body, {
            apps, onPick() {},
            layout: [{ type: 'folder', id: 'f1', name: 'T', apps: ['hello', 'notes'] }],
            transport: { saveLayout: () => Promise.resolve() },
        });
        l.deleteFolder('f1');
        expect(l.layout.some((i) => i.type === 'folder')).toBe(false);
        expect(l.layout.filter((i) => i.type === 'app').map((i) => i.slug).sort()).toEqual(['hello', 'notes']);
    });

    it('deleting an empty folder just removes it', () => {
        document.body.innerHTML = '';
        const l = new Launcher(document.body, {
            apps, onPick() {},
            layout: [{ type: 'folder', id: 'f1', name: 'T', apps: [] }, { type: 'app', slug: 'hello' }],
            transport: { saveLayout: () => Promise.resolve() },
        });
        l.deleteFolder('f1');
        expect(l.layout.some((i) => i.type === 'folder')).toBe(false);
    });

    it('a folder tile right-click menu offers Delete folder', () => {
        document.body.innerHTML = '';
        const l = new Launcher(document.body, {
            apps, onPick() {},
            layout: [{ type: 'folder', id: 'f1', name: 'T', apps: ['hello'] }],
            transport: { saveLayout: () => Promise.resolve() },
        });
        l.open();
        const folderTile = l.grid.querySelector('[data-sx-folder="f1"]');
        folderTile.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, clientX: 10, clientY: 10 }));
        const menu = document.querySelector('.sx-context-menu');
        expect([...menu.querySelectorAll('.sx-context-item-label')].some((n) => /delete folder/i.test(n.textContent))).toBe(true);
    });
});

describe('launcher backdrop new-folder', () => {
    const apps = [{ slug: 'hello', title: 'Hello', icon: 'window' }];

    it('right-clicking the empty grid area offers New folder, which creates an empty folder', () => {
        document.body.innerHTML = '';
        const l = new Launcher(document.body, {
            apps, onPick() {},
            layout: [{ type: 'app', slug: 'hello' }],
            transport: { saveLayout: () => Promise.resolve() },
        });
        l.open();
        l.grid.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, clientX: 5, clientY: 5 }));
        const menu = document.querySelector('.sx-context-menu');
        expect(menu).toBeTruthy();
        const item = [...menu.querySelectorAll('.sx-context-item')].find((n) => /new folder/i.test(n.textContent));
        expect(item).toBeTruthy();
        item.click();
        const folders = l.layout.filter((i) => i.type === 'folder');
        expect(folders.length).toBe(1);
        expect(folders[0].apps).toEqual([]);
    });

    it('a right-click ON an app tile shows the tile menu (New folder...), not the bare backdrop menu', () => {
        document.body.innerHTML = '';
        const l = new Launcher(document.body, { apps, onPick() {}, layout: [{ type: 'app', slug: 'hello' }], transport: { saveLayout: () => Promise.resolve() } });
        l.open();
        const tile = l.grid.querySelector('[data-sx-launch="hello"]');
        tile.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, clientX: 5, clientY: 5 }));
        const labels = [...document.querySelectorAll('.sx-context-item-label')].map((n) => n.textContent);
        expect(labels.some((t) => /new folder\.\.\./i.test(t))).toBe(true); // the seed-from-app item
    });
});

describe('launcher drag-onto (Launchpad group gesture)', () => {
    const apps = [
        { slug: 'hello', title: 'Hello', icon: 'window' },
        { slug: 'notes', title: 'Notes', icon: 'notes' },
    ];

    it('dropping an app onto another app creates a folder from the two', () => {
        document.body.innerHTML = '';
        const l = new Launcher(document.body, {
            apps, onPick() {},
            layout: [{ type: 'app', slug: 'hello' }, { type: 'app', slug: 'notes' }],
            transport: { saveLayout: () => Promise.resolve() },
        });
        l.dropOntoRoot(1, 0); // drop notes (idx1) onto hello (idx0)
        const folder = l.layout.find((i) => i.type === 'folder');
        expect(folder).toBeTruthy();
        expect(folder.apps.slice().sort()).toEqual(['hello', 'notes']);
        expect(l.layout.filter((i) => i.type === 'app').length).toBe(0);
    });

    it('dropping an app onto a folder adds it to the folder', () => {
        document.body.innerHTML = '';
        const l = new Launcher(document.body, {
            apps, onPick() {},
            layout: [{ type: 'folder', id: 'f1', name: 'T', apps: ['hello'] }, { type: 'app', slug: 'notes' }],
            transport: { saveLayout: () => Promise.resolve() },
        });
        l.dropOntoRoot(1, 0); // drop notes onto the folder at idx0
        expect(l.layout.find((i) => i.id === 'f1').apps).toContain('notes');
        expect(l.layout.some((i) => i.type === 'app' && i.slug === 'notes')).toBe(false);
    });

    it('dropping a folder (not an app) onto something is a no-op', () => {
        document.body.innerHTML = '';
        const l = new Launcher(document.body, {
            apps, onPick() {},
            layout: [{ type: 'folder', id: 'f1', name: 'T', apps: ['hello'] }, { type: 'app', slug: 'notes' }],
            transport: { saveLayout: () => Promise.resolve() },
        });
        const before = JSON.stringify(l.layout);
        l.dropOntoRoot(0, 1); // folder onto app -> ignored
        expect(JSON.stringify(l.layout)).toBe(before);
    });
});
