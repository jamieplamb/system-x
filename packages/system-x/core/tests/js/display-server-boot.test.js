import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

// Plan 5c, Task 5 -- the panel-clock backlog guard. The panel's interval is unit-tested
// (panel.test.js), but NOTHING proved the live boot path actually STARTS it. The suspected
// bug was "startClock is never called at boot"; the investigation found it IS (display-server
// constructs the panel then calls startClock()). This test pins that wiring so a future
// refactor can't silently drop the live tick (which only a slow Dusk run would otherwise catch).
const launchWindow = vi.fn();
const closeWindow = vi.fn();
const sendEvent = vi.fn(async () => null);
vi.mock('../../resources/js/system-x/transport.js', () => ({
    fetchDesktop: vi.fn(async () => ({ type: 'window', id: 'w', props: {}, children: [] })),
    sendEvent: (...args) => sendEvent(...args),
    launchWindow: (...args) => launchWindow(...args),
    closeWindow: (...args) => closeWindow(...args),
    savePref: vi.fn(async () => {}),
    saveGeometry: vi.fn(async () => {}),
    installApp: vi.fn(async () => {}),
    uninstallApp: vi.fn(async () => {}),
    saveLayout: vi.fn(async () => {}),
}));

vi.mock('../../resources/js/system-x/toast.js', () => ({
    showToast: vi.fn(),
}));

import { DisplayServer } from '../../resources/js/system-x/display-server.js';
import { showToast } from '../../resources/js/system-x/toast.js';

function buildMount() {
    const mount = document.createElement('div');
    mount.dataset.desktopId = 'desk-1';
    document.body.replaceChildren(mount);
    return mount;
}

describe('display-server boot starts the panel clock (Task 5 -- the backlog guard)', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('paints the tray clock immediately at boot (startClock IS wired into the mount path)', () => {
        vi.setSystemTime(new Date(2026, 5, 28, 9, 5, 0));
        const mount = buildMount();
        const server = new DisplayServer(mount, 'desk-1');

        const clock = server.panel.el.querySelector('[data-sx-clock]');
        expect(clock.textContent).toBe('09:05');
    });

    it('the boot clock keeps ticking live on the 15s interval', () => {
        vi.setSystemTime(new Date(2026, 5, 28, 9, 5, 0));
        const mount = buildMount();
        const server = new DisplayServer(mount, 'desk-1');
        expect(server.panel.el.querySelector('[data-sx-clock]').textContent).toBe('09:05');

        vi.setSystemTime(new Date(2026, 5, 28, 9, 6, 30));
        vi.advanceTimersByTime(15000);
        expect(server.panel.el.querySelector('[data-sx-clock]').textContent).toBe('09:06');

        server.panel.stopClock();
    });
});

describe('display-server boot keeps system apps in the window-label lookup (S5)', () => {
    it('the launcher !system filter does NOT strip system apps from byApp', () => {
        // The launcher filters its grid to user apps, but byApp (the panel's per-window label
        // lookup) MUST keep ALL apps -- else an OPEN Appearance/About window loses its panel
        // label and falls back to {title: 'appearance', icon: 'window'}. The filter lives in
        // the Launcher; parseBootMeta / byApp are untouched.
        const mount = document.createElement('div');
        mount.dataset.desktopId = 'desk-1';
        const boot = document.createElement('script');
        boot.id = 'sx-boot';
        boot.type = 'application/json';
        boot.textContent = JSON.stringify({
            apps: [
                { slug: 'hello', title: 'Hello', icon: 'window', system: false },
                { slug: 'appearance', title: 'Appearance', icon: 'gear', system: true },
                { slug: 'apps', title: 'Manage apps', icon: 'launcher', system: true },
            ],
            windows: [],
        });
        mount.appendChild(boot);
        document.body.replaceChildren(mount);

        const server = new DisplayServer(mount, 'desk-1');

        // byApp keeps the system app -> the panel can label an open Appearance window.
        expect(server.windowMeta.byApp.appearance).toEqual({ title: 'Appearance', icon: 'gear' });
        expect(server.panel.metadataFor('appearance')).toEqual({ title: 'Appearance', icon: 'gear' });

        // ...while the launcher grid (the user-app list) drops it -- and Manage-apps too (system).
        server.launcher.open();
        expect(server.launcher.el.querySelector('[data-sx-launch="appearance"]')).toBeNull();
        expect(server.launcher.el.querySelector('[data-sx-launch="apps"]')).toBeNull();
        expect(server.launcher.el.querySelector('[data-sx-launch="hello"]')).not.toBeNull();
    });
});

describe('display-server onEvent surfaces a handler fault as a toast (PH Task 6)', () => {
    beforeEach(() => {
        sendEvent.mockReset();
        showToast.mockReset();
    });

    it('shows a toast when sendEvent resolves with an {error} body', async () => {
        sendEvent.mockResolvedValueOnce({ error: { app: 'notes', message: 'This app hit a problem.' } });
        const mount = document.createElement('div');
        mount.dataset.desktopId = 'desk-1';
        document.body.replaceChildren(mount);
        const server = new DisplayServer(mount, 'desk-1');

        await server.onEvent('w1', 'click', null, 'win-1', 'notes');

        expect(showToast).toHaveBeenCalledWith('Something went wrong in notes.');
    });

    it('does NOT toast on a successful (null) response', async () => {
        sendEvent.mockResolvedValueOnce(null);
        const mount = document.createElement('div');
        mount.dataset.desktopId = 'desk-1';
        document.body.replaceChildren(mount);
        const server = new DisplayServer(mount, 'desk-1');

        await server.onEvent('w1', 'click', null, 'win-1', 'notes');

        expect(showToast).not.toHaveBeenCalled();
    });
});

describe('display-server logout reads sxConfig.logoutUrl', () => {
    it('uses sxConfig.logoutUrl as the form action when set', () => {
        window.sxConfig = { logoutUrl: '/my-app/logout', csrfToken: 'tok-x' };

        const mount = document.createElement('div');
        mount.dataset.desktopId = 'desk-1';
        document.body.replaceChildren(mount);
        const server = new DisplayServer(mount, 'desk-1');

        let submitted = false;
        let capturedAction = null;
        const origAppend = document.body.appendChild.bind(document.body);
        vi.spyOn(document.body, 'appendChild').mockImplementation((el) => {
            const result = origAppend(el);
            if (el.tagName === 'FORM') {
                capturedAction = el.action;
                vi.spyOn(el, 'submit').mockImplementation(() => { submitted = true; });
            }
            return result;
        });

        server.logout();

        expect(capturedAction).toContain('/my-app/logout');
        document.body.appendChild.mockRestore();
    });

    it('falls back to /logout when sxConfig.logoutUrl is absent', () => {
        window.sxConfig = {};

        const mount = document.createElement('div');
        mount.dataset.desktopId = 'desk-1';
        document.body.replaceChildren(mount);
        const server = new DisplayServer(mount, 'desk-1');

        let capturedAction = null;
        const origAppend = document.body.appendChild.bind(document.body);
        vi.spyOn(document.body, 'appendChild').mockImplementation((el) => {
            const result = origAppend(el);
            if (el.tagName === 'FORM') {
                capturedAction = el.action;
                vi.spyOn(el, 'submit').mockImplementation(() => {});
            }
            return result;
        });

        server.logout();

        // jsdom resolves relative URLs against its base (http://localhost), so check the suffix
        expect(capturedAction).toMatch(/\/logout$/);
        document.body.appendChild.mockRestore();
    });
});
