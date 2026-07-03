import { describe, it, expect } from 'vitest';
import { registry } from '../../resources/js/system-x/renderers.js';
import { CONTROL_GLYPHS } from '../../resources/js/system-x/widgets/window.js';

function renderWindow(props = {}) {
    const surface = document.createElement('div');
    surface.className = 'sx-window-surface';
    const node = { type: 'window', id: null, props: { title: 'Hello', width: 360, height: 280, ...props }, children: [] };
    surface.appendChild(registry.render(node, { registry }));
    return surface.querySelector('.sx-window');
}

describe('window chrome controls (D5)', () => {
    it('renders minimise, maximise, and close controls in the titlebar', () => {
        const win = renderWindow();
        const controls = win.querySelector('.sx-titlebar .sx-titlebar-controls');

        expect(controls).not.toBeNull();
        expect(controls.querySelector('[data-sx-control="close"]')).not.toBeNull();
        expect(controls.querySelector('[data-sx-control="maximise"]')).not.toBeNull();
        // Minimise is now PRESENT (5b D4 -- the panel is its target).
        expect(controls.querySelector('[data-sx-control="minimise"]')).not.toBeNull();
    });

    it('orders the controls minimise-then-maximise-then-close (the mockup order)', () => {
        const win = renderWindow();
        const buttons = [...win.querySelectorAll('.sx-titlebar-controls [data-sx-control]')];
        expect(buttons.map((b) => b.dataset.sxControl)).toEqual(['minimise', 'maximise', 'close']);
    });

    it('puts the title text in its own span so update() can patch it without touching controls', () => {
        const win = renderWindow({ title: 'Notes' });
        expect(win.querySelector('.sx-titlebar .sx-titlebar-text').textContent).toBe('Notes');
    });

    it('the controls do NOT round-trip as widget events (no data-sx-events on them)', () => {
        const win = renderWindow();
        const close = win.querySelector('[data-sx-control="close"]');
        // Chrome is WM-local: the close control is handled by the WM (Task 9), not POSTed
        // as a widget event, so it carries no props.events allowlist hook.
        expect(close.hasAttribute('data-sx-events')).toBe(false);
    });

    it('each control is a real button carrying its glyph SVG', () => {
        const win = renderWindow();
        for (const kind of ['minimise', 'close', 'maximise']) {
            const btn = win.querySelector(`[data-sx-control="${kind}"]`);
            expect(btn.tagName).toBe('BUTTON');
            expect(btn.type).toBe('button');
            expect(btn.getAttribute('aria-label')).toBe(kind);
            expect(btn.querySelector('svg')).not.toBeNull();
        }
    });

    it('exports CONTROL_GLYPHS with close/maximise/restore for the Task 5 swap (N1)', () => {
        expect(CONTROL_GLYPHS).toBeTypeOf('object');
        expect(CONTROL_GLYPHS.close).toBeTypeOf('string');
        expect(CONTROL_GLYPHS.maximise).toBeTypeOf('string');
        expect(CONTROL_GLYPHS.restore).toBeTypeOf('string');
    });

    it('emits exactly eight resize handles (4 edges + 4 corners) inside .sx-window (D8)', () => {
        const win = renderWindow();
        const handles = [...win.querySelectorAll('[data-sx-resize]')];
        expect(handles.length).toBe(8);
        expect(handles.map((h) => h.dataset.sxResize).sort()).toEqual(
            ['e', 'n', 'ne', 'nw', 's', 'se', 'sw', 'w'],
        );
    });

    it('gives each resize handle the shared + per-direction class hooks for the CSS', () => {
        const win = renderWindow();
        for (const dir of ['n', 'e', 's', 'w', 'ne', 'nw', 'se', 'sw']) {
            const handle = win.querySelector(`[data-sx-resize="${dir}"]`);
            expect(handle).not.toBeNull();
            expect(handle.classList.contains('sx-resize-handle')).toBe(true);
            expect(handle.classList.contains(`sx-resize-${dir}`)).toBe(true);
        }
    });

    it('keeps the resize handles OUTSIDE .sx-content so the morph never reconciles them (Landmine 1)', () => {
        const win = renderWindow();
        const content = win.querySelector('.sx-content');
        expect(content.querySelector('[data-sx-resize]')).toBeNull();
        // .sx-window stays the surface's only/first child; handles ride inside it like the titlebar.
        for (const handle of win.querySelectorAll('[data-sx-resize]')) {
            expect(handle.parentElement).toBe(win);
        }
    });

    it('the resize handles do NOT round-trip as widget events (no data-sx-events on them)', () => {
        const win = renderWindow();
        for (const handle of win.querySelectorAll('[data-sx-resize]')) {
            expect(handle.hasAttribute('data-sx-events')).toBe(false);
        }
    });

    it('update() leaves the resize handles untouched -- same count + identity after a frame (D3)', () => {
        const surface = document.createElement('div');
        surface.className = 'sx-window-surface';
        const node = { type: 'window', id: 'w1', props: { title: 'Hello', width: 360, height: 280 }, children: [] };
        const el = registry.render(node, { registry });
        surface.appendChild(el);

        const before = [...el.querySelectorAll('[data-sx-resize]')];
        registry.get('window').update(
            el,
            { type: 'window', id: 'w1', props: { title: 'Renamed', width: 360, height: 280 }, children: [] },
            { registry, reconcileChildren: () => {} },
        );
        const after = [...el.querySelectorAll('[data-sx-resize]')];

        expect(after.length).toBe(8);
        // Identity unchanged -- the morph did not recreate them.
        expect(after).toEqual(before);
    });

    it('update() patches the title text span without losing or duplicating the controls', () => {
        const surface = document.createElement('div');
        surface.className = 'sx-window-surface';
        const node = { type: 'window', id: 'w1', props: { title: 'Hello', width: 360, height: 280 }, children: [] };
        const el = registry.render(node, { registry });
        surface.appendChild(el);

        registry.get('window').update(
            el,
            { type: 'window', id: 'w1', props: { title: 'Renamed', width: 360, height: 280 }, children: [] },
            { registry, reconcileChildren: () => {} },
        );

        expect(el.querySelector('.sx-titlebar-text').textContent).toBe('Renamed');
        // The morph must PRESERVE the framework chrome -- exactly one of each, not zero, not doubled.
        expect(el.querySelectorAll('[data-sx-control="close"]').length).toBe(1);
        expect(el.querySelectorAll('[data-sx-control="maximise"]').length).toBe(1);
    });
});

describe('window declared size is a height cap (V1-A)', () => {
    it('stamps an exact style.height (a cap), not style.minHeight (a floor)', () => {
        const win = renderWindow({ width: 360, height: 280 });
        expect(win.style.height).toBe('280px');
        expect(win.style.minHeight).toBe('');   // no floor -- the window can't grow past its height
        expect(win.style.width).toBe('360px');  // width hint unchanged
    });
});
