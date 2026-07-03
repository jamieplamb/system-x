import { describe, it, expect, beforeEach, vi } from 'vitest';

// Task 8: a launch response {app, window, tree} mints a NEW surface, paints the tree
// into it, and raises it. A singleton re-launch (the server returns the SAME window id)
// must focus the EXISTING surface, not duplicate it. The transport is stubbed so the
// launch unit drives server.launch() without the network -- launchWindow returns the
// {app, window, tree} frame the endpoint sends.
const launchWindow = vi.fn();
const closeWindow = vi.fn();
vi.mock('../../resources/js/system-x/transport.js', () => ({
    fetchDesktop: vi.fn(async () => ({ type: 'window', id: 'w', props: {}, children: [] })),
    sendEvent: vi.fn(async () => {}),
    launchWindow: (...args) => launchWindow(...args),
    closeWindow: (...args) => closeWindow(...args),
    savePref: vi.fn(async () => {}),
    saveGeometry: vi.fn(async () => {}),
    installApp: vi.fn(async () => {}),
    uninstallApp: vi.fn(async () => {}),
    saveLayout: vi.fn(async () => {}),
}));

import { DisplayServer } from '../../resources/js/system-x/display-server.js';

function buildMount(windowIds) {
    const mount = document.createElement('div');
    mount.dataset.desktopId = 'desk-1';
    for (const id of windowIds) {
        const s = document.createElement('div');
        s.className = 'sx-window-surface';
        s.dataset.windowId = id;
        s.dataset.app = id;
        mount.appendChild(s);
    }
    document.body.replaceChildren(mount);
    return mount;
}

const notesTree = {
    type: 'window',
    id: null,
    props: { title: 'Notes' },
    children: [{ type: 'textfield', id: 'message-field', props: {}, children: [] }],
};

describe('launch (Task 8)', () => {
    beforeEach(() => {
        launchWindow.mockReset();
    });

    it('a launch response mints a surface, paints the tree, and raises it', async () => {
        const mount = buildMount(['hello']);
        const server = new DisplayServer(mount, 'desk-1');
        const ulid = '01HXLAUNCHED0000000000000';
        launchWindow.mockResolvedValue({ app: 'notes', window: ulid, tree: notesTree });

        await server.launch('notes');

        expect(launchWindow).toHaveBeenCalledWith('notes');
        const surface = mount.querySelector(`[data-window-id="${ulid}"]`);
        expect(surface).not.toBeNull();
        expect(surface.dataset.app).toBe('notes');
        // It is positioned (cascade transform on the surface) and in the map.
        expect(surface.style.transform).not.toBe('');
        expect(server.surfaceFor(ulid)).toBe(surface);
        // The tree painted into it (its message-field marker is present).
        expect(surface.querySelector('.sx-window')).not.toBeNull();
        expect(surface.querySelector('[data-sx-id="message-field"]')).not.toBeNull();
        // It was raised + focused.
        expect(surface.dataset.sxActive).toBe('true');
    });

    it('a singleton re-launch focuses the EXISTING surface, no duplicate (S4)', async () => {
        const mount = buildMount(['hello']);
        const server = new DisplayServer(mount, 'desk-1');
        const ulid = '01HXLAUNCHED0000000000000';
        launchWindow.mockResolvedValue({ app: 'notes', window: ulid, tree: notesTree });

        await server.launch('notes'); // first launch mints it
        await server.launch('notes'); // singleton: server returns the SAME window id

        // Exactly ONE surface for that window -- not a second.
        expect(mount.querySelectorAll(`[data-window-id="${ulid}"]`)).toHaveLength(1);
        expect(server.surfaceFor(ulid).dataset.sxActive).toBe('true');
    });
});

describe('openApp -- focus-if-open-else-launch (Task 10)', () => {
    beforeEach(() => {
        launchWindow.mockReset();
    });

    it('opening an already-open singleton focuses it instead of minting a duplicate', async () => {
        // hello is already adopted (a static surface). "Opening" it again must just raise it.
        const mount = buildMount(['hello']);
        const server = new DisplayServer(mount, 'desk-1');

        const before = server.wm.surfaces.size;
        await server.openApp('hello'); // singleton open: focus if present, launch if absent

        expect(server.wm.surfaces.size).toBe(before); // no duplicate surface
        expect(server.wm.surfaceFor('hello').dataset.sxActive).toBe('true'); // focused
        expect(launchWindow).not.toHaveBeenCalled(); // never hit the wire for an open app
    });

    it('opening an app that is NOT open launches a fresh window', async () => {
        const mount = buildMount(['hello']);
        const server = new DisplayServer(mount, 'desk-1');
        const ulid = '01HXLAUNCHED0000000000000';
        launchWindow.mockResolvedValue({ app: 'notes', window: ulid, tree: notesTree });

        await server.openApp('notes'); // notes is absent -> launch it

        expect(launchWindow).toHaveBeenCalledWith('notes');
        const surface = mount.querySelector(`[data-window-id="${ulid}"]`);
        expect(surface).not.toBeNull();
        expect(surface.dataset.app).toBe('notes');
        expect(surface.dataset.sxActive).toBe('true');
    });
});

// Build a surface that carries a close control, exactly like window.js renders chrome.
function buildSurfaceWithClose(mount, id) {
    const surface = document.createElement('div');
    surface.className = 'sx-window-surface';
    surface.dataset.windowId = id;
    surface.dataset.app = id;
    const win = document.createElement('div');
    win.className = 'sx-window';
    const titlebar = document.createElement('div');
    titlebar.className = 'sx-titlebar';
    const control = document.createElement('button');
    control.dataset.sxControl = 'close';
    titlebar.appendChild(control);
    win.appendChild(titlebar);
    surface.appendChild(win);
    mount.appendChild(surface);
    return { surface, control };
}

describe('close control (Task 9)', () => {
    beforeEach(() => {
        closeWindow.mockReset();
        closeWindow.mockResolvedValue(undefined);
    });

    it('clicking the close control removes the surface, maps it out, and POSTs close', () => {
        const mount = document.createElement('div');
        mount.dataset.desktopId = 'desk-1';
        document.body.replaceChildren(mount);
        const server = new DisplayServer(mount, 'desk-1');
        const { surface, control } = buildSurfaceWithClose(mount, 'notes');
        server.wm.adopt(surface);

        control.dispatchEvent(new MouseEvent('click', { bubbles: true }));

        // The surface is gone from the DOM and the WM map.
        expect(mount.querySelector('[data-window-id="notes"]')).toBeNull();
        expect(server.surfaceFor('notes')).toBeNull();
        // The close POST fired with the window id (fire-and-forget).
        expect(closeWindow).toHaveBeenCalledWith('notes');
    });

    it('closing the focused window clears focus', () => {
        const mount = document.createElement('div');
        mount.dataset.desktopId = 'desk-1';
        document.body.replaceChildren(mount);
        const server = new DisplayServer(mount, 'desk-1');
        const { surface, control } = buildSurfaceWithClose(mount, 'notes');
        server.wm.adopt(surface);
        server.wm.bring('notes'); // make it the focused window

        expect(server.wm.focused).toBe('notes');

        control.dispatchEvent(new MouseEvent('click', { bubbles: true }));

        // Closing the focused window leaves nothing focused.
        expect(server.wm.focused).toBeNull();
    });
});
