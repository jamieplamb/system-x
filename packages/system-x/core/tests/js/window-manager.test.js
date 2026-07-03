import { describe, it, expect, beforeEach, vi } from 'vitest';
import { WindowManager } from '../../resources/js/system-x/window-manager.js';
import { reconcile } from '../../resources/js/system-x/reconcile.js';
import { registry } from '../../resources/js/system-x/renderers.js';

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

describe('WindowManager scaffold', () => {
    let mount;
    beforeEach(() => { mount = buildMount(); });

    it('adopts the existing [data-window-id] surfaces into its map', () => {
        const wm = new WindowManager(mount);

        expect(wm.surfaceFor('hello')).toBe(mount.querySelector('[data-window-id="hello"]'));
        expect(wm.surfaceFor('notes')).toBe(mount.querySelector('[data-window-id="notes"]'));
        expect(wm.surfaceFor('ghost')).toBeNull();
    });

    it('cascades the adopted surfaces to distinct default positions', () => {
        const wm = new WindowManager(mount);

        const hello = wm.surfaceFor('hello');
        const notes = wm.surfaceFor('notes');
        // Absolute positioning via transform (NEVER left/top, D6). The two windows
        // must not stack at the same spot.
        expect(hello.style.transform).not.toBe('');
        expect(notes.style.transform).not.toBe('');
        expect(hello.style.transform).not.toBe(notes.style.transform);
    });

    it('focuses the first adopted window on boot so the desktop opens with one active surface', () => {
        const wm = new WindowManager(mount);

        // The boot-focused window's surface carries the active cue; the rest do not.
        expect(wm.surfaceFor('hello').dataset.sxActive).toBe('true');
        expect(wm.surfaceFor('notes').dataset.sxActive).toBe('false');
    });
});

describe('WindowManager: focus + z-order (D6)', () => {
    let mount, wm;
    beforeEach(() => { mount = buildMount(); wm = new WindowManager(mount); });

    it('bring() gives the raised surface the highest z and the active cue', () => {
        wm.bring('hello');
        wm.bring('notes');

        const hello = wm.surfaceFor('hello');
        const notes = wm.surfaceFor('notes');
        expect(Number(notes.style.zIndex)).toBeGreaterThan(Number(hello.style.zIndex));
        expect(notes.dataset.sxActive).toBe('true');
        expect(hello.dataset.sxActive).toBe('false'); // the prior loses it
    });

    it('z is monotonic -- re-raising the same window still lifts it above the other', () => {
        wm.bring('hello');
        wm.bring('notes');
        wm.bring('hello'); // raise hello back over notes

        const hello = wm.surfaceFor('hello');
        const notes = wm.surfaceFor('notes');
        expect(Number(hello.style.zIndex)).toBeGreaterThan(Number(notes.style.zIndex));
        expect(hello.dataset.sxActive).toBe('true');
    });

    it('a pointerdown anywhere in a window raises it (content too, D6)', () => {
        const notes = wm.surfaceFor('notes');
        notes.innerHTML = '<div class="sx-window"><div class="sx-content"><button id="x">hi</button></div></div>';
        // the listener is mount-level, so a pointerdown deep in the content still raises
        notes.querySelector('#x').dispatchEvent(new MouseEvent('pointerdown', { bubbles: true }));

        expect(notes.dataset.sxActive).toBe('true');
        expect(wm.surfaceFor('hello').dataset.sxActive).toBe('false');
    });

    it('a pointerdown on the desktop background clears focus', () => {
        wm.bring('hello');
        // a bare pointerdown on the mount (no window ancestor) blurs every surface
        mount.dispatchEvent(new MouseEvent('pointerdown', { bubbles: true }));

        expect(wm.surfaceFor('hello').dataset.sxActive).toBe('false');
        expect(wm.surfaceFor('notes').dataset.sxActive).toBe('false');
        expect(wm.focused).toBeNull();
    });

    // D3 applied to FOCUS: focus/z live on the SURFACE, which the reconciler never
    // touches. A server frame morphing window B's CONTENT must not steal A's focus or z.
    it('a morph into another window does NOT steal focus or z (D3 for focus)', () => {
        wm.bring('notes');
        wm.bring('hello'); // hello is active + on top
        const hello = wm.surfaceFor('hello');
        const notes = wm.surfaceFor('notes');
        const helloZ = hello.style.zIndex;

        // A frame arrives for NOTES and is reconciled into its surface content.
        reconcile(notes, {
            type: 'window', id: null, props: { title: 'Notes', width: 360, height: 280 },
            children: [{ type: 'label', id: 'msg', props: { text: 'new message' }, children: [] }],
        }, { registry });

        // Notes' content updated, but hello keeps focus + its z; notes stays inactive.
        expect(notes.querySelector('.sx-window')).not.toBeNull();
        expect(hello.dataset.sxActive).toBe('true');
        expect(notes.dataset.sxActive).toBe('false');
        expect(hello.style.zIndex).toBe(helloZ);
    });
});

describe('WindowManager: drag-to-move (D6)', () => {
    let mount, wm, surface;
    beforeEach(() => {
        mount = buildMount();
        wm = new WindowManager(mount);
        surface = wm.surfaceFor('hello');
        surface.innerHTML = '<div class="sx-window"><div class="sx-titlebar"><span class="sx-titlebar-text">Hello</span>'
            + '<div class="sx-titlebar-controls"><button data-sx-control="close"></button></div></div>'
            + '<div class="sx-content"></div></div>';
    });

    function pointer(type, target, x, y) {
        const e = new Event(type, { bubbles: true });
        Object.assign(e, { clientX: x, clientY: y, pointerId: 1 });
        (target ?? surface).dispatchEvent(e);
        return e;
    }

    it('dragging the titlebar updates the surface transform (rAF flushed)', async () => {
        const title = surface.querySelector('.sx-titlebar-text');
        const before = surface.style.transform;

        pointer('pointerdown', title, 100, 100);
        pointer('pointermove', window, 140, 130); // +40, +30
        await new Promise((r) => requestAnimationFrame(r)); // flush the rAF batch
        pointer('pointerup', window, 140, 130);

        expect(surface.style.transform).not.toBe(before);
        // The transform reflects the delta applied to the start position.
        expect(surface.style.transform).toMatch(/translate3d\(/);
    });

    it('the drag delta is applied to the surface start position', async () => {
        const title = surface.querySelector('.sx-titlebar-text');
        const startX = Number(surface.dataset.sxX);
        const startY = Number(surface.dataset.sxY);

        pointer('pointerdown', title, 200, 200);
        pointer('pointermove', window, 260, 250); // +60, +50
        await new Promise((r) => requestAnimationFrame(r));
        pointer('pointerup', window, 260, 250);

        expect(Number(surface.dataset.sxX)).toBe(startX + 60);
        expect(Number(surface.dataset.sxY)).toBe(startY + 50);
    });

    it('a pointerdown on a control does NOT start a drag', async () => {
        const close = surface.querySelector('[data-sx-control="close"]');
        const before = surface.style.transform;

        pointer('pointerdown', close, 100, 100);
        pointer('pointermove', window, 200, 200);
        await new Promise((r) => requestAnimationFrame(r));

        expect(surface.style.transform).toBe(before); // unmoved -- the control isn't a drag handle
    });

    it('a pointerdown on window content does NOT start a drag (titlebar only)', async () => {
        const content = surface.querySelector('.sx-content');
        const before = surface.style.transform;

        pointer('pointerdown', content, 100, 100);
        pointer('pointermove', window, 200, 200);
        await new Promise((r) => requestAnimationFrame(r));

        expect(surface.style.transform).toBe(before); // content raises (Task 3) but never drags
    });

    it('a maximised window does NOT drag from its titlebar', async () => {
        surface.dataset.sxMax = 'true';
        const title = surface.querySelector('.sx-titlebar-text');
        const before = surface.style.transform;

        pointer('pointerdown', title, 100, 100);
        pointer('pointermove', window, 200, 200);
        await new Promise((r) => requestAnimationFrame(r));

        expect(surface.style.transform).toBe(before);
    });

    it('pressing the titlebar also raises + focuses the window (composes with Task 3)', () => {
        wm.bring('notes'); // notes is active + on top
        const title = surface.querySelector('.sx-titlebar-text');

        pointer('pointerdown', title, 100, 100);

        // The same pointerdown raised hello (Task 3's mount-level raise) AND armed the drag.
        expect(surface.dataset.sxActive).toBe('true');
        expect(wm.surfaceFor('notes').dataset.sxActive).toBe('false');
        pointer('pointerup', window, 100, 100);
    });

    it('a server frame mid-drag does NOT move the surface (D3 re-asserted)', async () => {
        const title = surface.querySelector('.sx-titlebar-text');
        pointer('pointerdown', title, 100, 100);
        pointer('pointermove', window, 150, 150);
        await new Promise((r) => requestAnimationFrame(r));
        const midDrag = surface.style.transform;

        // A frame arrives and reconciles into the .sx-window -- the surface must not jump.
        reconcile(surface, { type: 'window', id: null, props: { title: 'Hello', width: 360, height: 280 }, children: [] }, { registry });

        expect(surface.style.transform).toBe(midDrag);
    });
});

describe('WindowManager: the panel position parametrises the work area (5b-2 D6)', () => {
    let mount, wm, surface;
    beforeEach(() => {
        mount = buildMount();
        Object.defineProperty(mount, 'clientHeight', { value: 700, configurable: true });
        Object.defineProperty(mount, 'clientWidth', { value: 1000, configurable: true });
        wm = new WindowManager(mount, { panelPosition: 'top' });
        surface = wm.surfaceFor('hello');
    });

    it('TOP panel: maximise insets the origin to PANEL_H (the default, D6)', () => {
        wm.maximise('hello');
        expect(surface.style.transform).toMatch(/translate3d\(0px, 34px/);
        expect(parseInt(surface.style.height, 10)).toBe(700 - 34);
    });

    it('TOP panel: the clamp floors y at PANEL_H (a window can\'t hide under the top panel)', () => {
        expect(wm.clamp(100, -50).y).toBeGreaterThanOrEqual(34);
    });

    it('BOTTOM panel: maximise fills from the top (origin y=0), height still insets by PANEL_H', () => {
        wm.setPanelPosition('bottom');
        wm.maximise('hello');
        expect(surface.style.transform).toMatch(/translate3d\(0px, 0px/);  // fills from the top now
        expect(parseInt(surface.style.height, 10)).toBe(700 - 34);          // the bottom panel eats the strip
    });

    it('BOTTOM panel: the clamp ceils the bottom (a window can\'t hide under the bottom panel)', () => {
        wm.setPanelPosition('bottom');
        const clamped = wm.clamp(100, 9999); // dragging down past the bottom panel
        expect(clamped.y).toBeLessThanOrEqual(700 - 34);
    });

    it('setPanelPosition re-insets an already-maximised window live (D6)', () => {
        wm.maximise('hello');                                  // top: origin 34px
        wm.setPanelPosition('bottom');
        expect(surface.style.transform).toMatch(/translate3d\(0px, 0px/); // re-inset to the top fill
    });
});

describe('WindowManager: maximise/restore (D5)', () => {
    let mount, wm, surface;
    beforeEach(() => {
        mount = buildMount();
        Object.defineProperty(mount, 'clientWidth', { value: 1000, configurable: true });
        Object.defineProperty(mount, 'clientHeight', { value: 700, configurable: true });
        wm = new WindowManager(mount);
        surface = wm.surfaceFor('hello');
        surface.innerHTML = '<div class="sx-window"><div class="sx-titlebar"><span class="sx-titlebar-text">Hello</span>'
            + '<div class="sx-titlebar-controls">'
            + '<button data-sx-control="maximise" class="sx-window-control sx-window-control-maximise"><svg></svg></button>'
            + '<button data-sx-control="close" class="sx-window-control sx-window-control-close"><svg></svg></button>'
            + '</div></div>'
            + '<div class="sx-content"></div></div>';
        wm.move(surface, 120, 90);
    });

    it('maximise sets data-sx-max + a real surface width (the work-area inset is position-proven below, D6)', () => {
        wm.maximise('hello');
        expect(surface.dataset.sxMax).toBe('true');
        expect(wm.surfaceFor('hello').style.width).toMatch(/px|%/);
    });

    it('maximise stores the pre-max rect so restore is exact (an un-sized window: empty h, sized false)', () => {
        surface.style.width = '360px';
        wm.move(surface, 120, 90);
        wm.maximise('hello');
        const pre = JSON.parse(surface.dataset.sxPreMax);
        expect(pre).toEqual({ x: 120, y: 90, w: '360px', h: '', sized: false });
    });

    // Plan 5d Task 2: a RESIZED window's pre-max stash round-trips w/h/sized (D5), so
    // restore returns it to the resized rect, not a shrink-wrap.
    it('maximise stashes h + sized for a resized window so restore is the resized rect', () => {
        wm.resize(surface, 120, 90, 600, 400);
        wm.maximise('hello');
        const pre = JSON.parse(surface.dataset.sxPreMax);
        expect(pre).toEqual({ x: 120, y: 90, w: '600px', h: '400px', sized: true });
    });

    it('resize -> maximise -> restore returns to the resized size + keeps data-sx-sized (D5)', () => {
        wm.resize(surface, 120, 90, 600, 400);
        wm.maximise('hello');
        wm.restore('hello');
        expect(surface.style.width).toBe('600px');
        expect(surface.style.height).toBe('400px');
        expect(surface.dataset.sxSized).toBe('true'); // the fill rule still matches -- the window fills
        expect(surface.dataset.sxX).toBe('120');
        expect(surface.dataset.sxY).toBe('90');
    });

    it('a never-resized window -> maximise -> restore shrink-wraps (no sized flag, empty w/h)', () => {
        // surface was never resized: no width/height, no data-sx-sized
        wm.maximise('hello');
        wm.restore('hello');
        expect(surface.style.width).toBe('');
        expect(surface.style.height).toBe('');
        expect(surface.dataset.sxSized).not.toBe('true');
    });

    it('restore returns the window to its pre-max position', () => {
        const before = surface.style.transform;
        wm.maximise('hello');
        wm.restore('hello');
        expect(surface.dataset.sxMax).toBe('false');
        expect(surface.style.transform).toBe(before);
    });

    it('the maximise control flips to a restore control when maximised', () => {
        wm.maximise('hello');
        expect(surface.querySelector('[data-sx-control="restore"]')).not.toBeNull();
        expect(surface.querySelector('[data-sx-control="maximise"]')).toBeNull();
    });

    it('the restore control flips back to maximise when restored', () => {
        wm.maximise('hello');
        wm.restore('hello');
        expect(surface.querySelector('[data-sx-control="maximise"]')).not.toBeNull();
        expect(surface.querySelector('[data-sx-control="restore"]')).toBeNull();
    });

    it('swaps the control glyph from the shared CONTROL_GLYPHS source (N1)', async () => {
        const { CONTROL_GLYPHS } = await import('../../resources/js/system-x/widgets/window.js');
        // Normalise via the DOM so self-closing vs open/close serialisation doesn't matter --
        // what we assert is that the swap reused the SAME glyph the renderer would build.
        const normalise = (markup) => {
            const probe = document.createElement('span');
            probe.innerHTML = `<svg>${markup}</svg>`;
            return probe.querySelector('svg').innerHTML;
        };
        wm.maximise('hello');
        const restoreBtn = surface.querySelector('[data-sx-control="restore"]');
        expect(restoreBtn.querySelector('svg').innerHTML).toBe(normalise(CONTROL_GLYPHS.restore));
        wm.restore('hello');
        const maxBtn = surface.querySelector('[data-sx-control="maximise"]');
        expect(maxBtn.querySelector('svg').innerHTML).toBe(normalise(CONTROL_GLYPHS.maximise));
    });

    it('toggleMaximise flips between the two', () => {
        wm.toggleMaximise('hello');
        expect(surface.dataset.sxMax).toBe('true');
        wm.toggleMaximise('hello');
        expect(surface.dataset.sxMax).toBe('false');
    });

    it('clicking the maximise control toggles maximise (WM-wired, not a widget event)', () => {
        const btn = surface.querySelector('[data-sx-control="maximise"]');
        btn.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        expect(surface.dataset.sxMax).toBe('true');
        // now showing restore -- clicking it restores
        surface.querySelector('[data-sx-control="restore"]').dispatchEvent(new MouseEvent('click', { bubbles: true }));
        expect(surface.dataset.sxMax).toBe('false');
    });

    it('double-clicking the titlebar toggles maximise', () => {
        const title = surface.querySelector('.sx-titlebar-text');
        title.dispatchEvent(new MouseEvent('dblclick', { bubbles: true }));
        expect(surface.dataset.sxMax).toBe('true');
        title.dispatchEvent(new MouseEvent('dblclick', { bubbles: true }));
        expect(surface.dataset.sxMax).toBe('false');
    });

    it('double-clicking a control does NOT toggle maximise', () => {
        const close = surface.querySelector('[data-sx-control="close"]');
        close.dispatchEvent(new MouseEvent('dblclick', { bubbles: true }));
        expect(surface.dataset.sxMax).not.toBe('true');
    });

    it('maximise raises + focuses the window (it is an interaction)', () => {
        wm.bring('notes'); // notes active + on top
        wm.maximise('hello');
        expect(surface.dataset.sxActive).toBe('true');
        expect(wm.surfaceFor('notes').dataset.sxActive).toBe('false');
    });

    it('a maximised window does NOT drag from its titlebar (Task 4 sxMax guard fires)', async () => {
        wm.maximise('hello');
        const maxedTransform = surface.style.transform;
        const title = surface.querySelector('.sx-titlebar-text');
        const fire = (type, target, x, y) => {
            const e = new Event(type, { bubbles: true });
            Object.assign(e, { clientX: x, clientY: y, pointerId: 1 });
            (target ?? surface).dispatchEvent(e);
        };
        fire('pointerdown', title, 100, 100);
        fire('pointermove', window, 300, 300);
        await new Promise((r) => requestAnimationFrame(r));
        expect(surface.style.transform).toBe(maxedTransform); // pinned to the work area
    });

    // D3: a server frame morphing the CONTENT of a maximised window must NOT un-maximise it
    // or change its size -- geometry/max-state live on the surface the morph never touches.
    it('a morph into a maximised window keeps it maximised at the work-area size (D3)', () => {
        wm.maximise('hello');
        const maxedTransform = surface.style.transform;
        const maxedWidth = surface.style.width;

        reconcile(surface, {
            type: 'window', id: null, props: { title: 'Hello', width: 360, height: 280 },
            children: [{ type: 'label', id: 'msg', props: { text: 'new message' }, children: [] }],
        }, { registry });

        expect(surface.querySelector('.sx-window')).not.toBeNull(); // content morphed
        expect(surface.dataset.sxMax).toBe('true');                 // still maximised
        expect(surface.style.transform).toBe(maxedTransform);       // same place
        expect(surface.style.width).toBe(maxedWidth);               // same size
    });

    // N2: the .sx-window width is CSS-driven, NOT an imperative per-toggle write. The WM
    // sizes the SURFACE only; the .sx-window follows via the standing CSS rule.
    it('does NOT write width onto the .sx-window (geometry stays on the surface, N2)', () => {
        const win = surface.querySelector('.sx-window');
        win.style.width = ''; // ensure the renderer hint isn't present in this fixture
        wm.maximise('hello');
        expect(win.style.width).toBe(''); // WM never touched the inner window's width
        wm.restore('hello');
        expect(win.style.width).toBe('');
    });
});

// Plan 5d, Task 1: the size primitive -- resize() writes SURFACE geometry (width/height +
// data-sx-sized) and reuses move() for the origin; clampSize() is a PURE SIZE clamp (the
// MIN floor + the work-area ceil), NOT clamp() (which is permissive position-only).
describe('WindowManager: resize geometry (Plan 5d D1/D4)', () => {
    let mount, wm, surface;
    beforeEach(() => {
        mount = buildMount();
        Object.defineProperty(mount, 'clientWidth', { value: 1000, configurable: true });
        Object.defineProperty(mount, 'clientHeight', { value: 700, configurable: true });
        wm = new WindowManager(mount);
        surface = wm.surfaceFor('hello');
        surface.innerHTML = '<div class="sx-window"><div class="sx-titlebar">'
            + '<span class="sx-titlebar-text">Hello</span></div>'
            + '<div class="sx-content"></div></div>';
    });

    it('resize() sizes the SURFACE (width/height + data-sx-sized) and moves to x,y via move()', () => {
        wm.resize(surface, 120, 90, 600, 400);

        expect(surface.style.width).toBe('600px');
        expect(surface.style.height).toBe('400px');
        expect(surface.dataset.sxSized).toBe('true');
        // The origin is written through the ONE position-writer (transform + data-sx-x/y).
        expect(surface.dataset.sxX).toBe('120');
        expect(surface.dataset.sxY).toBe('90');
        expect(surface.style.transform).toMatch(/translate3d\(120px, 90px/);
    });

    // N2 parity (mirrors the maximise assertion ~:409): resize writes the SURFACE only --
    // the inner .sx-window fills via the CSS rule, never an imperative inner-element write.
    it('does NOT write width/height onto the inner .sx-window (geometry stays on the surface, N2)', () => {
        const win = surface.querySelector('.sx-window');
        win.style.width = '';
        win.style.height = '';
        wm.resize(surface, 120, 90, 600, 400);
        expect(win.style.width).toBe('');
        expect(win.style.height).toBe('');
    });

    it('clampSize() is PURE SIZE: floors a tiny w/h at MIN_W/MIN_H', () => {
        const { w, h } = wm.clampSize(10, 5);
        expect(w).toBeGreaterThanOrEqual(180); // MIN_W
        expect(h).toBeGreaterThanOrEqual(90);  // MIN_H
    });

    it('clampSize() ceils an oversized w/h to the work area (clientWidth, clientHeight - PANEL_H)', () => {
        const { w, h } = wm.clampSize(9999, 9999);
        expect(w).toBe(1000);       // clientWidth exactly -- no drag margin leaks in
        expect(h).toBe(700 - 34);   // clientHeight - PANEL_H exactly
    });

    it('clampSize() takes/returns only w/h -- no x/y (the origin coupling lives elsewhere)', () => {
        const out = wm.clampSize(500, 300);
        expect(Object.keys(out).sort()).toEqual(['h', 'w']);
        expect(out).toEqual({ w: 500, h: 300 }); // a within-bounds rect passes through unchanged
    });
});

// THE HIGH-RISK TASK (Plan 5d, Task 4): arming a live resize from a handle + the eight-
// direction math. Mirrors the drag describe (rAF-flushed pointer events) but drives the
// [data-sx-resize] handles. The start rect is read ONCE at grab: x/y from data-sx-x/y, w/h
// via a one-time offset measure BEFORE data-sx-sized is applied (S4) -- mocked here because
// jsdom computes no layout. The anchor coupling (B1) is the make-or-break: W/N edges shift
// the origin while resizing, the OPPOSITE edge stays pinned.
describe('WindowManager: resize arming + the eight-direction math (Plan 5d D2/D4/D6)', () => {
    let mount, wm, surface;
    const START_X = 200;
    const START_Y = 150;
    const START_W = 500;
    const START_H = 400;

    beforeEach(() => {
        mount = buildMount();
        Object.defineProperty(mount, 'clientWidth', { value: 1000, configurable: true });
        Object.defineProperty(mount, 'clientHeight', { value: 700, configurable: true });
        wm = new WindowManager(mount);
        surface = wm.surfaceFor('hello');
        // The 8 handles the renderer (Task 3) emits inside .sx-window, plus a titlebar.
        surface.innerHTML = '<div class="sx-window"><div class="sx-titlebar">'
            + '<span class="sx-titlebar-text">Hello</span></div>'
            + '<div class="sx-content"></div>'
            + ['n', 'e', 's', 'w', 'ne', 'nw', 'se', 'sw'].map(
                (d) => `<div class="sx-resize-handle sx-resize-${d}" data-sx-resize="${d}"></div>`,
            ).join('')
            + '</div>';
        // Pin the window to a known origin (the cascade placed it elsewhere).
        wm.move(surface, START_X, START_Y);
        // The ONE-TIME measure at grab (S4): a never-resized surface is width:max-content with
        // NO width data-attribute, so maybeStartResize measures offsetWidth/offsetHeight once.
        // jsdom returns 0 for these, so mock them to the start size.
        Object.defineProperty(surface, 'offsetWidth', { value: START_W, configurable: true });
        Object.defineProperty(surface, 'offsetHeight', { value: START_H, configurable: true });
    });

    function pointer(type, target, x, y) {
        const e = new Event(type, { bubbles: true });
        Object.assign(e, { clientX: x, clientY: y, pointerId: 1 });
        (target ?? surface).dispatchEvent(e);
        return e;
    }

    // Grab a handle at (gx,gy), drag to (gx+dx, gy+dy), flush the rAF, drop. Returns the
    // settled rect read off the surface (x/y from data, w/h from the inline style px).
    async function dragHandle(dir, dx, dy, gx = 300, gy = 300) {
        const handle = surface.querySelector(`[data-sx-resize="${dir}"]`);
        pointer('pointerdown', handle, gx, gy);
        pointer('pointermove', window, gx + dx, gy + dy);
        await new Promise((r) => requestAnimationFrame(r));
        pointer('pointerup', window, gx + dx, gy + dy);
        return {
            x: Number(surface.dataset.sxX),
            y: Number(surface.dataset.sxY),
            w: parseInt(surface.style.width, 10),
            h: parseInt(surface.style.height, 10),
        };
    }

    it('e (east): width += dx, origin + height fixed', async () => {
        const r = await dragHandle('e', 80, 0);
        expect(r.w).toBe(START_W + 80);
        expect(r.h).toBe(START_H);
        expect(r.x).toBe(START_X);
        expect(r.y).toBe(START_Y);
    });

    it('s (south): height += dy, origin + width fixed', async () => {
        const r = await dragHandle('s', 0, 60);
        expect(r.h).toBe(START_H + 60);
        expect(r.w).toBe(START_W);
        expect(r.x).toBe(START_X);
        expect(r.y).toBe(START_Y);
    });

    it('se (south-east): both width and height grow, origin fixed', async () => {
        const r = await dragHandle('se', 80, 60);
        expect(r.w).toBe(START_W + 80);
        expect(r.h).toBe(START_H + 60);
        expect(r.x).toBe(START_X);
        expect(r.y).toBe(START_Y);
    });

    it('w (west): x += dx (origin moves right), width -= dx (right edge anchored), y/h fixed', async () => {
        const r = await dragHandle('w', 50, 0);
        expect(r.x).toBe(START_X + 50);
        expect(r.w).toBe(START_W - 50);
        // The right edge (anchorRight = START_X + START_W) stays pinned.
        expect(r.x + r.w).toBe(START_X + START_W);
        expect(r.y).toBe(START_Y);
        expect(r.h).toBe(START_H);
    });

    it('n (north): y += dy (origin moves down), height -= dy (bottom anchored), x/w fixed', async () => {
        const r = await dragHandle('n', 0, 40);
        expect(r.y).toBe(START_Y + 40);
        expect(r.h).toBe(START_H - 40);
        // The bottom edge (anchorBottom = START_Y + START_H) stays pinned.
        expect(r.y + r.h).toBe(START_Y + START_H);
        expect(r.x).toBe(START_X);
        expect(r.w).toBe(START_W);
    });

    it('nw (north-west): both origin-shifting axes (x+=dx,w-=dx AND y+=dy,h-=dy)', async () => {
        const r = await dragHandle('nw', 50, 40);
        expect(r.x).toBe(START_X + 50);
        expect(r.w).toBe(START_W - 50);
        expect(r.y).toBe(START_Y + 40);
        expect(r.h).toBe(START_H - 40);
        expect(r.x + r.w).toBe(START_X + START_W); // right anchored
        expect(r.y + r.h).toBe(START_Y + START_H); // bottom anchored
    });

    it('ne (north-east): east grows (w+=dx, x fixed), north shifts (y+=dy, h-=dy)', async () => {
        const r = await dragHandle('ne', 80, 40);
        expect(r.w).toBe(START_W + 80);
        expect(r.x).toBe(START_X);          // east edge: origin x stays
        expect(r.y).toBe(START_Y + 40);     // north edge: origin y shifts down
        expect(r.h).toBe(START_H - 40);
        expect(r.y + r.h).toBe(START_Y + START_H); // bottom anchored
    });

    it('sw (south-west): west shifts (x+=dx, w-=dx), south grows (h+=dy, y fixed)', async () => {
        const r = await dragHandle('sw', 50, 60);
        expect(r.x).toBe(START_X + 50);     // west edge: origin x shifts right
        expect(r.w).toBe(START_W - 50);
        expect(r.x + r.w).toBe(START_X + START_W); // right anchored
        expect(r.y).toBe(START_Y);          // south edge: origin y stays
        expect(r.h).toBe(START_H + 60);
    });

    it('the MIN_W floor STOPS the west edge -- dragging it right past the min pins w at MIN_W and x at anchorRight - MIN_W (no inversion)', async () => {
        // Drag the W edge right by far more than START_W - MIN_W (500 - 180 = 320).
        const r = await dragHandle('w', 600, 0);
        expect(r.w).toBe(180); // MIN_W -- not negative, not inverted
        // x is coupled to the anchor: anchorRight - MIN_W. The left edge STOPS, it doesn't
        // cross over the right edge.
        expect(r.x).toBe(START_X + START_W - 180);
        expect(r.x + r.w).toBe(START_X + START_W); // the right edge is STILL anchored
    });

    it('the MIN_H floor STOPS the north edge when dragged down past the min', async () => {
        const r = await dragHandle('n', 0, 600);
        expect(r.h).toBe(90); // MIN_H
        expect(r.y).toBe(START_Y + START_H - 90); // anchorBottom - MIN_H
        expect(r.y + r.h).toBe(START_Y + START_H); // bottom STILL anchored
    });

    // The origin work-area clamp (Plan 5d D4, the gap Task 4 flagged): a W/N origin-shift derives
    // x = anchorRight - w / y = anchorBottom - h, then FLOORS the origin to the work area (x >= 0,
    // y >= PANEL_H for a top panel) so the moving edge can't slip past the left edge or under the
    // top panel. Drag the NW corner OUT (up + left) far enough that the size ceils to the work area
    // and the derived origin would go NEGATIVE on both axes -- it must clamp, not escape.
    it('the origin clamps to the work area -- a NW drag past the edge floors x at 0 and y at PANEL_H', async () => {
        // -600,-600: w grows to startW+600 (ceiled to clientWidth 1000), h to startH+600 (ceiled to
        // clientHeight - PANEL_H = 666). The naive origin would be anchorRight - 1000 = -300 (x) and
        // anchorBottom - 666 = -116 (y) -- both negative, both must be floored.
        const r = await dragHandle('nw', -600, -600);
        expect(r.w).toBe(1000);       // size ceiled to clientWidth
        expect(r.h).toBe(700 - 34);   // size ceiled to clientHeight - PANEL_H
        expect(r.x).toBe(0);          // left edge floored -- not anchorRight - w (-300)
        expect(r.y).toBe(34);         // top floored at PANEL_H -- not under the top panel (-116)
    });

    it('a maximised window does NOT resize (the guard, mirrors the drag guard)', async () => {
        surface.dataset.sxMax = 'true';
        const before = surface.style.transform;
        const handle = surface.querySelector('[data-sx-resize="se"]');
        pointer('pointerdown', handle, 300, 300);
        pointer('pointermove', window, 380, 360);
        await new Promise((r) => requestAnimationFrame(r));
        pointer('pointerup', window, 380, 360);
        expect(surface.style.transform).toBe(before);
        expect(surface.style.width).toBe(''); // never sized
    });

    it('the resize is rAF-batched: a pointermove without a flush has not yet written the size', () => {
        const handle = surface.querySelector('[data-sx-resize="se"]');
        pointer('pointerdown', handle, 300, 300);
        pointer('pointermove', window, 380, 360);
        // No rAF flush yet -- the surface size is still unwritten (batched for the frame).
        expect(surface.style.width).toBe('');
        pointer('pointerup', window, 380, 360);
    });

    it('reads the start rect ONCE -- a second offset value mid-resize does not change the delta base', async () => {
        const handle = surface.querySelector('[data-sx-resize="e"]');
        pointer('pointerdown', handle, 300, 300);
        // Mutate the measured size AFTER grab -- the hot path must NOT re-measure.
        Object.defineProperty(surface, 'offsetWidth', { value: 9999, configurable: true });
        pointer('pointermove', window, 380, 300); // +80
        await new Promise((r) => requestAnimationFrame(r));
        pointer('pointerup', window, 380, 300);
        // Width is START_W + 80, NOT 9999 + 80 -- the measure was taken once at grab.
        expect(parseInt(surface.style.width, 10)).toBe(START_W + 80);
    });

    it('a server frame mid-resize does NOT change geometry (D3 -- reconcile leaves the surface)', async () => {
        const handle = surface.querySelector('[data-sx-resize="se"]');
        pointer('pointerdown', handle, 300, 300);
        pointer('pointermove', window, 380, 360);
        await new Promise((r) => requestAnimationFrame(r));
        const midW = surface.style.width;
        const midH = surface.style.height;
        const midT = surface.style.transform;

        // A frame arrives and reconciles into the .sx-window -- the surface geometry holds.
        reconcile(surface, {
            type: 'window', id: null, props: { title: 'Hello', width: 360, height: 280 }, children: [],
        }, { registry });

        expect(surface.style.width).toBe(midW);
        expect(surface.style.height).toBe(midH);
        expect(surface.style.transform).toBe(midT);
        pointer('pointerup', window, 380, 360);
    });

    it('does NOT call bring() a second time (S3) -- the mount pointerdown already raised', async () => {
        // The handle is INSIDE the surface, so the mount-level pointerdown raises + focuses
        // on the same press. maybeStartResize must NOT add its own bring() (double notify).
        wm.bring('notes');
        const handle = surface.querySelector('[data-sx-resize="se"]');
        let notifyCount = 0;
        wm.onChange = () => { notifyCount += 1; };
        pointer('pointerdown', handle, 300, 300); // raises hello once (mount-level bring)
        expect(notifyCount).toBe(1); // exactly one notify from the raise, none extra from resize
        expect(surface.dataset.sxActive).toBe('true');
        pointer('pointermove', window, 380, 360);
        await new Promise((r) => requestAnimationFrame(r));
        pointer('pointerup', window, 380, 360);
        expect(notifyCount).toBe(1); // still one -- the resize itself never notifies
    });
});

// THE D3 INVARIANT (the make-or-break test, re-asserted in every later WM task).
describe('WindowManager: a server frame never fights WM surface state (D3)', () => {
    let mount;
    beforeEach(() => { mount = buildMount(); });

    it('reconciling a frame into a surface leaves its transform and active state untouched', () => {
        const wm = new WindowManager(mount);
        const hello = wm.surfaceFor('hello');

        // Simulate the WM having placed + focused the window: a transform + active cue
        // live on the SURFACE (WM-owned), not the .sx-window inside.
        hello.style.transform = 'translate3d(123px, 45px, 0)';
        hello.dataset.sxActive = 'true';

        // A server frame arrives and is reconciled into the surface (the morph operates
        // on surface.firstElementChild and below -- it must NEVER touch the surface).
        reconcile(hello, {
            type: 'window', id: null, props: { title: 'Hello', width: 360, height: 280 },
            children: [{ type: 'label', id: 'counter', props: { text: 'Clicked 9 times' }, children: [] }],
        }, { registry });

        // The window's CONTENT updated...
        expect(hello.querySelector('.sx-window')).not.toBeNull();
        // ...but the WM-owned surface state is UNTOUCHED.
        expect(hello.style.transform).toBe('translate3d(123px, 45px, 0)');
        expect(hello.dataset.sxActive).toBe('true');
    });

    it('survives the REPLACE path too -- a type change re-renders without touching the surface (D3)', () => {
        // reconcile.js has TWO write paths into a surface (verified, reconcile.js:14-28):
        //   - patch()              -- same-type slot, in-place update (covered above)
        //   - surface.replaceChild -- the slot's TYPE changed, the whole .sx-window is rebuilt
        // The surface's transform + active cue must survive the REPLACE branch as well,
        // because launch (Task 8) renders into a freshly-minted, pre-positioned surface.
        const wm = new WindowManager(mount);
        const hello = wm.surfaceFor('hello');

        // First paint a NON-window child so the next reconcile of a `window` forces the
        // type-change REPLACE branch (existing child type !== 'window').
        reconcile(hello, { type: 'label', id: null, props: { text: 'placeholder' }, children: [] }, { registry });
        expect(hello.firstElementChild.dataset.sxType).toBe('label');

        // WM places + focuses the surface (WM-owned, on the surface itself).
        hello.style.transform = 'translate3d(123px, 45px, 0)';
        hello.dataset.sxActive = 'true';

        // A `window` frame arrives -> sameSlot is false (label vs window) -> replaceChild.
        reconcile(hello, {
            type: 'window', id: null, props: { title: 'Hello', width: 360, height: 280 },
            children: [{ type: 'label', id: 'counter', props: { text: 'Clicked 0 times' }, children: [] }],
        }, { registry });

        // The .sx-window was REPLACED in...
        expect(hello.querySelector('.sx-window')).not.toBeNull();
        // ...but replaceChild only swaps surface.firstElementChild -- the surface div's
        // own transform + active cue are UNTOUCHED.
        expect(hello.style.transform).toBe('translate3d(123px, 45px, 0)');
        expect(hello.dataset.sxActive).toBe('true');
    });

    it('first paint into a freshly-minted, pre-positioned surface does NOT stomp the cascade transform (D3)', () => {
        // The launch path (Task 8) mints a surface, CASCADE-positions it, THEN reconciles
        // the initial tree into the (empty) surface -- the empty-surface append branch
        // (reconcile.js:18-21). The cascade transform the WM just set must survive that
        // first paint -- the renderer's create() must not write geometry onto the surface.
        const wm = new WindowManager(mount);
        const fresh = wm.mintSurface
            ? wm.mintSurface('01HXFRESH', 'hello')           // once mintSurface exists (Task 7)
            : (() => { const s = wm.surfaceFor('hello'); s.replaceChildren(); return s; })();
        const cascade = fresh.style.transform;
        expect(cascade).not.toBe('');                          // the WM pre-positioned it

        // First paint into the empty surface (the append branch).
        reconcile(fresh, {
            type: 'window', id: null, props: { title: 'Hello', width: 360, height: 280 }, children: [],
        }, { registry });

        expect(fresh.querySelector('.sx-window')).not.toBeNull(); // painted...
        expect(fresh.style.transform).toBe(cascade);              // ...transform untouched
    });

    it('the window renderer update() does NOT re-stamp width or active on the .sx-window', () => {
        const wm = new WindowManager(mount);
        const hello = wm.surfaceFor('hello');

        // First render establishes the .sx-window.
        reconcile(hello, {
            type: 'window', id: null, props: { title: 'Hello', width: 360, height: 280 }, children: [],
        }, { registry });
        const win = hello.querySelector('.sx-window');
        // Prove the renderer no longer owns active state: update() must not set it.
        win.removeAttribute('data-sx-active');

        // A second frame (the morph's update path) must not re-stamp width/active.
        reconcile(hello, {
            type: 'window', id: null, props: { title: 'Hello', width: 999, height: 280 }, children: [],
        }, { registry });

        // width is a create()-time hint only -- update() leaves it to the WM (it does
        // not force 999px), and active is never stamped by the renderer.
        expect(win.hasAttribute('data-sx-active')).toBe(false);
    });
});

describe('WindowManager: the change-notify seam + the window list (D3)', () => {
    let mount, wm, changes;
    beforeEach(() => {
        mount = buildMount();
        changes = [];
        // The display server passes onChange (mirroring the onClose ctor pattern). It fires
        // with the WM's window list on every list-changing mutation -- NO polling.
        wm = new WindowManager(mount, { onChange: (list) => changes.push(list) });
    });

    it('windows() returns the ordered {id, app, active, minimised} projection', () => {
        const list = wm.windows();
        expect(list.map((w) => w.id)).toEqual(['hello', 'notes']); // boot order
        expect(list.every((w) => 'app' in w && 'active' in w && 'minimised' in w)).toBe(true);
        // The boot-focused window is active (the WM brings the first on boot, 5a).
        expect(list.find((w) => w.id === 'hello').active).toBe(true);
        expect(list.find((w) => w.id === 'notes').active).toBe(false);
        // app is read off the surface; minimised is always false until Task 3 adds it.
        expect(list.find((w) => w.id === 'hello').app).toBe('hello');
        expect(list.every((w) => w.minimised === false)).toBe(true);
    });

    it('bring() notifies, and the new active window is reflected in the list', () => {
        changes.length = 0;
        wm.bring('notes');

        expect(changes.length).toBeGreaterThan(0);
        const latest = changes.at(-1);
        expect(latest.find((w) => w.id === 'notes').active).toBe(true);
        expect(latest.find((w) => w.id === 'hello').active).toBe(false);
    });

    it('bring() notifies EXACTLY once -- the nested focus() inside it does not double-fire', () => {
        changes.length = 0;
        wm.bring('notes');
        // bring calls focus internally; only the public entry point (bring) notifies.
        expect(changes.length).toBe(1);
    });

    it('focus() notifies exactly once when called as a public entry point', () => {
        changes.length = 0;
        wm.focus('notes');
        expect(changes.length).toBe(1);
        expect(changes.at(-1).find((w) => w.id === 'notes').active).toBe(true);
    });

    it('mintSurface() notifies exactly once and the new window appears in the list', () => {
        changes.length = 0;
        wm.mintSurface('01HXNEW', 'notes');

        expect(wm.windows().map((w) => w.id)).toContain('01HXNEW');
        expect(changes.at(-1).map((w) => w.id)).toContain('01HXNEW');
        // mintSurface calls bring internally -- it must still notify only once.
        expect(changes.length).toBe(1);
    });

    it('removeSurface() notifies exactly once and the window leaves the list', () => {
        changes.length = 0;
        wm.removeSurface('notes');

        expect(wm.windows().map((w) => w.id)).not.toContain('notes');
        expect(changes.at(-1).map((w) => w.id)).not.toContain('notes');
        expect(changes.length).toBe(1);
    });

    it('removeSurface() runs the injected destroySubtree over the surface BEFORE detaching it (PH Task 2)', () => {
        // The teardown seam: the WM calls the injected destroySubtree with the surface element,
        // and it must run WHILE the surface is still attached (so the walk can enumerate the
        // subtree + run destroy hooks before the DOM node is gone). The display server wires this
        // to destroyTree(el, { registry }) -- here a spy proves the WM raises it once, with the
        // surface, before remove().
        const destroySubtree = vi.fn((el) => {
            // At call time the surface must still be in the DOM (destroy runs before remove).
            expect(el.isConnected).toBe(true);
            expect(mount.contains(el)).toBe(true);
        });
        const wm2 = new WindowManager(mount, { destroySubtree });
        const surface = wm2.surfaceFor('notes');

        wm2.removeSurface('notes');

        expect(destroySubtree).toHaveBeenCalledTimes(1);
        expect(destroySubtree).toHaveBeenCalledWith(surface);
        // ...and the surface is actually gone afterwards.
        expect(surface.isConnected).toBe(false);
        expect(wm2.surfaceFor('notes')).toBeNull();
    });

    it('blurAll() notifies exactly once and no window is active', () => {
        wm.bring('hello');
        changes.length = 0;
        wm.blurAll();

        expect(wm.windows().every((w) => !w.active)).toBe(true);
        expect(changes.length).toBe(1);
    });

    it('boot-time notify is null-safe -- constructing with no onChange does not throw', () => {
        // The WM brings the first window during construction (5a boot-focus), which fires
        // notifyChange BEFORE any panel exists. With the default no-op onChange that must
        // never throw (B2 null-safe).
        expect(() => new WindowManager(mount)).not.toThrow();
    });
});

describe('WindowManager: minimise (D4 -- client-only, mirrors maximise)', () => {
    let mount, wm, changes;
    beforeEach(() => {
        mount = buildMount();
        changes = [];
        wm = new WindowManager(mount, { onChange: (l) => changes.push(l) });
    });

    it('minimise hides the surface, clears focus if it was focused, and notifies', () => {
        wm.bring('hello'); // hello is focused
        changes.length = 0;
        wm.minimise('hello');

        const hello = wm.surfaceFor('hello');
        expect(hello.dataset.sxMin).toBe('true');     // hidden marker (a class display:none's it)
        expect(wm.focused).not.toBe('hello');          // focus cleared -- it's no longer visible
        expect(changes.length).toBeGreaterThan(0);     // the panel hears about it
        expect(wm.windows().find((w) => w.id === 'hello').minimised).toBe(true);
    });

    it('minimise notifies exactly once', () => {
        wm.bring('hello');
        changes.length = 0;
        wm.minimise('hello');
        expect(changes.length).toBe(1);
    });

    it('minimise NEVER removes the surface -- the window stays open (D4)', () => {
        wm.minimise('notes');
        // Still in the map + the DOM -- minimised is display state, not closed.
        expect(wm.surfaceFor('notes')).not.toBeNull();
        expect(wm.windows().map((w) => w.id)).toContain('notes');
    });

    it('bring() un-minimises -- it clears data-sx-min, raises, and focuses (distinct from restore)', () => {
        wm.minimise('notes');
        wm.bring('notes'); // the panel button does this

        const notes = wm.surfaceFor('notes');
        expect(notes.dataset.sxMin).toBe('false');     // un-hidden
        expect(wm.focused).toBe('notes');               // focused
        expect(wm.windows().find((w) => w.id === 'notes').minimised).toBe(false);
    });

    it('bring() un-minimises with a single notify (one panel re-render)', () => {
        wm.minimise('notes');
        changes.length = 0;
        wm.bring('notes');
        expect(changes.length).toBe(1);
    });

    it('min/max axes are INDEPENDENT -- minimising a maximised window keeps data-sx-max', () => {
        Object.defineProperty(mount, 'clientWidth', { value: 1000, configurable: true });
        Object.defineProperty(mount, 'clientHeight', { value: 700, configurable: true });
        wm.surfaceFor('hello').innerHTML = '<div class="sx-window"><div class="sx-titlebar">'
            + '<div class="sx-titlebar-controls">'
            + '<button data-sx-control="maximise" class="sx-window-control sx-window-control-maximise"><svg></svg></button>'
            + '</div></div><div class="sx-content"></div></div>';
        wm.maximise('hello');
        wm.minimise('hello');
        const hello = wm.surfaceFor('hello');
        expect(hello.dataset.sxMin).toBe('true');
        expect(hello.dataset.sxMax).toBe('true'); // minimise leaves maximise alone

        wm.bring('hello'); // un-minimise via the panel button
        expect(hello.dataset.sxMin).toBe('false');
        expect(hello.dataset.sxMax).toBe('true'); // restored back to its maximised state
    });

    it('the control-click minimise branch dispatches wm.minimise', () => {
        const hello = wm.surfaceFor('hello');
        hello.innerHTML = '<div class="sx-window"><div class="sx-titlebar">'
            + '<div class="sx-titlebar-controls">'
            + '<button data-sx-control="minimise" class="sx-window-control sx-window-control-minimise"><svg></svg></button>'
            + '</div></div><div class="sx-content"></div></div>';
        wm.bring('hello');
        hello.querySelector('[data-sx-control="minimise"]').dispatchEvent(new MouseEvent('click', { bubbles: true }));
        expect(hello.dataset.sxMin).toBe('true');
        expect(wm.focused).not.toBe('hello');
    });

    it('a minimised surface still accepts a morph frame (it morphs while hidden, D4)', () => {
        wm.minimise('hello');
        const hello = wm.surfaceFor('hello');
        // A server frame reconciles into the hidden .sx-window -- the surface's data-sx-min
        // (WM-owned, like geometry/active, 5a D3) is untouched by the morph.
        reconcile(hello, { type: 'window', id: null, props: { title: 'Hello', width: 360, height: 280 }, children: [] }, { registry });
        expect(hello.dataset.sxMin).toBe('true'); // still minimised after the morph
    });
});

// Plan 5e, Task 3 (D3): the WM fires onGeometry(windowId, snapshot) at each discrete settle
// point -- drag-end, resize-end, maximise, restore, minimise, un-minimise, and a REAL raise.
// The snapshot is the FULL current geometry off the surface ({x,y,w,h,sized,maximised,minimised,z});
// when maximised x/y/w/h are the RESTORE rect read off the pre-max stash (B1), NOT the maximised
// fill. The z-save in bring() is GUARDED to fire ONLY on a genuine raise (B3) -- clicking the
// already-top window saves nothing. The WM stays transport-agnostic: onGeometry is the injected
// seam (the display server wires it to a saveGeometry POST), exactly like onChange/onClose.
describe('WindowManager: geometry persistence -- the onGeometry settle-capture (Plan 5e D3)', () => {
    let mount, wm, geom;

    beforeEach(() => {
        mount = buildMount();
        Object.defineProperty(mount, 'clientWidth', { value: 1000, configurable: true });
        Object.defineProperty(mount, 'clientHeight', { value: 700, configurable: true });
        geom = [];
        wm = new WindowManager(mount, { onGeometry: (id, g) => geom.push({ id, g }) });
        // Give hello the chrome the drag/resize/maximise paths need.
        const hello = wm.surfaceFor('hello');
        hello.innerHTML = '<div class="sx-window"><div class="sx-titlebar">'
            + '<span class="sx-titlebar-text">Hello</span>'
            + '<div class="sx-titlebar-controls">'
            + '<button data-sx-control="minimise" class="sx-window-control sx-window-control-minimise"><svg></svg></button>'
            + '<button data-sx-control="maximise" class="sx-window-control sx-window-control-maximise"><svg></svg></button>'
            + '<button data-sx-control="close" class="sx-window-control sx-window-control-close"><svg></svg></button>'
            + '</div></div>'
            + '<div class="sx-content"></div>'
            + ['n', 'e', 's', 'w', 'ne', 'nw', 'se', 'sw'].map(
                (d) => `<div class="sx-resize-handle" data-sx-resize="${d}"></div>`,
            ).join('')
            + '</div>';
    });

    function pointer(type, target, x, y) {
        const e = new Event(type, { bubbles: true });
        Object.assign(e, { clientX: x, clientY: y, pointerId: 1 });
        target.dispatchEvent(e);
        return e;
    }

    it('snapshotGeometry returns the FULL geometry object (all keys present), not a partial', () => {
        const surface = wm.surfaceFor('hello');
        wm.move(surface, 120, 90);
        const snap = wm.snapshotGeometry(surface);
        expect(Object.keys(snap).sort()).toEqual(
            ['h', 'maximised', 'minimised', 'sized', 'w', 'x', 'y', 'z'],
        );
    });

    it('drag-END fires onGeometry once with the settled position', async () => {
        const surface = wm.surfaceFor('hello');
        const title = surface.querySelector('.sx-titlebar-text');
        geom.length = 0;

        pointer('pointerdown', title, 100, 100);
        pointer('pointermove', window, 160, 140); // +60, +40
        await new Promise((r) => requestAnimationFrame(r));
        pointer('pointerup', window, 160, 140);

        // One settle-save for the drag (the pointerdown also raised, but hello was already top).
        const drags = geom.filter((e) => e.id === 'hello');
        expect(drags.length).toBe(1);
        const g = drags[0].g;
        expect(g.x).toBe(Number(surface.dataset.sxX));
        expect(g.y).toBe(Number(surface.dataset.sxY));
    });

    it('resize-END fires onGeometry with {x, y, w, h, sized: true}', async () => {
        const surface = wm.surfaceFor('hello');
        wm.move(surface, 200, 150);
        Object.defineProperty(surface, 'offsetWidth', { value: 500, configurable: true });
        Object.defineProperty(surface, 'offsetHeight', { value: 400, configurable: true });
        const handle = surface.querySelector('[data-sx-resize="se"]');
        geom.length = 0;

        pointer('pointerdown', handle, 300, 300);
        pointer('pointermove', window, 380, 360); // +80 w, +60 h
        await new Promise((r) => requestAnimationFrame(r));
        pointer('pointerup', window, 380, 360);

        const resizes = geom.filter((e) => e.id === 'hello');
        expect(resizes.length).toBe(1);
        const g = resizes[0].g;
        expect(g.sized).toBe(true);
        expect(g.w).toBe(parseInt(surface.style.width, 10));
        expect(g.h).toBe(parseInt(surface.style.height, 10));
        expect(g.x).toBe(Number(surface.dataset.sxX));
        expect(g.y).toBe(Number(surface.dataset.sxY));
    });

    it('maximise fires {maximised: true} with x/y/w/h = the RESTORE rect, NOT the maximised dims (B1)', () => {
        const surface = wm.surfaceFor('hello');
        // A KNOWN un-maximised resized rect.
        wm.resize(surface, 120, 90, 600, 400);
        geom.length = 0;

        wm.maximise('hello');

        const saves = geom.filter((e) => e.id === 'hello' && e.g.maximised === true);
        expect(saves.length).toBeGreaterThan(0);
        const g = saves.at(-1).g;
        expect(g.maximised).toBe(true);
        // The persisted rect is the PRE-MAX restore rect -- NOT the work-area fill (1000 x 666).
        expect(g.x).toBe(120);
        expect(g.y).toBe(90);
        expect(g.w).toBe(600);
        expect(g.h).toBe(400);
        expect(g.sized).toBe(true);
    });

    it('maximise B1 fallback: with no pre-max stash, x/y/w/h fall back to the current rect', () => {
        const surface = wm.surfaceFor('hello');
        wm.move(surface, 70, 60);
        // Force the maximised state WITHOUT a stash (defensive path).
        surface.dataset.sxMax = 'true';
        wm.applyMaximiseGeometry(surface);
        delete surface.dataset.sxPreMax;

        const snap = wm.snapshotGeometry(surface);
        expect(snap.maximised).toBe(true);
        // No stash -> fall back to the live surface rect, NOT NaN/undefined.
        expect(Number.isFinite(snap.x)).toBe(true);
        expect(Number.isFinite(snap.y)).toBe(true);
    });

    it('restore fires {maximised: false}', () => {
        const surface = wm.surfaceFor('hello');
        wm.resize(surface, 120, 90, 600, 400);
        wm.maximise('hello');
        geom.length = 0;

        wm.restore('hello');

        const saves = geom.filter((e) => e.id === 'hello');
        expect(saves.length).toBeGreaterThan(0);
        expect(saves.at(-1).g.maximised).toBe(false);
        // The restore rect is current now.
        expect(saves.at(-1).g.x).toBe(120);
        expect(saves.at(-1).g.y).toBe(90);
    });

    it('minimise fires {minimised: true}', () => {
        wm.bring('hello');
        geom.length = 0;
        wm.minimise('hello');

        const saves = geom.filter((e) => e.id === 'hello');
        expect(saves.length).toBeGreaterThan(0);
        expect(saves.at(-1).g.minimised).toBe(true);
    });

    it('un-minimise (bring on a minimised window) fires {minimised: false}', () => {
        wm.minimise('hello');
        geom.length = 0;
        wm.bring('hello');

        const saves = geom.filter((e) => e.id === 'hello');
        expect(saves.length).toBeGreaterThan(0);
        expect(saves.at(-1).g.minimised).toBe(false);
    });

    it('a REAL raise fires {z} ONCE (the z advanced)', () => {
        // notes is currently top (boot-focused hello, then we raise notes). Raise hello -> real raise.
        wm.bring('notes');
        geom.length = 0;
        wm.bring('hello');

        const saves = geom.filter((e) => e.id === 'hello');
        expect(saves.length).toBe(1);
        expect(Number.isFinite(saves[0].g.z)).toBe(true);
    });

    it('clicking the ALREADY-TOP window fires ZERO onGeometry saves (B3 -- the z-guard)', () => {
        wm.bring('hello'); // hello is now top
        geom.length = 0;
        // Re-raise the already-top window N times -- no z advance, so no save.
        wm.bring('hello');
        wm.bring('hello');
        wm.bring('hello');

        expect(geom.filter((e) => e.id === 'hello').length).toBe(0);
    });

    it('a pointerdown into the already-top window content fires ZERO onGeometry (B3)', () => {
        wm.bring('hello'); // top
        geom.length = 0;
        const content = wm.surfaceFor('hello').querySelector('.sx-content');
        pointer('pointerdown', content, 100, 100);

        expect(geom.filter((e) => e.id === 'hello').length).toBe(0);
    });

    it('a single drag gesture fires AT MOST one raise-save + one settled-rect save (no per-click storm)', async () => {
        const surface = wm.surfaceFor('hello');
        wm.bring('notes'); // hello is NOT top, so the drag press will raise it (one raise-save)
        geom.length = 0;
        const title = surface.querySelector('.sx-titlebar-text');

        pointer('pointerdown', title, 100, 100); // raises hello (it wasn't top) + arms the drag
        pointer('pointermove', window, 160, 140);
        await new Promise((r) => requestAnimationFrame(r));
        pointer('pointerup', window, 160, 140);

        // Exactly two facts: the raise (z) + the settled rect -- never more.
        const saves = geom.filter((e) => e.id === 'hello');
        expect(saves.length).toBe(2);
    });

    it('the WM stays transport-agnostic -- onGeometry defaults to a no-op (no fetch in the WM)', () => {
        const bare = new WindowManager(buildMount());
        // No onGeometry wired: a settle must not throw.
        expect(() => bare.minimise('hello')).not.toThrow();
    });
});

// Plan 5e, Task 4 (D4/D5): the boot RESTORE. The blade stamps a surface's persisted geometry as
// data-sx-* attributes; the WM adopt() applies it (move/resize/z/max/min) INSTEAD of cascading,
// re-clamps it to the live work area, rebases maxZ + focuses the highest-z non-minimised window,
// and cascades the never-positioned (null-geometry) windows ABOVE the restored stack. The whole
// boot block runs under a `restoring` guard so it fires ZERO onGeometry saves (D5/S1) -- the first
// post-boot user action saves normally.
describe('WindowManager: boot restore -- apply persisted geometry, not cascade (Plan 5e D4/D5)', () => {
    let mount;

    // Build a mount whose surfaces carry STAMPED geometry (mirrors the blade's data-sx-* stamp).
    // Each spec entry: { id, app, geom: { x, y, w, h, sized, max, min, z } | null }. A null geom
    // stamps NOTHING extra (the cascade path); a geom stamps x/y/z + (sized: w/h/data-sx-sized) +
    // (max/min flags). A MAXIMISED window stamps the RESTORE rect (x/y/w/h) + the flag, NOT the fill.
    function buildStampedMount(specs) {
        const m = document.createElement('div');
        m.className = 'sx-desktop';
        Object.defineProperty(m, 'clientWidth', { value: 1000, configurable: true });
        Object.defineProperty(m, 'clientHeight', { value: 700, configurable: true });
        for (const spec of specs) {
            const s = document.createElement('div');
            s.className = 'sx-window-surface';
            s.dataset.windowId = spec.id;
            s.dataset.app = spec.app ?? spec.id;
            const g = spec.geom;
            if (g) {
                s.dataset.sxX = String(g.x);
                s.dataset.sxY = String(g.y);
                if (g.z !== undefined && g.z !== null) {
                    s.dataset.sxZ = String(g.z);
                }
                if (g.sized) {
                    s.style.width = `${g.w}px`;
                    s.style.height = `${g.h}px`;
                    s.dataset.sxSized = 'true';
                }
                if (g.max) {
                    s.dataset.sxMax = 'true';
                }
                if (g.min) {
                    s.dataset.sxMin = 'true';
                }
            }
            m.appendChild(s);
        }
        document.body.replaceChildren(m);
        return m;
    }

    it('a surface WITH stamped geometry is applied (move/resize/z), NOT cascade-placed', () => {
        mount = buildStampedMount([
            { id: 'hello', geom: { x: 220, y: 140, w: 500, h: 360, sized: true, z: 3 } },
        ]);
        const wm = new WindowManager(mount);
        const surface = wm.surfaceFor('hello');

        // The position is the stamped rect (clamped, but it fits) -- NOT the cascade origin (40,40).
        expect(surface.dataset.sxX).toBe('220');
        expect(surface.dataset.sxY).toBe('140');
        expect(surface.style.transform).toBe('translate3d(220px, 140px, 0)');
        // The size + the sized flag survived the apply (the 5d fill rule needs both).
        expect(surface.style.width).toBe('500px');
        expect(surface.style.height).toBe('360px');
        expect(surface.dataset.sxSized).toBe('true');
        // The stacking z was applied.
        expect(Number(surface.style.zIndex)).toBe(3);
    });

    it('a surface with NULL geometry still cascades (the migrated cascade test)', () => {
        mount = buildStampedMount([
            { id: 'hello', geom: null },
            { id: 'notes', geom: null },
        ]);
        const wm = new WindowManager(mount);
        const hello = wm.surfaceFor('hello');
        const notes = wm.surfaceFor('notes');

        // Both cascade-placed (transform set, distinct) -- the existing scaffold contract.
        expect(hello.style.transform).not.toBe('');
        expect(notes.style.transform).not.toBe('');
        expect(hello.style.transform).not.toBe(notes.style.transform);
        // The cascade origin is 40,40 -- NOT a stamped rect.
        expect(hello.dataset.sxX).toBe('40');
    });

    it('a restored MINIMISED window keeps the min flag and is NOT focused/painted-over', () => {
        mount = buildStampedMount([
            { id: 'hello', geom: { x: 100, y: 100, z: 1, min: true } },
        ]);
        const wm = new WindowManager(mount);
        const surface = wm.surfaceFor('hello');

        expect(surface.dataset.sxMin).toBe('true');
        // The only window is minimised -> focus nothing (no crash).
        expect(wm.focused).toBeNull();
        expect(surface.dataset.sxActive).toBe('false');
    });

    it('a restored MAXIMISED window fills the FRESH work area AND seeds the stash from the restore rect', () => {
        // Persisted maximised: the stamped rect is the un-maximised RESTORE rect (NOT the fill).
        mount = buildStampedMount([
            { id: 'hello', geom: { x: 120, y: 90, w: 600, h: 400, sized: true, z: 2, max: true } },
        ]);
        const wm = new WindowManager(mount);
        const surface = wm.surfaceFor('hello');

        expect(surface.dataset.sxMax).toBe('true');
        // The maximised fill is computed FRESH against the live viewport (1000 x 700-PANEL_H=666),
        // NOT the stamped restore rect (600x400).
        expect(surface.style.width).toBe('1000px');
        expect(surface.style.height).toBe('666px');
        // The pre-max stash is the CLAMPED restore rect (so a later restore returns there, B1).
        const pre = JSON.parse(surface.dataset.sxPreMax);
        expect(pre).toEqual({ x: 120, y: 90, w: '600px', h: '400px', sized: true });
    });

    it('B1 coherence: restore-maximised-on-boot THEN restore() returns to the persisted restore rect', () => {
        mount = buildStampedMount([
            { id: 'hello', geom: { x: 120, y: 90, w: 600, h: 400, sized: true, z: 2, max: true } },
        ]);
        const wm = new WindowManager(mount);
        const surface = wm.surfaceFor('hello');

        wm.restore('hello');

        // It returns to the PERSISTED restore rect -- NOT the maximised fill.
        expect(surface.style.width).toBe('600px');
        expect(surface.style.height).toBe('400px');
        expect(surface.dataset.sxSized).toBe('true');
        expect(surface.dataset.sxX).toBe('120');
        expect(surface.dataset.sxY).toBe('90');
        expect(surface.dataset.sxMax).toBe('false');
    });

    // The boot-restore applies data-sx-max BEFORE the tree (and its control row) hydrate, so
    // applyGeometry can't swap the control then. syncMaximiseControl runs AFTER the frame paints
    // (the display server calls it post-reconcile) and flips the (rendered) maximise button to
    // restore -- without it a reloaded-maximised window shows a DEAD maximise button you can't
    // restore with. This pins the fix the Dusk reload proof surfaced.
    it('syncMaximiseControl flips a restored-maximised window control to restore after the frame paints', () => {
        mount = buildStampedMount([
            { id: 'hello', geom: { x: 120, y: 90, w: 600, h: 400, sized: true, z: 2, max: true } },
        ]);
        const wm = new WindowManager(mount);
        const surface = wm.surfaceFor('hello');

        // The morph paints the control row -- ALWAYS a `maximise` button (window.js). Mirror that:
        // a freshly-rendered maximise control inside the (already data-sx-max) surface.
        const win = document.createElement('div');
        win.className = 'sx-window';
        win.innerHTML = '<div class="sx-titlebar"><div class="sx-titlebar-controls">'
            + '<button data-sx-control="maximise" class="sx-window-control sx-window-control-maximise"><svg></svg></button>'
            + '</div></div>';
        surface.appendChild(win);

        // Before the sync the control is still the (dead) maximise button.
        expect(surface.querySelector('[data-sx-control="maximise"]')).not.toBeNull();
        expect(surface.querySelector('[data-sx-control="restore"]')).toBeNull();

        wm.syncMaximiseControl('hello');

        // After the sync it's the restore control -- clicking it now actually restores.
        expect(surface.querySelector('[data-sx-control="restore"]')).not.toBeNull();
        expect(surface.querySelector('[data-sx-control="maximise"]')).toBeNull();
    });

    it('syncMaximiseControl is a no-op for a non-maximised window', () => {
        mount = buildStampedMount([
            { id: 'hello', geom: { x: 100, y: 100, z: 1 } },
        ]);
        const wm = new WindowManager(mount);
        const surface = wm.surfaceFor('hello');
        const win = document.createElement('div');
        win.className = 'sx-window';
        win.innerHTML = '<button data-sx-control="maximise" class="sx-window-control sx-window-control-maximise"><svg></svg></button>';
        surface.appendChild(win);

        wm.syncMaximiseControl('hello');

        // Untouched -- the window isn't maximised.
        expect(surface.querySelector('[data-sx-control="maximise"]')).not.toBeNull();
        expect(surface.querySelector('[data-sx-control="restore"]')).toBeNull();
    });

    it('rebases maxZ to the highest restored z + focuses the highest-z non-minimised window', () => {
        mount = buildStampedMount([
            { id: 'hello', geom: { x: 100, y: 100, z: 2 } },
            { id: 'notes', geom: { x: 200, y: 200, z: 5 } },
            { id: 'about', geom: { x: 300, y: 300, z: 4 } },
        ]);
        const wm = new WindowManager(mount);

        // The highest-z (notes, z=5) is focused.
        expect(wm.focused).toBe('notes');
        expect(wm.surfaceFor('notes').dataset.sxActive).toBe('true');
        // maxZ rebased to 5 so a NEW raise lands above the restored stack.
        wm.bring('hello');
        expect(Number(wm.surfaceFor('hello').style.zIndex)).toBeGreaterThan(5);
    });

    it('focuses the highest-z NON-minimised window (a higher-z minimised one is skipped)', () => {
        mount = buildStampedMount([
            { id: 'hello', geom: { x: 100, y: 100, z: 2 } },
            { id: 'notes', geom: { x: 200, y: 200, z: 9, min: true } }, // higher z but minimised
        ]);
        const wm = new WindowManager(mount);

        expect(wm.focused).toBe('hello'); // notes is minimised -> skipped
    });

    it('all windows minimised -> focus nothing, no crash', () => {
        mount = buildStampedMount([
            { id: 'hello', geom: { x: 100, y: 100, z: 1, min: true } },
            { id: 'notes', geom: { x: 200, y: 200, z: 2, min: true } },
        ]);
        const wm = new WindowManager(mount);

        expect(wm.focused).toBeNull();
    });

    it('mixed null-z + positioned: the null-geometry window cascades ABOVE the restored stack', () => {
        mount = buildStampedMount([
            { id: 'hello', geom: { x: 100, y: 100, z: 5 } }, // positioned, high z
            { id: 'fresh', geom: null },                      // never-positioned -> cascade
        ]);
        const wm = new WindowManager(mount);
        const hello = wm.surfaceFor('hello');
        const fresh = wm.surfaceFor('fresh');

        // The cascaded fresh window sits ABOVE the restored stack (z > the restored 5) -- no z-fight.
        expect(Number(fresh.style.zIndex)).toBeGreaterThan(Number(hello.style.zIndex));
        // ...and it cascaded (origin 40,40), not applied a stamped rect.
        expect(fresh.dataset.sxX).toBe('40');
    });

    it('re-clamp: a persisted rect bigger than the smaller live work area is clamped in', () => {
        // The persisted rect came from a big screen; the live mount is 1000 x 700.
        mount = buildStampedMount([
            { id: 'hello', geom: { x: 1800, y: 1200, w: 1600, h: 1100, sized: true, z: 1 } },
        ]);
        const wm = new WindowManager(mount);
        const surface = wm.surfaceFor('hello');

        // The origin is clamped inside the reachable area (not off at 1800,1200).
        expect(Number(surface.dataset.sxX)).toBeLessThanOrEqual(1000);
        expect(Number(surface.dataset.sxY)).toBeLessThanOrEqual(700);
        // The size is clamped to the work area (never wider than the mount).
        expect(parseInt(surface.style.width, 10)).toBeLessThanOrEqual(1000);
        expect(parseInt(surface.style.height, 10)).toBeLessThanOrEqual(700);
    });

    it('the boot restore fires ZERO onGeometry saves; a subsequent user drag fires ONE (D5/S1)', async () => {
        // hello is the highest-z non-minimised window -> it's the boot-focused top. Dragging it
        // therefore fires exactly the ONE settled-rect save (no raise-save: it was already top),
        // cleanly isolating that the restoring guard cleared.
        mount = buildStampedMount([
            { id: 'hello', geom: { x: 120, y: 90, w: 600, h: 400, sized: true, z: 3 } },
            { id: 'notes', geom: { x: 200, y: 200, z: 2, min: true } },
        ]);
        const geom = [];
        const wm = new WindowManager(mount, { onGeometry: (id, g) => geom.push({ id, g }) });

        // The whole boot-restore (adopt + apply + boot-focus bring) fired NOTHING.
        expect(geom.length).toBe(0);

        // The guard is cleared -- a real post-boot drag DOES save.
        const surface = wm.surfaceFor('hello');
        surface.innerHTML = '<div class="sx-window"><div class="sx-titlebar">'
            + '<span class="sx-titlebar-text">Hello</span>'
            + '<div class="sx-titlebar-controls"><button data-sx-control="close"></button></div>'
            + '</div><div class="sx-content"></div></div>';
        const title = surface.querySelector('.sx-titlebar-text');
        const pointer = (type, target, x, y) => {
            const e = new Event(type, { bubbles: true });
            Object.assign(e, { clientX: x, clientY: y, pointerId: 1 });
            target.dispatchEvent(e);
        };
        pointer('pointerdown', title, 100, 100);
        pointer('pointermove', window, 160, 140);
        await new Promise((r) => requestAnimationFrame(r));
        pointer('pointerup', window, 160, 140);

        expect(geom.filter((e) => e.id === 'hello').length).toBe(1);
    });
});

// The snap geometry (Plan 5f, Task 1) -- the two PURE functions that decide WHICH zone a
// pointer is in (snapZoneFor) and the target rect for a zone (snapRectFor). No DOM mutation,
// no drag wiring (that's Task 3) -- just the math, mirroring the maximise work-area inset.
// 1000x700 mount, top panel: top = PANEL_H = 34, workH = clientHeight - PANEL_H = 666, W = 1000.
describe('WindowManager: snap geometry (Plan 5f D1/D3/D4)', () => {
    let mount, wm;
    beforeEach(() => {
        mount = buildMount();
        Object.defineProperty(mount, 'clientWidth', { value: 1000, configurable: true });
        Object.defineProperty(mount, 'clientHeight', { value: 700, configurable: true });
        wm = new WindowManager(mount, { panelPosition: 'top' });
    });

    // --- snapZoneFor: the single edges (mid-run, outside the corner bands) ---

    it('a pointer within EDGE of the left edge at MID-HEIGHT -> left', () => {
        expect(wm.snapZoneFor(5, 350)).toBe('left');
    });

    it('a pointer within EDGE of the right edge at MID-HEIGHT -> right', () => {
        expect(wm.snapZoneFor(995, 350)).toBe('right');
    });

    it('a pointer near the top edge, mid-width -> top', () => {
        expect(wm.snapZoneFor(500, 36)).toBe('top');
    });

    it('the centre -> null (no snap)', () => {
        expect(wm.snapZoneFor(500, 350)).toBeNull();
    });

    it('just inside the desktop but well off every edge -> null', () => {
        expect(wm.snapZoneFor(200, 300)).toBeNull();
    });

    // --- snapZoneFor: the corner band is HITTABLE (B1) -- a generous CORNER run, not a 24px box ---
    // The load-bearing pair: a point 60px down the left edge from the work-area top is INSIDE the
    // CORNER (~120) band -> 'tl'; the SAME left edge at mid-height (outside the band) -> 'left'.
    // A 24px corner box would fail the 60px corner assertion; only a CORNER-sized band passes both.

    it('left edge + within CORNER of the work-area top -> tl (corner wins, not left)', () => {
        // y = top(34) + 60 = 94, well inside the 120 corner band but past the 24px edge box.
        expect(wm.snapZoneFor(5, 94)).toBe('tl');
    });

    it('right edge + within CORNER of the work-area top -> tr', () => {
        expect(wm.snapZoneFor(995, 94)).toBe('tr');
    });

    it('left edge + within CORNER of the work-area bottom -> bl', () => {
        // work-area bottom = clientHeight - PANEL_H = 666; 60px up from it = 606, inside the band.
        expect(wm.snapZoneFor(5, 606)).toBe('bl');
    });

    it('right edge + within CORNER of the work-area bottom -> br', () => {
        expect(wm.snapZoneFor(995, 606)).toBe('br');
    });

    it('the same left edge at MID-HEIGHT (outside the CORNER band) -> left (the band is a run, not a box)', () => {
        expect(wm.snapZoneFor(5, 350)).toBe('left');
    });

    it('the exact top-left corner is tl, NOT left or top (corners checked first)', () => {
        expect(wm.snapZoneFor(0, 34)).toBe('tl');
    });

    // --- snapRectFor: the work-area fractions (top panel) ---

    it('snapRectFor(left) -> the left half {x:0, y:34, w:500, h:666}', () => {
        expect(wm.snapRectFor('left')).toEqual({ x: 0, y: 34, w: 500, h: 666 });
    });

    it('snapRectFor(right) -> the right half {x:500, y:34, w:500, h:666}', () => {
        expect(wm.snapRectFor('right')).toEqual({ x: 500, y: 34, w: 500, h: 666 });
    });

    it('snapRectFor(tl) -> the top-left quarter {x:0, y:34, w:500, h:333}', () => {
        expect(wm.snapRectFor('tl')).toEqual({ x: 0, y: 34, w: 500, h: 333 });
    });

    it('snapRectFor(br) -> the bottom-right quarter {x:500, y:367, w:500, h:333}', () => {
        // y = top(34) + workH/2(333) = 367.
        expect(wm.snapRectFor('br')).toEqual({ x: 500, y: 367, w: 500, h: 333 });
    });

    it('snapRectFor(top) -> the FULL work area {x:0, y:34, w:1000, h:666} (the maximise fill)', () => {
        expect(wm.snapRectFor('top')).toEqual({ x: 0, y: 34, w: 1000, h: 666 });
    });

    // --- the bottom-panel inset flip, for a QUARTER (not just a half) ---
    // BOTTOM panel: top = 0, workH = clientHeight - PANEL_H = 666. The bottom row ends at 666
    // (above the bottom panel), so bl = {x:0, y:333, w:500, h:333}.

    it('BOTTOM panel: snapRectFor(bl) flips the inset for a quarter -> {x:0, y:333, w:500, h:333}', () => {
        wm.setPanelPosition('bottom');
        expect(wm.snapRectFor('bl')).toEqual({ x: 0, y: 333, w: 500, h: 333 });
    });

    it('BOTTOM panel: snapRectFor(top) fills from y:0 -> {x:0, y:0, w:1000, h:666}', () => {
        wm.setPanelPosition('bottom');
        expect(wm.snapRectFor('top')).toEqual({ x: 0, y: 0, w: 1000, h: 666 });
    });
});

// The snap ghost (Plan 5f, Task 2) -- the body-mounted translucent rectangle that previews the
// target rect mid-drag (D2). showSnapGhost(rect) lazily CREATES one .sx-snap-ghost on
// document.body, positions it (fixed) at the rect, and shows it -- REUSING the same element on
// every subsequent call (no duplicates). hideSnapGhost() hides it and is safe with no ghost.
// The ghost is z 99999 (above every window surface, below the panel 100000) + pointer-events:none.
// No drag wiring here (Task 3) -- just the show/hide/position helpers + the CSS.
describe('WindowManager: snap ghost overlay (Plan 5f D2)', () => {
    let mount, wm;
    beforeEach(() => {
        mount = buildMount();
        Object.defineProperty(mount, 'clientWidth', { value: 1000, configurable: true });
        Object.defineProperty(mount, 'clientHeight', { value: 700, configurable: true });
        wm = new WindowManager(mount, { panelPosition: 'top' });
    });

    it('showSnapGhost creates a .sx-snap-ghost on document.body, positioned + visible', () => {
        wm.showSnapGhost({ x: 0, y: 34, w: 500, h: 666 });

        const ghost = document.body.querySelector('.sx-snap-ghost');
        expect(ghost).not.toBeNull();
        expect(ghost.parentElement).toBe(document.body);
        expect(ghost.style.position).toBe('fixed');
        expect(ghost.style.left).toBe('0px');
        expect(ghost.style.top).toBe('34px');
        expect(ghost.style.width).toBe('500px');
        expect(ghost.style.height).toBe('666px');
        // Visible -- not hidden.
        expect(ghost.hidden).toBe(false);
    });

    it('hideSnapGhost leaves no visible ghost', () => {
        wm.showSnapGhost({ x: 0, y: 34, w: 500, h: 666 });
        wm.hideSnapGhost();

        const ghost = document.body.querySelector('.sx-snap-ghost');
        // Either removed from the DOM, or present-but-hidden -- both are acceptable; nothing visible.
        const visible = ghost !== null && ghost.hidden === false;
        expect(visible).toBe(false);
    });

    it('hideSnapGhost is a safe no-op when no ghost exists', () => {
        expect(() => wm.hideSnapGhost()).not.toThrow();
        expect(document.body.querySelectorAll('.sx-snap-ghost').length).toBe(0);
    });

    it('showSnapGhost called twice repositions the SAME element (no duplicates)', () => {
        wm.showSnapGhost({ x: 0, y: 34, w: 500, h: 666 });
        wm.showSnapGhost({ x: 500, y: 34, w: 500, h: 666 });

        expect(document.querySelectorAll('.sx-snap-ghost').length).toBe(1);
        const ghost = document.querySelector('.sx-snap-ghost');
        expect(ghost.style.left).toBe('500px');
        expect(ghost.style.top).toBe('34px');
    });

    it('the ghost is pointer-events:none and z BELOW the panel AND above 0 (the intent, not a literal)', () => {
        wm.showSnapGhost({ x: 0, y: 34, w: 500, h: 666 });
        const ghost = document.querySelector('.sx-snap-ghost');

        // The class hook the CSS targets is present (the stylesheet carries pointer-events:none).
        expect(ghost.classList.contains('sx-snap-ghost')).toBe(true);

        // The z-index is below the panel (100000) and above the desktop (0) -- assert the intent.
        const z = Number(ghost.style.zIndex);
        expect(z).toBeLessThan(100000);
        expect(z).toBeGreaterThan(0);
    });
});

// The drag-snap integration (Plan 5f, Task 3 -- D3/D4/D5). Wires the snap geometry (Task 1) +
// the ghost (Task 2) into the live drag: onMove detects the zone on the RAW pointer + shows the
// ghost, onUp reads the STORED zone + snaps (top -> maximise; halves/quarters -> resize + bring +
// notifyGeometry ONCE) + hides the ghost UNCONDITIONALLY, pointercancel cleans up without snapping.
// The work area here is 1000x700 with a top panel (PANEL_H 34): x[0,1000], y[34,700]; the left half
// is {x:0,y:34,w:500,h:666}, the quarters {w:500,h:333}. The pointer coords drive snapZoneFor:
// clientX<=24 is the left edge, >=976 the right; the corner band runs ~120px off the work-area top
// (34) / bottom (700).
describe('WindowManager: drag snap (Plan 5f D3/D4/D5)', () => {
    let mount, wm, surface, geom;
    beforeEach(() => {
        mount = buildMount();
        Object.defineProperty(mount, 'clientWidth', { value: 1000, configurable: true });
        Object.defineProperty(mount, 'clientHeight', { value: 700, configurable: true });
        geom = [];
        wm = new WindowManager(mount, { panelPosition: 'top', onGeometry: (id, g) => geom.push({ id, g }) });
        surface = wm.surfaceFor('hello');
        surface.innerHTML = '<div class="sx-window"><div class="sx-titlebar">'
            + '<span class="sx-titlebar-text">Hello</span>'
            + '<div class="sx-titlebar-controls">'
            + '<button data-sx-control="maximise" class="sx-window-control sx-window-control-maximise"><svg></svg></button>'
            + '<button data-sx-control="close" class="sx-window-control sx-window-control-close"><svg></svg></button>'
            + '</div></div>'
            + '<div class="sx-content"></div></div>';
    });

    function pointer(type, target, x, y) {
        const e = new Event(type, { bubbles: true });
        Object.assign(e, { clientX: x, clientY: y, pointerId: 1 });
        (target ?? surface).dispatchEvent(e);
        return e;
    }

    async function dragTo(x, y) {
        const title = surface.querySelector('.sx-titlebar-text');
        pointer('pointerdown', title, 100, 100);
        pointer('pointermove', window, x, y);
        await new Promise((r) => requestAnimationFrame(r)); // flush the rAF position batch
        pointer('pointerup', window, x, y);
    }

    it('a drag ending in the LEFT zone snaps the surface to the left-half rect, raised + focused', async () => {
        geom.length = 0;
        await dragTo(5, 350); // clientX 5 <= EDGE, mid-height -> left half

        expect(surface.dataset.sxSized).toBe('true');
        expect(Number(surface.dataset.sxX)).toBe(0);
        expect(Number(surface.dataset.sxY)).toBe(34);
        expect(parseInt(surface.style.width, 10)).toBe(500);
        expect(parseInt(surface.style.height, 10)).toBe(666);
        // Raised + focused.
        expect(surface.dataset.sxActive).toBe('true');
        // Exactly ONE geometry POST for the snap (no double-notify).
        expect(geom.filter((e) => e.id === 'hello').length).toBe(1);
        const g = geom.filter((e) => e.id === 'hello')[0].g;
        expect(g.sized).toBe(true);
        expect(g.w).toBe(500);
        expect(g.h).toBe(666);
    });

    it('a drag ending in the RIGHT zone snaps to the right-half rect', async () => {
        await dragTo(995, 350);
        expect(Number(surface.dataset.sxX)).toBe(500);
        expect(parseInt(surface.style.width, 10)).toBe(500);
        expect(parseInt(surface.style.height, 10)).toBe(666);
    });

    it.each([
        ['tl', 5, 80, 0, 34],
        ['tr', 995, 80, 500, 34],
        ['bl', 5, 620, 0, 367],
        ['br', 995, 620, 500, 367],
    ])('a drag ending in the %s corner snaps to the quarter rect', async (zone, px, py, ex, ey) => {
        geom.length = 0;
        await dragTo(px, py);

        expect(Number(surface.dataset.sxX)).toBe(ex);
        expect(Number(surface.dataset.sxY)).toBe(ey);
        expect(parseInt(surface.style.width, 10)).toBe(500);
        expect(parseInt(surface.style.height, 10)).toBe(333);
        expect(surface.dataset.sxSized).toBe('true');
        // ONE geometry POST per quarter snap.
        expect(geom.filter((e) => e.id === 'hello').length).toBe(1);
    });

    it('a drag ending in the TOP zone maximises the window AND fires exactly ONE geometry POST', async () => {
        geom.length = 0;
        await dragTo(500, 36); // mid-width, near the top -> maximise

        expect(surface.dataset.sxMax).toBe('true');
        // maximise self-fires notifyGeometry ONCE -- the snap wrapper must NOT add a second.
        const saves = geom.filter((e) => e.id === 'hello');
        expect(saves.length).toBe(1);
        expect(saves[0].g.maximised).toBe(true);
    });

    it('a drag ending in NO zone is the normal drop (no snap, one settle save)', async () => {
        geom.length = 0;
        const before = surface.dataset.sxSized;
        await dragTo(400, 300); // dead centre -- no zone

        // No snap: data-sx-sized is unchanged (still un-sized from a plain move).
        expect(surface.dataset.sxSized).toBe(before);
        expect(surface.dataset.sxMax).not.toBe('true');
        // The normal drag-end settle fires once.
        expect(geom.filter((e) => e.id === 'hello').length).toBe(1);
    });

    it('the no-move case: grab + release with NO pointermove does NOT snap, the ghost is never shown', () => {
        const title = surface.querySelector('.sx-titlebar-text');
        geom.length = 0;

        pointer('pointerdown', title, 5, 5); // over the corner, but no move follows
        pointer('pointerup', window, 5, 5);

        // No snap -- sxSized/sxMax untouched by a snap.
        expect(surface.dataset.sxMax).not.toBe('true');
        // The ghost was never created (snapZone stayed undefined -> showSnapGhost never ran).
        expect(document.querySelector('.sx-snap-ghost')).toBeNull();
    });

    it('the ghost SHOWS while the pointer is in a zone mid-drag and HIDES on release', async () => {
        const title = surface.querySelector('.sx-titlebar-text');
        pointer('pointerdown', title, 100, 100);
        pointer('pointermove', window, 5, 350); // into the left zone

        const ghost = document.querySelector('.sx-snap-ghost');
        expect(ghost).not.toBeNull();
        expect(ghost.hidden).toBe(false);
        expect(ghost.style.left).toBe('0px');
        expect(ghost.style.width).toBe('500px');

        await new Promise((r) => requestAnimationFrame(r));
        pointer('pointerup', window, 5, 350);

        // Hidden on release (snapped).
        expect(document.querySelector('.sx-snap-ghost').hidden).toBe(true);
    });

    it('the ghost HIDES on a release outside any zone (not just on a snap)', async () => {
        const title = surface.querySelector('.sx-titlebar-text');
        pointer('pointerdown', title, 100, 100);
        pointer('pointermove', window, 5, 350); // ghost shown
        expect(document.querySelector('.sx-snap-ghost').hidden).toBe(false);

        pointer('pointermove', window, 400, 300); // back to centre -- no zone
        await new Promise((r) => requestAnimationFrame(r));
        pointer('pointerup', window, 400, 300);

        expect(document.querySelector('.sx-snap-ghost').hidden).toBe(true);
    });

    it('the ghost repositions when the pointer moves from one zone to another mid-drag', () => {
        const title = surface.querySelector('.sx-titlebar-text');
        pointer('pointerdown', title, 100, 100);

        pointer('pointermove', window, 5, 350); // left zone
        expect(document.querySelector('.sx-snap-ghost').style.left).toBe('0px');

        pointer('pointermove', window, 995, 350); // right zone -- same ghost, new position
        expect(document.querySelectorAll('.sx-snap-ghost').length).toBe(1);
        expect(document.querySelector('.sx-snap-ghost').style.left).toBe('500px');

        pointer('pointerup', window, 995, 350);
    });

    it('pointercancel mid-drag clears the drag + hides the ghost (no stuck overlay, no snap)', () => {
        const title = surface.querySelector('.sx-titlebar-text');
        geom.length = 0;
        pointer('pointerdown', title, 100, 100);
        pointer('pointermove', window, 5, 350); // ghost shown, left zone armed
        expect(document.querySelector('.sx-snap-ghost').hidden).toBe(false);

        pointer('pointercancel', window, 5, 350);

        // The drag state is cleared.
        expect(wm.drag).toBeNull();
        // The ghost is hidden (no stuck overlay).
        expect(document.querySelector('.sx-snap-ghost').hidden).toBe(true);
        // No snap fired -- the surface was NOT sized/maximised by a cancel.
        expect(surface.dataset.sxMax).not.toBe('true');
    });

    it('a server frame mid-drag still does NOT move the surface (D3 unchanged)', async () => {
        const title = surface.querySelector('.sx-titlebar-text');
        pointer('pointerdown', title, 100, 100);
        pointer('pointermove', window, 150, 150);
        await new Promise((r) => requestAnimationFrame(r));
        const midDrag = surface.style.transform;

        reconcile(surface, { type: 'window', id: null, props: { title: 'Hello', width: 360, height: 280 }, children: [] }, { registry });

        expect(surface.style.transform).toBe(midDrag);
        pointer('pointerup', window, 150, 150);
    });
});
