import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { registry } from '../../resources/js/system-x/renderer-registry.js';
import '../../resources/js/system-x/renderers.js'; // self-registers core widgets
import { dialogRenderer } from '../../resources/js/system-x/widgets/dialog.js';
import { menuButtonRenderer } from '../../resources/js/system-x/widgets/menubutton.js';
import { menuBarRenderer } from '../../resources/js/system-x/widgets/menubar.js';
import { tooltipRenderer } from '../../resources/js/system-x/widgets/tooltip.js';

const node = (type, props = {}, id = null, children = []) => ({ type, id, props, children });

describe('core widget renderers', () => {
    it('window create() builds titlebar + content and nests children through the registry', () => {
        const ctx = { registry };
        const el = registry.render(
            node('window', { title: 'Hi', width: 320, height: 160 }, 'w1', [
                node('label', { text: 'hello' }, 'l1'),
            ]),
            ctx,
        );
        expect(el.className).toBe('sx-window');
        expect(el.dataset.sxId).toBe('w1');
        expect(el.dataset.sxType).toBe('window'); // stamped by the registry
        // The renderer NO LONGER owns focus (Plan 5a, D3): data-sx-active lives on the
        // WM-owned .sx-window-surface, not the .sx-window, so a server frame can never
        // re-stamp or reset focus. create() must not touch active state.
        expect(el.dataset.sxActive).toBeUndefined();
        expect(el.querySelector('.sx-titlebar').textContent).toBe('Hi');
        expect(el.querySelector('.sx-content .sx-label').textContent).toBe('hello');
        // The class hooks base.css targets must be present on the real DOM contract.
        expect(el.querySelector('.sx-titlebar')).not.toBeNull();
        expect(el.querySelector('.sx-content')).not.toBeNull();
    });

    it('every core renderer stamps data-sx-type through the registry render path', () => {
        for (const type of ['window', 'label', 'button']) {
            const el = registry.render(node(type, { title: 't', width: 10, height: 10, text: 't', label: 't' }, 'x'), { registry });
            expect(el.dataset.sxType).toBe(type);
        }
    });

    it('label update() patches text in place and keeps the SAME node', () => {
        const el = registry.render(node('label', { text: 'a' }, 'l1'), { registry });
        const before = el;
        registry.get('label').update(el, node('label', { text: 'b' }, 'l1'), { registry });
        expect(el).toBe(before);
        expect(el.textContent).toBe('b');
    });

    it('button create() stamps the events allowlist and does NOT attach a listener', () => {
        const el = registry.render(
            node('button', { label: 'Go', events: ['click'] }, 'clicker'),
            { registry },
        );
        expect(el.className).toBe('sx-button');
        expect(el.dataset.sxId).toBe('clicker');
        expect(el.dataset.sxEvents).toBe('click');
        expect(el.textContent).toBe('Go');
    });

    it('a button with a pref prop stamps data-sx-pref (the Appearance control hook)', () => {
        const el = registry.render(
            node('button', { label: 'Pewter', pref: 'theme:pewter' }, 'b'),
            { registry },
        );
        expect(el.getAttribute('data-sx-pref')).toBe('theme:pewter');
    });

    it('a pref button clears its events allowlist so it never round-trips (B2/D2)', () => {
        // Button::pref() sets props.events = [] server-side; the renderer stamps data-sx-events=""
        // -> the dispatcher's allow() filters to [] -> no 'click' round-trip (the pref click is
        // owned by the document-level interceptor only). Here we render the node the PHP would emit.
        const el = registry.render(
            node('button', { label: 'Pewter', pref: 'theme:pewter', events: [] }, 'b'),
            { registry },
        );
        // data-sx-events is empty (''), so the dispatcher's allow() -> ''.split(',').filter(Boolean) === []
        expect(el.dataset.sxEvents).toBe('');
        expect((el.dataset.sxEvents ?? '').split(',').filter(Boolean)).not.toContain('click');
    });

    it('a button with an appAction prop stamps data-sx-app-action (the Manage-apps toggle hook)', () => {
        const el = registry.render(
            node('button', { label: 'Toggle', appAction: 'hello' }, 'b'),
            { registry },
        );
        expect(el.getAttribute('data-sx-app-action')).toBe('hello');
    });

    it('an appAction button clears its events allowlist so it never round-trips (like pref)', () => {
        // Button::appAction() sets props.events = [] server-side, exactly like pref() -- the
        // Manage-apps client interceptor owns the click, no App round-trip. Render the node PHP emits.
        const el = registry.render(
            node('button', { label: 'Toggle', appAction: 'hello', events: [] }, 'b'),
            { registry },
        );
        expect(el.dataset.sxEvents).toBe('');
        expect((el.dataset.sxEvents ?? '').split(',').filter(Boolean)).not.toContain('click');
    });

    it('box create() builds a .sx-box row and nests children through the registry', () => {
        const el = registry.render(
            node('box', {}, 'b1', [node('label', { text: 'hi' }, 'l1')]),
            { registry },
        );
        expect(el.className).toBe('sx-box');
        expect(el.dataset.sxId).toBe('b1');
        expect(el.querySelector('.sx-label').textContent).toBe('hi');
    });

    it('grid create() builds a .sx-grid and sets template columns from props.columns', () => {
        const el = registry.render(
            node('grid', { columns: 3 }, 'g1', [node('label', { text: 'a' }, 'l1')]),
            { registry },
        );
        expect(el.className).toBe('sx-grid');
        expect(el.dataset.sxId).toBe('g1');
        expect(el.style.gridTemplateColumns).toBe('repeat(3, 1fr)');
        expect(el.querySelector('.sx-label').textContent).toBe('a');
    });

    it('grid update() re-applies the column count when it changes', () => {
        const el = registry.render(node('grid', { columns: 2 }, 'g1', []), { registry });
        const ctx = { registry, reconcileChildren: () => {} };
        registry.get('grid').update(el, node('grid', { columns: 4 }, 'g1', []), ctx);
        expect(el.style.gridTemplateColumns).toBe('repeat(4, 1fr)');
    });

    it('badge create() renders a toned pill with the text', () => {
        const el = registry.render(node('badge', { text: 'New', tone: 'ok' }, 'b1'), { registry });
        expect(el.className).toContain('sx-badge');
        expect(el.className).toContain('sx-badge--ok');
        expect(el.dataset.sxId).toBe('b1');
        expect(el.textContent).toBe('New');
    });

    it('badge update() patches text and tone in place', () => {
        const el = registry.render(node('badge', { text: 'a', tone: 'neutral' }, 'b1'), { registry });
        registry.get('badge').update(el, node('badge', { text: 'b', tone: 'warn' }, 'b1'), { registry });
        expect(el.textContent).toBe('b');
        expect(el.className).toContain('sx-badge--warn');
        expect(el.className).not.toContain('sx-badge--neutral');
    });

    it('separator create() renders a horizontal role=separator div', () => {
        const el = registry.render(node('separator', { orientation: 'horizontal' }, 's1'), { registry });
        expect(el.getAttribute('role')).toBe('separator');
        expect(el.className).toContain('sx-separator');
        expect(el.className).toContain('sx-separator--horizontal');
        expect(el.dataset.sxId).toBe('s1');
    });

    it('separator update() swaps orientation modifier in place', () => {
        const el = registry.render(node('separator', { orientation: 'horizontal' }, 's1'), { registry });
        registry.get('separator').update(el, node('separator', { orientation: 'vertical' }, 's1'), { registry });
        expect(el.className).toContain('sx-separator--vertical');
        expect(el.className).not.toContain('sx-separator--horizontal');
        expect(el.getAttribute('aria-orientation')).toBe('vertical');
    });

    it('groupbox create() builds fieldset+legend and renders children in the body host', () => {
        const ctx = { registry };
        const el = registry.render(
            node('groupbox', { legend: 'Settings' }, 'g1', [node('label', { text: 'inside' }, 'l1')]),
            ctx,
        );
        expect(el.tagName).toBe('FIELDSET');
        expect(el.dataset.sxId).toBe('g1');
        expect(el.querySelector('.sx-groupbox-legend').textContent).toBe('Settings');
        // children live in the body host, NOT as direct fieldset children alongside the legend
        const body = el.querySelector('.sx-groupbox-body');
        expect(body).not.toBeNull();
        expect(body.querySelector('.sx-label').textContent).toBe('inside');
    });

    it('groupbox update() reconciles children in the body and leaves the legend intact', () => {
        const reconcileChildren = (hostEl, children, c) => {
            // minimal positional reconcile stub mirroring the real one for the test:
            hostEl.replaceChildren(...children.map((ch) => registry.render(ch, c)));
        };
        const ctx = { registry, reconcileChildren };
        const el = registry.render(node('groupbox', { legend: 'S' }, 'g1', [node('label', { text: 'a' }, 'l1')]), ctx);
        registry.get('groupbox').update(
            el,
            node('groupbox', { legend: 'S2' }, 'g1', [node('label', { text: 'a' }, 'l1'), node('label', { text: 'b' }, 'l2')]),
            ctx,
        );
        expect(el.querySelector('.sx-groupbox-legend').textContent).toBe('S2'); // legend untouched by child reconcile
        const body = el.querySelector('.sx-groupbox-body');
        expect(body.querySelectorAll('.sx-label').length).toBe(2); // children grew in the body
    });

    it('progressbar create() sets role, aria, and a fill tracking value', () => {
        const el = registry.render(node('progressbar', { value: 40, indeterminate: false, label: null }, 'p1'), { registry });
        expect(el.getAttribute('role')).toBe('progressbar');
        expect(el.getAttribute('aria-valuenow')).toBe('40');
        expect(el.getAttribute('aria-valuemin')).toBe('0');
        expect(el.getAttribute('aria-valuemax')).toBe('100');
        expect(el.dataset.sxId).toBe('p1');
        const fill = el.querySelector('.sx-progressbar-fill');
        expect(fill.style.width).toBe('40%');
    });

    it('progressbar indeterminate drops aria-valuenow and adds the modifier', () => {
        const el = registry.render(node('progressbar', { value: 0, indeterminate: true, label: null }, 'p1'), { registry });
        expect(el.className).toContain('sx-progressbar--indeterminate');
        expect(el.hasAttribute('aria-valuenow')).toBe(false);
    });

    it('progressbar renders a label + percent readout when label is set', () => {
        const el = registry.render(node('progressbar', { value: 60, indeterminate: false, label: 'Sync' }, 'p1'), { registry });
        const label = el.querySelector('.sx-progressbar-label');
        expect(label).not.toBeNull();
        expect(label.textContent).toContain('Sync');
        expect(label.textContent).toContain('60%');
    });

    it('progressbar update() patches value, modifier, aria, and label in place', () => {
        const el = registry.render(node('progressbar', { value: 10, indeterminate: false, label: null }, 'p1'), { registry });
        registry.get('progressbar').update(el, node('progressbar', { value: 75, indeterminate: false, label: 'Done' }, 'p1'), { registry });
        expect(el.querySelector('.sx-progressbar-fill').style.width).toBe('75%');
        expect(el.getAttribute('aria-valuenow')).toBe('75');
        expect(el.querySelector('.sx-progressbar-label').textContent).toContain('Done');
    });

    it('progressbar update() removes the label span when label goes set -> null', () => {
        const el = registry.render(node('progressbar', { value: 50, indeterminate: false, label: 'Done' }, 'p1'), { registry });
        expect(el.querySelector('.sx-progressbar-label')).not.toBeNull();
        registry.get('progressbar').update(el, node('progressbar', { value: 50, indeterminate: false, label: null }, 'p1'), { registry });
        expect(el.querySelector('.sx-progressbar-label')).toBeNull();
    });

    it('switch create() builds a labelled checkbox-backed toggle with events on the input', () => {
        const el = registry.render(node('switch', { label: 'Wifi', checked: true, events: ['change'] }, 'sw1'), { registry });
        expect(el.className).toContain('sx-switch');
        expect(el.dataset.sxId).toBe('sw1');
        const input = el.querySelector('input[type=checkbox]');
        expect(input.checked).toBe(true);
        expect(input.dataset.sxEvents).toBe('change');
        expect(input.dataset.sxId).toBeUndefined(); // id on the wrapper, not the input
    });

    it('switch update() syncs checked when unfocused', () => {
        const el = registry.render(node('switch', { label: 'Wifi', checked: false, events: ['change'] }, 'sw1'), { registry });
        registry.get('switch').update(el, node('switch', { label: 'Wifi', checked: true, events: ['change'] }, 'sw1'), { registry });
        expect(el.querySelector('input[type=checkbox]').checked).toBe(true);
    });

    it('progressbar update() drops stale aria-valuenow and adds the modifier on determinate -> indeterminate', () => {
        const el = registry.render(node('progressbar', { value: 40, indeterminate: false, label: null }, 'p1'), { registry });
        expect(el.getAttribute('aria-valuenow')).toBe('40');
        registry.get('progressbar').update(el, node('progressbar', { value: 40, indeterminate: true, label: null }, 'p1'), { registry });
        expect(el.hasAttribute('aria-valuenow')).toBe(false);
        expect(el.className).toContain('sx-progressbar--indeterminate');
    });

    it('select create() builds a native select with options + selected value, events on the select', () => {
        const el = registry.render(
            node('select', { label: 'Theme', options: { light: 'Light', dark: 'Dark' }, value: 'dark', events: ['change'] }, 'se1'),
            { registry },
        );
        expect(el.className).toContain('sx-select');
        expect(el.dataset.sxId).toBe('se1');
        const select = el.querySelector('select');
        expect(select.dataset.sxEvents).toBe('change');
        expect(select.dataset.sxId).toBeUndefined(); // id on the wrapper, not the select
        expect(select.querySelectorAll('option').length).toBe(2);
        expect(select.value).toBe('dark');
    });

    it('select update() syncs the selected value when unfocused', () => {
        const el = registry.render(
            node('select', { label: 'Theme', options: { light: 'Light', dark: 'Dark' }, value: 'light', events: ['change'] }, 'se1'),
            { registry },
        );
        registry.get('select').update(
            el,
            node('select', { label: 'Theme', options: { light: 'Light', dark: 'Dark' }, value: 'dark', events: ['change'] }, 'se1'),
            { registry },
        );
        expect(el.querySelector('select').value).toBe('dark');
    });

    it('radiogroup create() builds inline radios sharing a name, events on inputs, id on wrapper', () => {
        const el = registry.render(
            node('radiogroup', { label: 'Size', options: { s: 'Small', l: 'Large' }, value: 'l', events: ['change'] }, 'rg1'),
            { registry },
        );
        expect(el.className).toContain('sx-radiogroup');
        expect(el.dataset.sxId).toBe('rg1');
        const inputs = el.querySelectorAll('input[type=radio]');
        expect(inputs.length).toBe(2);
        // all share one name; none carries its own data-sx-id; each carries data-sx-events
        const names = new Set([...inputs].map((i) => i.name));
        expect(names.size).toBe(1);
        for (const i of inputs) {
            expect(i.dataset.sxId).toBeUndefined();
            expect(i.dataset.sxEvents).toBe('change');
        }
        // the value-matching radio is checked
        expect([...inputs].find((i) => i.value === 'l').checked).toBe(true);
        expect([...inputs].find((i) => i.value === 's').checked).toBe(false);
    });

    it('radiogroup names are id-derived so two groups on one desktop never collide', () => {
        const opts = { s: 'Small', l: 'Large' };
        const a = registry.render(node('radiogroup', { label: 'Size', options: opts, value: 's', events: ['change'] }, 'rg1'), { registry });
        const b = registry.render(node('radiogroup', { label: 'Size', options: opts, value: 's', events: ['change'] }, 'rg2'), { registry });
        const nameA = a.querySelector('input[type=radio]').name;
        const nameB = b.querySelector('input[type=radio]').name;
        // Distinct names => selecting in rg1 can't clear rg2 (the silent cross-contam bug).
        expect(nameA).not.toBe(nameB);
        // And every radio within a group still shares its group's name.
        for (const i of a.querySelectorAll('input[type=radio]')) expect(i.name).toBe(nameA);
        for (const i of b.querySelectorAll('input[type=radio]')) expect(i.name).toBe(nameB);
    });

    it('radiogroup update() moves the checked radio to the new value when unfocused', () => {
        const el = registry.render(
            node('radiogroup', { label: 'Size', options: { s: 'Small', l: 'Large' }, value: 's', events: ['change'] }, 'rg1'),
            { registry },
        );
        registry.get('radiogroup').update(
            el,
            node('radiogroup', { label: 'Size', options: { s: 'Small', l: 'Large' }, value: 'l', events: ['change'] }, 'rg1'),
            { registry },
        );
        const inputs = el.querySelectorAll('input[type=radio]');
        expect([...inputs].find((i) => i.value === 'l').checked).toBe(true);
    });

    it('radiogroup update() repatches label + rebuilds radios when options change, preserving the wrapper/events contract', () => {
        const el = registry.render(
            node('radiogroup', { label: 'Size', options: { s: 'Small', l: 'Large' }, value: 's', events: ['change'] }, 'rg1'),
            { registry },
        );

        // Update with a new label AND a completely different options set; value matches 'xl' in the new set.
        registry.get('radiogroup').update(
            el,
            node('radiogroup', { label: 'Fit', options: { xs: 'Extra Small', xl: 'Extra Large', xxl: 'Double XL' }, value: 'xl', events: ['change'] }, 'rg1'),
            { registry },
        );

        // (a) legend repatched
        expect(el.querySelector('.sx-radiogroup-label').textContent).toBe('Fit');

        // (b) radios rebuilt to the new set (3, not 2; old values gone)
        const inputs = el.querySelectorAll('input[type=radio]');
        expect(inputs.length).toBe(3);
        const values = [...inputs].map((i) => i.value);
        expect(values).toContain('xs');
        expect(values).toContain('xl');
        expect(values).toContain('xxl');
        expect(values).not.toContain('s');
        expect(values).not.toContain('l');

        // (c) the value-matching radio is checked
        expect([...inputs].find((i) => i.value === 'xl').checked).toBe(true);
        expect([...inputs].find((i) => i.value === 'xs').checked).toBe(false);

        // (d) wrapper still has data-sx-id; rebuilt inputs carry data-sx-events + shared name; no data-sx-id on inputs
        expect(el.dataset.sxId).toBe('rg1');
        const names = new Set([...inputs].map((i) => i.name));
        expect(names.size).toBe(1); // all share one name
        for (const i of inputs) {
            expect(i.dataset.sxEvents).toBe('change');
            expect(i.dataset.sxId).toBeUndefined();
        }
    });

    it('slider create() builds a native range with min/max/step/value, events on the input', () => {
        const el = registry.render(
            node('slider', { label: 'Volume', min: 0, max: 10, step: 2, value: 6, events: ['change'] }, 'sl1'),
            { registry },
        );
        expect(el.className).toContain('sx-slider');
        expect(el.dataset.sxId).toBe('sl1');
        const input = el.querySelector('input[type=range]');
        expect(input.min).toBe('0');
        expect(input.max).toBe('10');
        expect(input.step).toBe('2');
        expect(input.value).toBe('6');
        expect(input.dataset.sxEvents).toBe('change');
        expect(input.dataset.sxId).toBeUndefined(); // id on the wrapper
    });

    it('slider update() syncs value (as a string) when unfocused', () => {
        const el = registry.render(
            node('slider', { label: 'Volume', min: 0, max: 10, step: 1, value: 3, events: ['change'] }, 'sl1'),
            { registry },
        );
        registry.get('slider').update(
            el,
            node('slider', { label: 'Volume', min: 0, max: 10, step: 1, value: 8, events: ['change'] }, 'sl1'),
            { registry },
        );
        expect(el.querySelector('input[type=range]').value).toBe('8');
    });

    const tabsNode = (id, active, tabs, panelLabels) =>
        node('tabs', { tabs, active, events: ['change'] }, id, panelLabels.map((t, i) => node('label', { text: t }, `p${i}`)));

    // tabs update() calls ctx.reconcileChildren -- stub it (mirrors the grid/groupbox tests already in this file).
    const reconcileCtx = { registry, reconcileChildren: (host, kids, c) => host.replaceChildren(...kids.map((k) => registry.render(k, c))) };

    it('tabs create() builds a radio strip (RadioGroup contract) + a body host with only the active panel shown', () => {
        const el = registry.render(tabsNode('t1', 'advanced', { general: 'General', advanced: 'Advanced' }, ['gen', 'adv']), { registry });
        expect(el.className).toContain('sx-tabs');
        expect(el.dataset.sxId).toBe('t1');
        const radios = el.querySelectorAll('.sx-tabs-strip input[type=radio]');
        expect(radios.length).toBe(2);
        expect(new Set([...radios].map((r) => r.name)).size).toBe(1);
        for (const r of radios) { expect(r.dataset.sxId).toBeUndefined(); expect(r.dataset.sxEvents).toBe('change'); }
        expect([...radios].find((r) => r.value === 'advanced').checked).toBe(true);
        const panels = el.querySelectorAll('.sx-tabs-body > *');
        expect(panels.length).toBe(2);
        expect(panels[0].hasAttribute('hidden')).toBe(true);
        expect(panels[1].hasAttribute('hidden')).toBe(false);
    });

    it('tabs unknown/empty active shows the first panel', () => {
        const el = registry.render(tabsNode('t1', '', { a: 'A', b: 'B' }, ['pa', 'pb']), { registry });
        const panels = el.querySelectorAll('.sx-tabs-body > *');
        expect(panels[0].hasAttribute('hidden')).toBe(false);
        expect(panels[1].hasAttribute('hidden')).toBe(true);
    });

    it('tabs update() switches the shown panel on a changed active', () => {
        const el = registry.render(tabsNode('t1', 'a', { a: 'A', b: 'B' }, ['pa', 'pb']), { registry });
        registry.get('tabs').update(el, tabsNode('t1', 'b', { a: 'A', b: 'B' }, ['pa', 'pb']), reconcileCtx);
        const panels = el.querySelectorAll('.sx-tabs-body > *');
        expect(panels[0].hasAttribute('hidden')).toBe(true);
        expect(panels[1].hasAttribute('hidden')).toBe(false);
    });

    it('tabs update() with a CHANGED tab SET rebuilds the strip AND still shows only the active panel (coordination rule)', () => {
        const el = registry.render(tabsNode('t1', 'a', { a: 'A', b: 'B' }, ['pa', 'pb']), { registry });
        registry.get('tabs').update(el, tabsNode('t1', 'y', { x: 'X', y: 'Y', z: 'Z' }, ['px', 'py', 'pz']), reconcileCtx);
        const radios = el.querySelectorAll('.sx-tabs-strip input[type=radio]');
        expect(radios.length).toBe(3);
        expect([...radios].find((r) => r.value === 'y').checked).toBe(true);
        const panels = el.querySelectorAll('.sx-tabs-body > *');
        expect(panels.length).toBe(3);
        expect(panels[0].hasAttribute('hidden')).toBe(true);
        expect(panels[1].hasAttribute('hidden')).toBe(false);
        expect(panels[2].hasAttribute('hidden')).toBe(true);
    });

    it('tabs optimistic listener: checking a tab radio shows its panel immediately (no server frame)', () => {
        const el = registry.render(tabsNode('t1', 'a', { a: 'A', b: 'B' }, ['pa', 'pb']), { registry });
        const bRadio = [...el.querySelectorAll('.sx-tabs-strip input[type=radio]')].find((r) => r.value === 'b');
        bRadio.checked = true;
        bRadio.dispatchEvent(new Event('change', { bubbles: true }));
        const panels = el.querySelectorAll('.sx-tabs-body > *');
        expect(panels[1].hasAttribute('hidden')).toBe(false);
        expect(panels[0].hasAttribute('hidden')).toBe(true);
    });

    it('toolbar create() is a role=toolbar strip rendering its children', () => {
        const el = registry.render(node('toolbar', {}, 'tb1', [node('button', { label: 'Save' }, 'b1'), node('button', { label: 'Del' }, 'b2')]), { registry });
        expect(el.className).toContain('sx-toolbar');
        expect(el.getAttribute('role')).toBe('toolbar');
        expect(el.dataset.sxId).toBe('tb1');
        expect(el.querySelectorAll('.sx-button').length).toBe(2);
    });

    it('toolbar update() reconciles children in place', () => {
        const ctx = { registry, reconcileChildren: (host, kids, c) => host.replaceChildren(...kids.map((k) => registry.render(k, c))) };
        const el = registry.render(node('toolbar', {}, 'tb1', [node('button', { label: 'A' }, 'b1')]), ctx);
        registry.get('toolbar').update(el, node('toolbar', {}, 'tb1', [node('button', { label: 'A' }, 'b1'), node('button', { label: 'B' }, 'b2')]), ctx);
        expect(el.querySelectorAll('.sx-button').length).toBe(2);
    });
});

describe('dialog', () => {
    const emit = vi.fn();
    const ctx = {
        registry,
        emit,
        reconcileChildren: (host, kids, c) => host.replaceChildren(...kids.map((k) => registry.render(k, c))),
    };

    const node = (props, children = []) => ({
        type: 'dialog', id: 'dlg', props: { open: false, title: '', dismissible: true, events: ['close'], ...props }, children,
    });

    // Dialogs attach a DOCUMENT-level Escape listener while open; every open dialog a test
    // creates MUST be driven closed afterwards or its listener leaks into the next test and
    // corrupts emit counts. mount() creates + appends + tracks; afterEach closes them all.
    const roots = [];
    const mount = (n) => {
        const el = dialogRenderer.create(n, ctx);
        document.body.appendChild(el);
        roots.push(el);
        return el;
    };
    beforeEach(() => { emit.mockClear(); });
    afterEach(() => {
        for (const el of roots.splice(0)) {
            if (el.querySelector('.sx-dialog-backdrop')) dialogRenderer.update(el, node({ open: false }), ctx);
            el.remove();
        }
    });
    const escape = () => document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));

    it('renders a layout-neutral root but no overlay when closed', () => {
        const el = mount(node({ open: false }));
        expect(el.className).toBe('sx-dialog');
        expect(el.querySelector('.sx-dialog-backdrop')).toBeNull();
    });

    it('mounts a backdrop + titled panel + body when open', () => {
        const el = mount(node({ open: true, title: 'Settings' }, [{ type: 'label', id: 'l', props: { text: 'hi' }, children: [] }]));
        expect(el.querySelector('.sx-dialog-backdrop')).not.toBeNull();
        expect(el.querySelector('.sx-dialog-title').textContent).toBe('Settings');
        expect(el.querySelector('.sx-dialog-body').textContent).toContain('hi');
        expect(el.dataset.sxId).toBe('dlg');
    });

    it('emits close on the close button', () => {
        const el = mount(node({ open: true, title: 'T' }));
        el.querySelector('.sx-dialog-close').click();
        expect(emit).toHaveBeenCalledWith('dlg', 'close', undefined, null, null);
    });

    it('emits close on Escape and on a backdrop click when dismissible', () => {
        const el = mount(node({ open: true, title: 'T' }));
        escape();
        expect(emit).toHaveBeenCalledTimes(1);
        el.querySelector('.sx-dialog-backdrop').dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
        expect(emit).toHaveBeenCalledTimes(2);
    });

    it('emits close on Escape even when focus is OUTSIDE the panel (document-scoped, the boot-open fix)', () => {
        // The regression: a durable-open dialog boots with focus on <body> (create() ran
        // detached, so the trap's focus() no-oped) -- Escape must still work.
        mount(node({ open: true, title: 'T' }));
        document.body.focus?.();
        escape(); // dispatched on body, NOT inside the dialog
        expect(emit).toHaveBeenCalledTimes(1);
    });

    it('engages the focus trap after connect for a dialog created OPEN (boot-open)', async () => {
        const el = mount(node({ open: true, title: 'T' }, [
            { type: 'button', id: 'b', props: { label: 'OK', events: [] }, children: [] },
        ]));
        await Promise.resolve(); // the deferred microtask engage()
        expect(el.contains(document.activeElement)).toBe(true); // focus moved into the dialog
    });

    it('suppresses Escape/backdrop and drops the close button when not dismissible', () => {
        const el = mount(node({ open: true, title: 'T', dismissible: false }));
        expect(el.querySelector('.sx-dialog-close')).toBeNull();
        escape();
        el.querySelector('.sx-dialog-backdrop').dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
        expect(emit).not.toHaveBeenCalled();
    });

    it('applies a dismissible flip while open (close button + Escape follow the CURRENT prop)', () => {
        const el = mount(node({ open: true, title: 'T' })); // dismissible
        expect(el.querySelector('.sx-dialog-close')).not.toBeNull();
        dialogRenderer.update(el, node({ open: true, title: 'T', dismissible: false }), ctx); // flip mid-open
        expect(el.querySelector('.sx-dialog-close')).toBeNull(); // chrome rebuilt without the button
        escape();
        expect(emit).not.toHaveBeenCalled(); // Escape now inert
        dialogRenderer.update(el, node({ open: true, title: 'T', dismissible: true }), ctx); // flip back
        expect(el.querySelector('.sx-dialog-close')).not.toBeNull();
        escape();
        expect(emit).toHaveBeenCalledTimes(1);
    });

    it('pins the backdrop to the scroll offset and locks a scrollable .sx-content while open', () => {
        const content = document.createElement('div');
        content.className = 'sx-content';
        document.body.appendChild(content);
        content.scrollTop = 120; // a sized window's content, scrolled down
        const el = dialogRenderer.create(node({ open: false }), ctx);
        content.appendChild(el);
        roots.push(el);
        dialogRenderer.update(el, node({ open: true, title: 'T' }), ctx); // opens while connected + scrolled
        const backdrop = el.querySelector('.sx-dialog-backdrop');
        expect(backdrop.style.top).toBe('120px'); // pinned to the visible viewport, not content-top
        expect(backdrop.style.height).toBe('100%');
        expect(content.classList.contains('sx-dialog-scroll-lock')).toBe(true);
        dialogRenderer.update(el, node({ open: false }), ctx);
        expect(content.classList.contains('sx-dialog-scroll-lock')).toBe(false); // lock released
        content.remove();
    });

    it('mounts on open and tears down on close across update()', () => {
        const el = mount(node({ open: false }));
        dialogRenderer.update(el, node({ open: true, title: 'T' }), ctx);
        expect(el.querySelector('.sx-dialog-backdrop')).not.toBeNull();
        dialogRenderer.update(el, node({ open: false }), ctx);
        expect(el.querySelector('.sx-dialog-backdrop')).toBeNull();
        escape();
        expect(emit).not.toHaveBeenCalled(); // the document Escape listener was removed with the overlay
    });

    it('does not stack a second backdrop or restack listeners across an open->open update', () => {
        const el = mount(node({ open: true, title: 'T' }));
        dialogRenderer.update(el, node({ open: true, title: 'T2' }), ctx); // still open, title changed
        expect(el.querySelectorAll('.sx-dialog-backdrop')).toHaveLength(1);
        expect(el.querySelector('.sx-dialog-title').textContent).toBe('T2');
        // one Escape yields exactly one emit -- proves the open->open branch didn't re-attach listeners
        escape();
        expect(emit).toHaveBeenCalledTimes(1);
    });

    it('destroy() removes the document Escape listener (the window-close leak fix)', () => {
        const el = mount(node({ open: true, title: 'T' })); // mount() appends + tracks
        dialogRenderer.destroy(el);
        // after destroy, an Escape on document must NOT emit close (the listener is gone)
        document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        expect(emit).not.toHaveBeenCalled();
    });
});

describe('menu button', () => {
    const emit = vi.fn();
    const ctx = { registry, emit };
    beforeEach(() => { emit.mockClear(); document.body.replaceChildren(); });

    const node = (items, props = {}) => ({
        type: 'menu', id: 'mb', props: { label: 'Actions', items, events: ['select'], ...props }, children: [],
    });

    it('renders a button in-tree, no popup when closed', () => {
        const el = menuButtonRenderer.create(node([{ label: 'Save', value: 'save' }]), ctx);
        expect(el.classList.contains('sx-menu-button')).toBe(true);
        expect(el.textContent).toContain('Actions');
        expect(document.querySelector('.sx-menu')).toBeNull();
    });

    it('portals a popup with one row per item on click', () => {
        const el = menuButtonRenderer.create(node([
            { label: 'Save', value: 'save' }, { divider: true }, { label: 'Del', value: 'del', disabled: true },
        ]), ctx);
        document.body.appendChild(el);
        el.click();
        const popup = document.querySelector('.sx-menu');
        expect(popup).not.toBeNull();
        expect(popup.querySelectorAll('.sx-menu-item')).toHaveLength(2); // divider is not an item
        expect(popup.querySelector('.sx-menu-divider')).not.toBeNull();
    });

    it('emits select with the value + window/app captured from the trigger, and closes', () => {
        const surface = document.createElement('div');
        surface.dataset.windowId = 'w1'; surface.dataset.app = 'controls';
        const el = menuButtonRenderer.create(node([{ label: 'Save', value: 'save' }]), ctx);
        surface.appendChild(el); document.body.appendChild(surface);
        el.click();
        document.querySelector('.sx-menu .sx-menu-item').click();
        expect(emit).toHaveBeenCalledWith('mb', 'select', 'save', 'w1', 'controls');
        expect(document.querySelector('.sx-menu')).toBeNull();
    });

    it('a disabled item emits nothing', () => {
        const el = menuButtonRenderer.create(node([{ label: 'Del', value: 'del', disabled: true }]), ctx);
        document.body.appendChild(el);
        el.click();
        document.querySelector('.sx-menu .sx-menu-item').click();
        expect(emit).not.toHaveBeenCalled();
    });

    it('closes on outside mousedown', () => {
        const el = menuButtonRenderer.create(node([{ label: 'Save', value: 'save' }]), ctx);
        document.body.appendChild(el);
        el.click();
        expect(document.querySelector('.sx-menu')).not.toBeNull();
        document.body.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
        expect(document.querySelector('.sx-menu')).toBeNull();
    });

    it('serves the CURRENT items after a morph update changes them (stale-closure regression)', () => {
        // The bug: the click listener closed over the create-time node, so a server re-render
        // that changed props.items kept serving -- and emitting -- the OLD items forever.
        const el = menuButtonRenderer.create(node([{ label: 'Old', value: 'old' }]), ctx);
        document.body.appendChild(el);
        menuButtonRenderer.update(el, node([{ label: 'New', value: 'new' }]), ctx);
        el.click();
        const row = document.querySelector('.sx-menu .sx-menu-item');
        expect(row.textContent).toContain('New'); // the popup reflects the LATEST render
        row.click();
        expect(emit).toHaveBeenCalledWith('mb', 'select', 'new', null, null); // and emits its value
    });

    it('closes an orphaned popup when its anchor has left the DOM (window-close guard)', () => {
        // The reconciler/WM removes elements with a bare .remove() -- no teardown hook -- so a
        // window closing mid-open strands the popup. The next input must clear it eagerly.
        const el = menuButtonRenderer.create(node([{ label: 'Save', value: 'save' }]), ctx);
        document.body.appendChild(el);
        el.click();
        expect(document.querySelector('.sx-menu')).not.toBeNull();
        el.remove(); // the window (and the trigger with it) is gone
        document.body.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
        expect(document.querySelector('.sx-menu')).toBeNull();
    });

    it('Escape closes only the top layer: the popup consumes the press via stopPropagation', () => {
        const el = menuButtonRenderer.create(node([{ label: 'Save', value: 'save' }]), ctx);
        document.body.appendChild(el);
        el.click();
        const seenBelow = vi.fn();
        document.addEventListener('keydown', seenBelow); // stands in for a dialog's document listener
        document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        expect(document.querySelector('.sx-menu')).toBeNull(); // popup closed
        expect(seenBelow).not.toHaveBeenCalled(); // the press never reached the layer below
        document.removeEventListener('keydown', seenBelow);
    });

    it('destroy() closes an open portaled popup deterministically', () => {
        const el = menuButtonRenderer.create(node([{ label: 'Save', value: 'save' }]), ctx);
        document.body.appendChild(el);
        el.click();
        expect(document.querySelector('.sx-menu')).not.toBeNull();
        menuButtonRenderer.destroy(el);
        expect(document.querySelector('.sx-menu')).toBeNull();
    });
});

describe('menu bar', () => {
    const emit = vi.fn();
    const ctx = { registry, emit };
    beforeEach(() => { emit.mockClear(); document.body.replaceChildren(); });

    const menus = [
        { label: 'File', items: [{ label: 'New', value: 'file.new' }] },
        { label: 'Edit', items: [{ label: 'Copy', value: 'edit.copy' }] },
    ];
    const node = () => ({ type: 'menubar', id: 'bar', props: { menus, events: ['select'] }, children: [] });

    it('renders a label strip in-tree, no popup when closed', () => {
        const el = menuBarRenderer.create(node(), ctx);
        expect(el.classList.contains('sx-menubar')).toBe(true);
        expect(el.querySelectorAll('.sx-menubar-label')).toHaveLength(2);
        expect(document.querySelector('.sx-menu')).toBeNull();
    });

    it('opens a menu on label click and emits select+value on a pick', () => {
        const surface = document.createElement('div');
        surface.dataset.windowId = 'w1'; surface.dataset.app = 'controls';
        const el = menuBarRenderer.create(node(), ctx);
        surface.appendChild(el); document.body.appendChild(surface);
        el.querySelectorAll('.sx-menubar-label')[0].click(); // File
        document.querySelector('.sx-menu .sx-menu-item').click(); // New
        expect(emit).toHaveBeenCalledWith('bar', 'select', 'file.new', 'w1', 'controls');
    });

    it('switches the open menu when a sibling label is hovered', () => {
        const el = menuBarRenderer.create(node(), ctx);
        document.body.appendChild(el);
        const [file, edit] = el.querySelectorAll('.sx-menubar-label');
        file.click(); // File open
        expect(document.querySelector('.sx-menu .sx-menu-item').textContent).toContain('New');
        edit.dispatchEvent(new MouseEvent('mouseenter', { bubbles: true }));
        expect(document.querySelector('.sx-menu .sx-menu-item').textContent).toContain('Copy'); // switched
    });

    it('destroy() closes an open portaled popup deterministically', () => {
        const el = menuBarRenderer.create(node(), ctx);
        document.body.appendChild(el);
        el.querySelector('.sx-menubar-label').click(); // open the first menu
        expect(document.querySelector('.sx-menu')).not.toBeNull();
        menuBarRenderer.destroy(el);
        expect(document.querySelector('.sx-menu')).toBeNull();
    });
});

describe('tooltip', () => {
    const ctx = {
        registry,
        reconcileChildren: (host, kids, c) => host.replaceChildren(...kids.map((k) => registry.render(k, c))),
    };
    const node = (props, children = [{ type: 'label', id: 'l', props: { text: 'hover me' }, children: [] }]) => ({
        type: 'tooltip', id: 'tt', props: { text: 'Saves your work', side: 'top', ...props }, children,
    });

    it('wraps the child in a content host and renders a bubble with the hint text', () => {
        const el = tooltipRenderer.create(node(), ctx);
        expect(el.classList.contains('sx-tooltip')).toBe(true);
        expect(el.dataset.sxId).toBe('tt');
        expect(el.dataset.side).toBe('top');
        const content = el.querySelector('.sx-tooltip-content');
        expect(content).not.toBeNull();
        expect(content.textContent).toContain('hover me');
        const bubble = el.querySelector('.sx-tooltip-bubble');
        expect(bubble.getAttribute('role')).toBe('tooltip');
        expect(bubble.textContent).toBe('Saves your work');
    });

    it('reflects the side prop on the wrapper', () => {
        const el = tooltipRenderer.create(node({ side: 'right' }), ctx);
        expect(el.dataset.side).toBe('right');
    });

    it('re-syncs the hint text + side and reconciles the child on update', () => {
        const el = tooltipRenderer.create(node(), ctx);
        tooltipRenderer.update(el, node({ text: 'New hint', side: 'bottom' }), ctx);
        expect(el.dataset.side).toBe('bottom');
        expect(el.querySelector('.sx-tooltip-bubble').textContent).toBe('New hint');
        expect(el.querySelector('.sx-tooltip-content').textContent).toContain('hover me');
    });

    it('never puts the bubble in the reconciled child slot', () => {
        const el = tooltipRenderer.create(node(), ctx);
        tooltipRenderer.update(el, node({}, [{ type: 'label', id: 'l2', props: { text: 'changed' }, children: [] }]), ctx);
        expect(el.querySelector('.sx-tooltip-bubble')).not.toBeNull();
        expect(el.querySelector('.sx-tooltip-content').textContent).toContain('changed');
    });
});
