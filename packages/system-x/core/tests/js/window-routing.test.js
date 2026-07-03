import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

// The transport is stubbed so the boot/reconnect S5 units can assert the exact
// per-window fetch calls without hitting the network. The routing units below do not
// touch the transport at all (applyFrame is pure DOM), so the stub is inert for them.
// The resync GET returns the BARE tree (response()->json($tree)) -- the boot wraps it
// with the known window id. The stub mirrors that bare shape.
vi.mock('../../resources/js/system-x/transport.js', () => ({
    fetchDesktop: vi.fn(async () => ({ type: 'window', id: 'w', props: {}, children: [] })),
    sendEvent: vi.fn(async () => {}),
    savePref: vi.fn(async () => {}),
    saveGeometry: vi.fn(async () => {}),
    installApp: vi.fn(async () => {}),
    uninstallApp: vi.fn(async () => {}),
    saveLayout: vi.fn(async () => {}),
}));

import { DisplayServer } from '../../resources/js/system-x/display-server.js';
import { fetchDesktop, sendEvent } from '../../resources/js/system-x/transport.js';

// The display server routes an inbound {app, window, tree} frame to the matching surface
// via the WM's surface map. The surface set is no longer a hardcoded static pair -- it is
// whatever the shell PAINTED (the user's open set, D7), which the WM adopts at boot.
// A frame for a window NOT in the map is ignored UNTIL a launch mints it (mintSurface,
// Task 8) -- so an unknown window can BECOME known, the inverse of the old static model.

function buildMountWith(windowIds) {
    const mount = document.createElement('div');
    mount.dataset.desktopId = 'desk-1';
    // The shell paints one surface per OPEN window -- any count, not a fixed pair.
    for (const id of windowIds) {
        const s = document.createElement('div');
        s.className = 'sx-window-surface';
        s.dataset.windowId = id;
        s.dataset.app = id; // app == id for the seeded pair; a ULID carries its real app
        mount.appendChild(s);
    }
    document.body.replaceChildren(mount);
    return mount;
}

describe('multi-window routing', () => {
    it('routes a frame to the surface matching payload.window', () => {
        const mount = buildMountWith(['hello', 'notes']);
        const server = new DisplayServer(mount, 'desk-1');

        server.applyFrame({ app: 'notes', window: 'notes', tree: { type: 'window', id: 'w', props: {}, children: [] } });

        const notes = mount.querySelector('[data-window-id="notes"]');
        expect(notes.querySelector('.sx-window')).not.toBeNull();
        // The hello surface is untouched.
        expect(mount.querySelector('[data-window-id="hello"]').querySelector('.sx-window')).toBeNull();
    });

    it('a frame for a not-yet-known window is ignored UNTIL it is minted', () => {
        const mount = buildMountWith(['hello']);
        const server = new DisplayServer(mount, 'desk-1');

        // unknown -> ignored (no surface), and it does not throw.
        expect(() => server.applyFrame({
            app: 'notes', window: 'ghost', tree: { type: 'window', id: 'w', props: {}, children: [] },
        })).not.toThrow();
        expect(mount.querySelector('[data-window-id="ghost"]')).toBeNull();

        // mint it (the launch path, Task 8) -> now a frame routes to its fresh surface.
        server.wm.mintSurface('ghost', 'notes');
        server.applyFrame({ app: 'notes', window: 'ghost', tree: { type: 'window', id: null, props: {}, children: [] } });
        expect(mount.querySelector('[data-window-id="ghost"] .sx-window')).not.toBeNull();
    });

    it('onEvent POSTs BOTH the window id and the app ({app, window} split, Task 6)', async () => {
        sendEvent.mockClear();
        const mount = buildMountWith(['hello', 'notes']);
        const server = new DisplayServer(mount, 'desk-1');

        await server.onEvent('clicker', 'click', undefined, 'w-01', 'notes');

        expect(sendEvent).toHaveBeenCalledWith({
            widget: 'clicker',
            event: 'click',
            value: undefined,
            window: 'w-01',
            app: 'notes',
        });
    });

    it('surfaceFor returns the mapped surface by window id and null for an unknown one', () => {
        const mount = buildMountWith(['hello', 'notes']);
        const server = new DisplayServer(mount, 'desk-1');

        expect(server.surfaceFor('hello')).toBe(mount.querySelector('[data-window-id="hello"]'));
        expect(server.surfaceFor('notes')).toBe(mount.querySelector('[data-window-id="notes"]'));
        expect(server.surfaceFor('ghost')).toBeNull();
    });
});

// S5: the boot AND the reconnect resync must loop EVERY ADOPTED surface -- the user's
// open set, whatever its size. If either served a single window a second surface would
// silently go stale. The set is dynamic now, so these prove "per adopted surface", not
// "exactly the static pair".
describe('multi-window boot/reconnect (S5, dynamic surfaces)', () => {
    let stateChangeHandler;

    beforeEach(() => {
        fetchDesktop.mockClear();

        // Minimal Echo fake: capture the connection state_change handler so the
        // reconnect branch can be driven, and no-op the channel subscribe.
        stateChangeHandler = null;
        window.Echo = {
            private: () => ({ listen: () => {} }),
            connector: {
                pusher: {
                    connection: {
                        bind: (_event, handler) => {
                            stateChangeHandler = handler;
                        },
                    },
                },
            },
        };
    });

    afterEach(() => {
        delete window.Echo;
    });

    it('boot() fetches once per ADOPTED surface (dynamic, not a fixed pair)', async () => {
        // Build a mount with THREE surfaces to prove it is not hardcoded to two.
        const mount = buildMountWith(['hello', 'notes', '01HXLAUNCHED0000000000000']);
        const server = new DisplayServer(mount, 'desk-1');

        await server.boot();

        expect(fetchDesktop).toHaveBeenCalledWith('hello');
        expect(fetchDesktop).toHaveBeenCalledWith('notes');
        expect(fetchDesktop).toHaveBeenCalledWith('01HXLAUNCHED0000000000000');
        expect(fetchDesktop).toHaveBeenCalledTimes(3);
    });

    it('a genuine reconnect re-fetches EVERY adopted surface', async () => {
        const mount = buildMountWith(['hello', 'notes']);
        const server = new DisplayServer(mount, 'desk-1');
        await server.boot();
        fetchDesktop.mockClear();

        // First connect must NOT re-fetch (boot already did the initial pull).
        stateChangeHandler({ previous: 'connecting', current: 'connected' });
        expect(fetchDesktop).toHaveBeenCalledTimes(0);

        // A genuine reconnect re-issues a fetch PER window.
        stateChangeHandler({ previous: 'unavailable', current: 'connected' });
        await Promise.resolve();
        await Promise.resolve();

        expect(fetchDesktop).toHaveBeenCalledWith('hello');
        expect(fetchDesktop).toHaveBeenCalledWith('notes');
        expect(fetchDesktop).toHaveBeenCalledTimes(2);
    });

    // B4: resyncAll (boot + reconnect) must fetch + repaint a LAUNCHED ULID surface, not
    // just the static pair. Mint a ULID surface, run resyncAll, assert it was fetched and
    // its tree painted in -- so a reconnect does not wipe a launched window.
    it('resyncAll fetches + repaints a launched ULID surface (B4)', async () => {
        const mount = buildMountWith(['hello']);
        const server = new DisplayServer(mount, 'desk-1');
        const ulid = '01HXLAUNCHED0000000000000';
        server.wm.mintSurface(ulid, 'notes'); // a launched window exists in the WM map
        fetchDesktop.mockResolvedValue({
            type: 'window',
            id: null,
            props: { title: 'Notes' },
            children: [{ type: 'textfield', id: 'message-field', props: {}, children: [] }],
        });

        await server.resyncAll();

        expect(fetchDesktop).toHaveBeenCalledWith(ulid); // the ULID surface was resynced...
        expect(mount.querySelector(`[data-window-id="${ulid}"] .sx-window`)).not.toBeNull(); // ...and repainted
    });
});
