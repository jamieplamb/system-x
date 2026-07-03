import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { WindowManager } from '../../resources/js/system-x/window-manager.js';
import { Panel } from '../../resources/js/system-x/panel.js';

function buildMount() {
    const mount = document.createElement('div');
    mount.className = 'sx-desktop';
    for (const slug of ['hello', 'notes']) {
        const s = document.createElement('div');
        s.className = 'sx-window-surface';
        s.dataset.windowId = slug;
        s.dataset.app = slug;
        mount.appendChild(s);
    }
    document.body.replaceChildren(mount);
    return mount;
}

describe('Panel (D3 -- mirrors the WM window list)', () => {
    let mount, wm, panel, opened, meta, userMenuEl;
    beforeEach(() => {
        mount = buildMount();
        opened = false;
        userMenuEl = null;
        meta = { hello: { title: 'Hello', icon: 'window' }, notes: { title: 'Notes', icon: 'notes' } };
        // The panel reads each window's title/icon from the metadata map (boot/launch).
        // The host element here is the test's mount; in PRODUCTION the panel mounts on
        // document.body (B4), but the Panel class takes its host as a ctor arg, so the unit
        // test hands it the test root -- the mount-point decision is the display server's.
        // Panel BEFORE the WM (B4/B2): the WM's boot-bring fires onChange synchronously, so
        // the panel must already exist when the WM is constructed below.
        panel = new Panel(mount, {
            metadataFor: (app) => meta[app] ?? { title: app, icon: 'window' },
            onSelectWindow: (id, active) => (active ? wm.minimise(id) : wm.bring(id)),
            onLaunch: () => { opened = true; },
            userName: 'Demo User',
            onUserMenu: (el) => { userMenuEl = el; },
        });
        // The DISPLAY SERVER wires the WM's onChange to panel.render -- emulate that here
        // (null-safe in prod via this.panel?.render; here the panel already exists).
        wm = new WindowManager(mount, { onChange: (list) => panel.render(list) });
        panel.render(wm.windows()); // initial paint
    });

    it('renders one button per open window with its title', () => {
        const buttons = panel.el.querySelectorAll('[data-sx-panel-window]');
        expect(buttons.length).toBe(2);
        const titles = [...buttons].map((b) => b.textContent);
        expect(titles.some((t) => t.includes('Hello'))).toBe(true);
        expect(titles.some((t) => t.includes('Notes'))).toBe(true);
    });

    it('marks the active window button pressed', () => {
        wm.bring('notes');
        const notesBtn = panel.el.querySelector('[data-sx-panel-window="notes"]');
        expect(notesBtn.dataset.sxActive).toBe('true');
        expect(panel.el.querySelector('[data-sx-panel-window="hello"]').dataset.sxActive).toBe('false');
    });

    it('re-renders on a WM change -- opening a window adds a button, closing removes it', () => {
        wm.mintSurface('01HXNEW', 'notes');
        expect(panel.el.querySelector('[data-sx-panel-window="01HXNEW"]')).not.toBeNull();
        wm.removeSurface('01HXNEW');
        expect(panel.el.querySelector('[data-sx-panel-window="01HXNEW"]')).toBeNull();
    });

    it('clicking an inactive window button focuses it (bring)', () => {
        wm.bring('hello'); // hello active, notes inactive
        panel.el.querySelector('[data-sx-panel-window="notes"]').click();
        expect(wm.focused).toBe('notes');
    });

    it('clicking the active window button minimises it (the mockup toggle)', () => {
        wm.bring('notes'); // notes active
        panel.el.querySelector('[data-sx-panel-window="notes"]').click();
        expect(wm.surfaceFor('notes').dataset.sxMin).toBe('true');
    });

    it('a minimised window button is dimmed; clicking it restores + focuses', () => {
        wm.minimise('hello');
        const helloBtn = panel.el.querySelector('[data-sx-panel-window="hello"]');
        expect(helloBtn.dataset.sxMin).toBe('true');
        helloBtn.click();
        expect(wm.surfaceFor('hello').dataset.sxMin).toBe('false');
        expect(wm.focused).toBe('hello');
    });

    it('minimising the active window leaves NO button pressed; clicking any button then brings (S2)', () => {
        // After minimising the active window, focused is null -- the all-inactive panel state.
        wm.bring('hello');          // hello active
        wm.minimise('hello');       // focused -> null
        expect(wm.focused).toBeNull();
        // No panel button carries data-sx-active='true' -- nothing is pressed.
        const pressed = [...panel.el.querySelectorAll('[data-sx-panel-window]')]
            .filter((b) => b.dataset.sxActive === 'true');
        expect(pressed.length).toBe(0);
        // With none active, clicking any button brings (not minimises) -- it's inactive.
        panel.el.querySelector('[data-sx-panel-window="notes"]').click();
        expect(wm.focused).toBe('notes');
    });

    it('the launcher button calls onLaunch', () => {
        panel.el.querySelector('[data-sx-launcher]').click();
        expect(opened).toBe(true);
    });

    it('renders the tray clock and the user-menu button', () => {
        expect(panel.el.querySelector('[data-sx-clock]')).not.toBeNull();
        expect(panel.el.querySelector('[data-sx-user-menu]')).not.toBeNull();
    });

    // system-menu D4: the standalone logout button is gone -- logout moved into the dropdown's
    // Log out item. The tray button is now the user-menu anchor (no dusk="logout" here anymore).
    it('the old standalone logout trigger is gone (logout moved into the menu)', () => {
        expect(panel.el.querySelector('.sx-panel-logout')).toBeNull();
        expect(panel.el.querySelector('[data-sx-logout]')).toBeNull();
    });

    // D4: the user button shows the initials (the greeter's logic) for a named user.
    it('the user button shows the user initials', () => {
        const btn = panel.el.querySelector('.sx-panel-user');
        expect(btn).not.toBeNull();
        expect(btn.textContent).toContain('DU');
    });

    // D4/S1: clicking the user button calls onUserMenu with the BUTTON ELEMENT (the SystemMenu
    // needs the anchor el for the toggle-vs-close guard, not just a rect).
    it('clicking the user button calls onUserMenu with the button element (S1)', () => {
        const btn = panel.el.querySelector('.sx-panel-user');
        btn.click();
        expect(userMenuEl).toBe(btn);
    });

    // S3: render() must ONLY rebuild the windows row -- the tray + the user button (the
    // SystemMenu's stable anchor) survive a WM change, or the menu's anchor would dangle.
    it('render() does NOT rebuild the tray -- the user button is the same element after a re-render (S3)', () => {
        const before = panel.el.querySelector('.sx-panel-user');
        const clockBefore = panel.el.querySelector('[data-sx-clock]');
        panel.render(wm.windows());
        wm.mintSurface('01HXNEW', 'notes'); // a WM change -> another render
        expect(panel.el.querySelector('.sx-panel-user')).toBe(before);
        expect(panel.el.querySelector('[data-sx-clock]')).toBe(clockBefore);
    });

    // D4/S4: no name -> the user glyph fallback (no initials text).
    it('with no user name the button falls back to the user glyph', () => {
        const p = new Panel(mount, { metadataFor: (app) => meta[app], userName: '' });
        const btn = p.el.querySelector('.sx-panel-user');
        expect(btn).not.toBeNull();
        expect(btn.textContent.trim()).toBe('');
        expect(btn.querySelector('svg')).not.toBeNull();
    });
});

describe('Panel tray clock (Task 7 -- a live setInterval)', () => {
    let mount, panel;

    beforeEach(() => {
        vi.useFakeTimers();
        mount = document.createElement('div');
        document.body.replaceChildren(mount);
        panel = new Panel(mount, { metadataFor: (app) => ({ title: app, icon: 'window' }) });
    });

    afterEach(() => {
        panel.stopClock?.();
        vi.useRealTimers();
    });

    it('paints the time immediately when the clock starts', () => {
        // 09:05 local -- the clock reads HH:MM (en-GB, 2-digit). It must paint at once,
        // not wait a full interval for the first tick.
        vi.setSystemTime(new Date(2026, 5, 28, 9, 5, 0));
        panel.startClock();
        expect(panel.el.querySelector('[data-sx-clock]').textContent).toBe('09:05');
    });

    it('updates the time on the interval as the clock advances', () => {
        vi.setSystemTime(new Date(2026, 5, 28, 9, 5, 0));
        panel.startClock();
        expect(panel.el.querySelector('[data-sx-clock]').textContent).toBe('09:05');

        // Advance real time AND fire the pending interval -> the clock repaints the new minute.
        vi.setSystemTime(new Date(2026, 5, 28, 9, 6, 30));
        vi.advanceTimersByTime(15000);
        expect(panel.el.querySelector('[data-sx-clock]').textContent).toBe('09:06');
    });

    it('stopClock clears the interval so it no longer ticks', () => {
        vi.setSystemTime(new Date(2026, 5, 28, 9, 5, 0));
        panel.startClock();
        panel.stopClock();

        vi.setSystemTime(new Date(2026, 5, 28, 9, 6, 30));
        vi.advanceTimersByTime(15000);
        // Stopped -> still on the old minute.
        expect(panel.el.querySelector('[data-sx-clock]').textContent).toBe('09:05');
    });
});
