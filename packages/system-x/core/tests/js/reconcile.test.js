import { describe, it, expect, beforeEach, vi, afterEach } from 'vitest';
import { registry } from '../../resources/js/system-x/renderer-registry.js';
import '../../resources/js/system-x/renderers.js';      // core widgets
import { reconcile, reconcileChildren, destroyTree } from '../../resources/js/system-x/reconcile.js';

const n = (type, props = {}, id = null, children = []) => ({ type, id, props, children });
const ctx = () => ({ registry, onEvent: () => {}, strict: false });

// A list of keyed items, used across the reorder/insert/delete tests.
const list = (items) =>
    n('list', {}, 'mylist', items.map((it) =>
        n('listitem', { key: it.key, text: it.text }, `li-${it.key}`, [])));

// ----------------------------------------------------------------------------
// Task 4 deliverable: cases that only need the morph + the already-registered
// window/label/button renderers. These go GREEN now.
// ----------------------------------------------------------------------------
describe('reconcile', () => {
    let surface;
    beforeEach(() => {
        surface = document.createElement('div');
        document.body.replaceChildren(surface);
    });

    it('a text-only change patches in place and keeps the SAME DOM node', () => {
        reconcile(surface, n('label', { text: 'a' }, 'l1'), ctx());
        const before = surface.querySelector('[data-sx-id="l1"]');
        reconcile(surface, n('label', { text: 'b' }, 'l1'), ctx());
        const after = surface.querySelector('[data-sx-id="l1"]');
        expect(after).toBe(before);          // node identity preserved
        expect(after.textContent).toBe('b'); // text patched
    });

    it('a type change at a slot REPLACES the element instead of morphing', () => {
        reconcile(surface, n('window', { title: 'T', width: 100, height: 100 }, 'w', [
            n('label', { text: 'x' }, 's1'),
        ]), ctx());
        const before = surface.querySelector('[data-sx-id="s1"]');
        expect(before.className).toBe('sx-label');

        reconcile(surface, n('window', { title: 'T', width: 100, height: 100 }, 'w', [
            n('button', { label: 'x', events: ['click'] }, 's1'),
        ]), ctx());
        const after = surface.querySelector('[data-sx-id="s1"]');
        expect(after).not.toBe(before);
        expect(after.className).toBe('sx-button');
    });

    it('window content children match POSITIONALLY (structurally-fixed container)', () => {
        // Two labels in window content; change only the SECOND. The first is matched
        // by position and patched in place (same node), proving positional matching
        // for a container that carries no key expectation.
        reconcile(surface, n('window', { title: 'T', width: 100, height: 100 }, 'w', [
            n('label', { text: 'a' }, 's1'),
            n('label', { text: 'b' }, 's2'),
        ]), ctx());
        const first = surface.querySelector('[data-sx-id="s1"]');
        reconcile(surface, n('window', { title: 'T', width: 100, height: 100 }, 'w', [
            n('label', { text: 'a' }, 's1'),
            n('label', { text: 'CHANGED' }, 's2'),
        ]), ctx());
        expect(surface.querySelector('[data-sx-id="s1"]')).toBe(first); // untouched, same node
        expect(surface.querySelector('[data-sx-id="s2"]').textContent).toBe('CHANGED');
    });

    it('a trailing positional child the new tree dropped is removed', () => {
        reconcile(surface, n('window', { title: 'T', width: 100, height: 100 }, 'w', [
            n('label', { text: 'a' }, 's1'),
            n('label', { text: 'b' }, 's2'),
        ]), ctx());
        reconcile(surface, n('window', { title: 'T', width: 100, height: 100 }, 'w', [
            n('label', { text: 'a' }, 's1'),
        ]), ctx());
        expect(surface.querySelector('[data-sx-id="s2"]')).toBeNull();
        expect(surface.querySelectorAll('.sx-content .sx-label').length).toBe(1);
    });
});

// ----------------------------------------------------------------------------
// Reconciler-internal guarantees that do NOT depend on the Task 5 widgets. These
// nail down the carried-forward review notes: the registry's update() path is
// genuinely exercised by the morph, a malformed ctx fails LOUDLY rather than
// silently no-op'ing, the keyed validation shouts, and a keyed unknown row is
// tracked (no orphan leak). All GREEN now.
// ----------------------------------------------------------------------------
describe('reconcile -- ctx threading + keyed validation (Task 4 guarantees)', () => {
    let surface;
    beforeEach(() => {
        surface = document.createElement('div');
        document.body.replaceChildren(surface);
    });

    it('a matched slot drives the renderer.update() seam, not a recreate', () => {
        // Spy on label.update to prove the morph calls get(type).update(el,node,ctx)
        // on a matched node rather than rebuilding it. (Task 2 never exercised this.)
        const labelRenderer = registry.get('label');
        const realUpdate = labelRenderer.update;
        let calledWith = null;
        labelRenderer.update = (el, node, c) => {
            calledWith = { el, node, c };
            return realUpdate(el, node, c);
        };
        try {
            reconcile(surface, n('label', { text: 'a' }, 'l1'), ctx());
            const before = surface.querySelector('[data-sx-id="l1"]');
            reconcile(surface, n('label', { text: 'b' }, 'l1'), ctx());
            expect(calledWith).not.toBeNull();
            expect(calledWith.el).toBe(before);       // patched IN PLACE
            expect(calledWith.node.props.text).toBe('b');
            expect(typeof calledWith.c.reconcileChildren).toBe('function'); // ctx threaded
        } finally {
            labelRenderer.update = realUpdate;
        }
    });

    it('reconcileChildren throws LOUDLY when ctx lost its reconcileChildren thread', async () => {
        const { reconcileChildren } = await import('../../resources/js/system-x/reconcile.js');
        const container = document.createElement('div');
        container.dataset.sxType = 'window';
        // A ctx WITHOUT reconcileChildren -- simulates a renderer.update() that did
        // not thread ctx through. Must throw, never silently skip the child morph.
        expect(() => reconcileChildren(container, [], { registry })).toThrow(/reconcileChildren thread/);
    });

    it('an ALL-unkeyed keyed container logs a loud error (container expects keys)', async () => {
        // EVERY child lacks props.key, so anyKeyed === false. The error must STILL
        // fire: a `list` CONTAINER expects keys (read off data-sx-type), so it takes
        // the keyed path regardless and shouts. Driven through reconcileChildren so
        // this needs no Task 5 list renderer -- the validation lives in the morph.
        const { reconcileChildren } = await import('../../resources/js/system-x/reconcile.js');
        const el = document.createElement('div');
        el.dataset.sxType = 'list';
        el.dataset.sxId = 'l';
        const c = { registry, onEvent: () => {}, strict: false, reconcileChildren };
        const errs = [];
        const orig = console.error;
        console.error = (...a) => errs.push(a.join(' '));
        try {
            reconcileChildren(el, [n('listitem', { text: 'no key' }, 'li-x', [])], c);
        } finally {
            console.error = orig;
        }
        expect(errs.some((e) => /missing props\.key/i.test(e))).toBe(true);
    });

    it('a MIXED keyed/unkeyed sibling set errors loudly (all-or-nothing, D6)', async () => {
        const { reconcileChildren } = await import('../../resources/js/system-x/reconcile.js');
        const el = document.createElement('div');
        el.dataset.sxType = 'list';
        el.dataset.sxId = 'l';
        const c = { registry, onEvent: () => {}, strict: false, reconcileChildren };
        const errs = [];
        const orig = console.error;
        console.error = (...a) => errs.push(a.join(' '));
        try {
            reconcileChildren(el, [
                n('listitem', { key: '1', text: 'keyed' }, 'li-1', []),
                n('listitem', { text: 'unkeyed' }, 'li-2', []), // no key in a keyed set
            ], c);
        } finally {
            console.error = orig;
        }
        expect(errs.some((e) => /missing props\.key/i.test(e))).toBe(true);
    });

    it('an EMPTY-STRING key is loud + positional, never silently accepted as a real key', async () => {
        // props.key === '' is "defined" but useless: it could dodge the missing-key
        // error, never match an existing row, and so silently RECREATE the row every
        // frame -- the exact silent state-loss this module exists to prevent. It must
        // be treated as a MISSING key: shout, and fall back to positional. We also
        // prove a real-keyed sibling is NOT disturbed by the empty-keyed one.
        const { reconcileChildren } = await import('../../resources/js/system-x/reconcile.js');
        const container = document.createElement('div');
        container.dataset.sxType = 'list';
        container.dataset.sxId = 'mylist';
        const c = { registry, onEvent: () => {}, strict: false, reconcileChildren };

        const errs = [];
        const orig = console.error;
        console.error = (...a) => errs.push(a.join(' '));
        try {
            // Frame 1: a real-keyed row + an empty-keyed row.
            reconcileChildren(container, [
                n('label', { key: 'real', text: 'keeps me' }, 'lr', []),
                n('label', { key: '', text: 'empty key' }, 'le', []),
            ], c);
            const realRow = container.querySelector('[data-sx-id="lr"]');
            const emptyRow1 = container.querySelector('[data-sx-id="le"]');
            // The empty-keyed row never gets a data-sx-key -- it is not a real key.
            expect(emptyRow1.dataset.sxKey).toBeUndefined();
            // The real-keyed row carries its key (so it can be matched next frame).
            expect(realRow.dataset.sxKey).toBe('real');
            // The empty/missing key was shouted about, naming it as empty or missing.
            expect(errs.some((e) => /empty or missing props\.key/i.test(e))).toBe(true);

            errs.length = 0;

            // Frame 2: same tree. The empty-keyed row is RECREATED (positional fallback,
            // cannot be matched), while the real-keyed sibling is the SAME node (matched).
            reconcileChildren(container, [
                n('label', { key: 'real', text: 'keeps me' }, 'lr', []),
                n('label', { key: '', text: 'empty key' }, 'le', []),
            ], c);
            expect(container.querySelector('[data-sx-id="lr"]')).toBe(realRow);       // untouched
            expect(container.querySelector('[data-sx-id="le"]')).not.toBe(emptyRow1); // recreated
            // Still loud on every frame the empty key persists.
            expect(errs.some((e) => /empty or missing props\.key/i.test(e))).toBe(true);
            // No leak: exactly the two rows.
            expect(container.children.length).toBe(2);
        } finally {
            console.error = orig;
        }
    });

    it('a keyed row of an UNKNOWN type carries data-sx-key so the morph tracks it (no orphan leak)', async () => {
        // An unregistered type inside a keyed list still gets a placeholder that
        // MUST carry data-sx-key (carried-forward review note 2). Without the key the
        // keyed matcher cannot find last frame's placeholder, so it renders a fresh
        // one AND never removes the old -- leaking a second orphaned .sx-unknown each
        // frame. With the key stamped, the matcher tracks it: the unknown-at-matched-
        // slot replace (D5) swaps it in place and exactly ONE placeholder survives.
        // Driven through reconcileChildren directly so this needs no Task 5 list renderer.
        const { reconcileChildren } = await import('../../resources/js/system-x/reconcile.js');
        const container = document.createElement('div');
        container.dataset.sxType = 'list';
        container.dataset.sxId = 'mylist';
        const c = { registry, onEvent: () => {}, strict: false, reconcileChildren };

        const warn = [];
        const orig = console.warn;
        console.warn = (...a) => warn.push(a.join(' '));
        try {
            reconcileChildren(container, [n('vendor.gauge', { key: '7' }, 'g7', [])], c);
            const ph = container.querySelector('.sx-unknown');
            expect(ph).not.toBeNull();
            expect(ph.dataset.sxKey).toBe('7'); // stamped, so the matcher can track it

            reconcileChildren(container, [n('vendor.gauge', { key: '7' }, 'g7', [])], c);
            // The key let the matcher find and replace the stale placeholder in place:
            // no orphan leak -- exactly one .sx-unknown, one container child.
            expect(container.querySelectorAll('.sx-unknown').length).toBe(1);
            expect(container.children.length).toBe(1);
            expect(container.querySelector('.sx-unknown').dataset.sxKey).toBe('7');
        } finally {
            console.warn = orig;
        }
    });
});

// ----------------------------------------------------------------------------
// Task 5 dependent: these need the stack / textfield / list / listitem /
// checkbox / raw renderers (Task 5). The morph algorithm here ALREADY supports
// them (validated against proxy renderers during Task 4), but they cannot go
// green until those widgets register. Per the plan (Task 4 Step 4 / Task 5
// Step 6) they are SKIPPED here and Task 5 flips `.skip` -> live to complete the
// red->green for the widget behaviours. Leaving them un-skipped would make the
// suite permanently red between Task 4 and Task 5.
// ----------------------------------------------------------------------------
describe('reconcile -- DOM-state preservation across Task 5 widgets', () => {
    let surface;
    beforeEach(() => {
        surface = document.createElement('div');
        document.body.replaceChildren(surface);
    });

    it('focus survives a sibling morph (matched node is never recreated)', () => {
        reconcile(surface, n('stack', {}, 'st', [
            n('textfield', { value: '', events: [] }, 'f1'),
            n('label', { text: 'a' }, 'lbl'),
        ]), ctx());
        const input = surface.querySelector('[data-sx-id="f1"] input');
        input.focus();
        expect(document.activeElement).toBe(input);

        // Change ONLY the sibling label.
        reconcile(surface, n('stack', {}, 'st', [
            n('textfield', { value: '', events: [] }, 'f1'),
            n('label', { text: 'CHANGED' }, 'lbl'),
        ]), ctx());

        expect(document.activeElement).toBe(input); // still focused, never detached
        expect(surface.querySelector('[data-sx-id="lbl"]').textContent).toBe('CHANGED');
    });

    it('caret/selection survive a morph of a focused field', () => {
        reconcile(surface, n('stack', {}, 'st', [
            n('textfield', { value: 'hello', events: [] }, 'f1'),
        ]), ctx());
        const input = surface.querySelector('[data-sx-id="f1"] input');
        input.focus();
        input.setSelectionRange(2, 4);

        // An unrelated frame arrives; the field is focused so value/selection must hold.
        reconcile(surface, n('stack', {}, 'st', [
            n('textfield', { value: 'hello', events: [] }, 'f1'),
        ]), ctx());

        expect(input.selectionStart).toBe(2);
        expect(input.selectionEnd).toBe(4);
    });

    it('a focused field is NOT clobbered by an incoming server value (focused-input-wins)', () => {
        reconcile(surface, n('stack', {}, 'st', [
            n('textfield', { value: 'server', events: [] }, 'f1'),
        ]), ctx());
        const input = surface.querySelector('[data-sx-id="f1"] input');
        input.focus();
        input.value = 'user typing';          // local live value

        // Server re-broadcasts a stale committed value while the field is focused.
        reconcile(surface, n('stack', {}, 'st', [
            n('textfield', { value: 'server', events: [] }, 'f1'),
        ]), ctx());

        expect(input.value).toBe('user typing'); // not snapped back
    });

    it('an UNfocused field DOES take the incoming server value', () => {
        reconcile(surface, n('stack', {}, 'st', [
            n('textfield', { value: 'a', events: [] }, 'f1'),
        ]), ctx());
        const input = surface.querySelector('[data-sx-id="f1"] input');
        reconcile(surface, n('stack', {}, 'st', [
            n('textfield', { value: 'b', events: [] }, 'f1'),
        ]), ctx());
        expect(input.value).toBe('b');
    });

    it('keyed list reorder MOVES the existing nodes (no recreate)', () => {
        reconcile(surface, list([{ key: '1', text: 'one' }, { key: '2', text: 'two' }]), ctx());
        const first = surface.querySelector('[data-sx-id="li-1"]');
        const second = surface.querySelector('[data-sx-id="li-2"]');

        reconcile(surface, list([{ key: '2', text: 'two' }, { key: '1', text: 'one' }]), ctx());

        const rows = surface.querySelectorAll('.sx-listitem');
        expect(rows[0]).toBe(second); // same DOM nodes, reordered
        expect(rows[1]).toBe(first);
    });

    it('a keyed row that changes type is replaced and leaves NO orphan', () => {
        // Row key '1' is a listitem; on the next frame it becomes a label at the SAME key.
        reconcile(surface, n('list', {}, 'mylist', [
            n('listitem', { key: '1', text: 'one' }, 'li-1', []),
            n('listitem', { key: '2', text: 'two' }, 'li-2', []),
        ]), ctx());
        const oldRow1 = surface.querySelector('[data-sx-id="li-1"]');
        expect(oldRow1.className).toBe('sx-listitem');

        reconcile(surface, n('list', {}, 'mylist', [
            n('label', { key: '1', text: 'now a label' }, 'li-1', []),
            n('listitem', { key: '2', text: 'two' }, 'li-2', []),
        ]), ctx());

        // Exactly one element at key '1', and it is the new label -- the old listitem is gone.
        const atKey1 = surface.querySelectorAll('[data-sx-key="1"]');
        expect(atKey1.length).toBe(1);
        expect(atKey1[0].className).toBe('sx-label');
        expect(atKey1[0]).not.toBe(oldRow1);
        // No orphaned old listitem anywhere in the container.
        expect([...surface.querySelectorAll('.sx-listitem')].length).toBe(1); // only key '2' remains
        // Total children is exactly 2, not 3 (no leak).
        expect(surface.querySelector('.sx-list').children.length).toBe(2);
    });

    it('a half-typed row follows its props.key on reorder, not its index', () => {
        reconcile(surface, list([{ key: '1', text: 'one' }, { key: '2', text: 'two' }]), ctx());
        const row2 = surface.querySelector('[data-sx-id="li-2"]');
        row2.tabIndex = 0;
        row2.focus();

        reconcile(surface, list([{ key: '2', text: 'two' }, { key: '1', text: 'one' }]), ctx());

        // NODE IDENTITY, not document.activeElement: jsdom BLURS any element moved via
        // insertBefore (real browsers do NOT), so asserting activeElement here would
        // fail for a jsdom reason, not a bug. The morph's real guarantee is that the
        // SAME DOM node moved -- proving local state (caret, focus in a real browser)
        // would ride along. Real-browser focus survival is proven by Task 8's Dusk.
        expect(surface.querySelector('[data-sx-id="li-2"]')).toBe(row2); // same node, not recreated
        expect(surface.querySelectorAll('.sx-listitem')[0]).toBe(row2);  // moved to front
    });

    it('keyed list insert adds only the new row and keeps the rest', () => {
        reconcile(surface, list([{ key: '1', text: 'one' }]), ctx());
        const existing = surface.querySelector('[data-sx-id="li-1"]');
        reconcile(surface, list([{ key: '1', text: 'one' }, { key: '2', text: 'two' }]), ctx());
        expect(surface.querySelector('[data-sx-id="li-1"]')).toBe(existing);
        expect(surface.querySelectorAll('.sx-listitem').length).toBe(2);
    });

    it('keyed list delete removes only the gone row and keeps the rest', () => {
        reconcile(surface, list([{ key: '1', text: 'one' }, { key: '2', text: 'two' }]), ctx());
        const keep = surface.querySelector('[data-sx-id="li-2"]');
        reconcile(surface, list([{ key: '2', text: 'two' }]), ctx());
        expect(surface.querySelector('[data-sx-id="li-1"]')).toBeNull();
        expect(surface.querySelector('[data-sx-id="li-2"]')).toBe(keep);
        expect(surface.querySelectorAll('.sx-listitem').length).toBe(1);
    });

    it('scroll position survives a prop-only update of a scrollable list', () => {
        const many = Array.from({ length: 50 }, (_, i) => ({ key: String(i), text: `row ${i}` }));
        reconcile(surface, list(many), ctx());
        const listEl = surface.querySelector('.sx-list');
        // jsdom has no layout, so simulate a retained scrollTop and assert we never reset it.
        Object.defineProperty(listEl, 'scrollTop', { value: 120, writable: true, configurable: true });
        reconcile(surface, list(many.map((m) => ({ ...m, text: m.text + '!' }))), ctx());
        expect(surface.querySelector('.sx-list').scrollTop).toBe(120);
        expect(surface.querySelector('.sx-list')).toBe(listEl); // same container, never replaced
    });

    it('an ALL-unkeyed list logs a loud error and still renders (container expects keys)', () => {
        // EVERY child lacks props.key, so anyKeyed === false. The error must STILL
        // fire: the `list` CONTAINER expects keys (read off data-sx-type), so it
        // takes the keyed path regardless and shouts. It must NOT silently degrade
        // to positional and stay quiet -- that was the bug this fix closes.
        const warn = [];
        const orig = console.error;
        console.error = (...a) => warn.push(a.join(' '));
        try {
            reconcile(surface, n('list', {}, 'l', [
                n('listitem', { text: 'no key' }, 'li-x', []),
            ]), ctx());
        } finally {
            console.error = orig;
        }
        expect(warn.some((w) => /missing props.key/.test(w))).toBe(true);
        expect(surface.querySelectorAll('.sx-listitem').length).toBe(1); // still rendered
    });

    it('a MIXED keyed/unkeyed sibling set errors loudly (all-or-nothing, D6)', () => {
        const errs = [];
        const orig = console.error;
        console.error = (...a) => errs.push(a.join(' '));
        try {
            reconcile(surface, n('list', {}, 'l', [
                n('listitem', { key: '1', text: 'keyed' }, 'li-1', []),
                n('listitem', { text: 'unkeyed' }, 'li-2', []), // no key in a keyed set
            ]), ctx());
        } finally {
            console.error = orig;
        }
        expect(errs.some((e) => /missing props\.key/i.test(e))).toBe(true);
        // It still renders both rows -- the error is a dev signal, not a crash.
        expect(surface.querySelectorAll('.sx-listitem').length).toBe(2);
    });

    it('an unknown type at a MATCHED slot degrades to the sx-unknown placeholder', () => {
        // First render a known label at a slot, then send an unknown type at the SAME id.
        reconcile(surface, n('stack', {}, 'st', [n('label', { text: 'a' }, 'x')]), ctx());
        const warn = [];
        const orig = console.warn;
        console.warn = (...a) => warn.push(a.join(' '));
        try {
            reconcile(surface, n('stack', {}, 'st', [n('vendor.gauge', {}, 'x', [])]), ctx());
        } finally {
            console.warn = orig;
        }
        const ph = surface.querySelector('.sx-unknown');
        expect(ph).not.toBeNull();
        expect(ph.dataset.sxType).toBe('vendor.gauge');
        expect(warn.some((w) => /no renderer registered for "vendor.gauge"/.test(w))).toBe(true);
    });

    it('a focused checkbox is NOT clobbered by an incoming server checked (focused-input-wins)', () => {
        reconcile(surface, n('stack', {}, 'st', [
            n('checkbox', { label: 'Sub', checked: false, events: ['change'] }, 'c1'),
        ]), ctx());
        const box = surface.querySelector('[data-sx-id="c1"] input');
        box.focus();
        box.checked = true; // local live toggle, not yet committed

        // Server re-broadcasts the stale unchecked state while the box is focused.
        reconcile(surface, n('stack', {}, 'st', [
            n('checkbox', { label: 'Sub', checked: false, events: ['change'] }, 'c1'),
        ]), ctx());

        expect(box.checked).toBe(true); // not snapped back
    });

    it('an UNfocused checkbox DOES take the incoming server checked', () => {
        reconcile(surface, n('stack', {}, 'st', [
            n('checkbox', { label: 'Sub', checked: false, events: ['change'] }, 'c1'),
        ]), ctx());
        const box = surface.querySelector('[data-sx-id="c1"] input');
        reconcile(surface, n('stack', {}, 'st', [
            n('checkbox', { label: 'Sub', checked: true, events: ['change'] }, 'c1'),
        ]), ctx());
        expect(box.checked).toBe(true);
    });

    it('raw renders its developer HTML verbatim (no sanitising)', () => {
        reconcile(surface, n('stack', {}, 'st', [
            n('raw', { html: '<b id="rawb">hi</b>' }, 'r1'),
        ]), ctx());
        const raw = surface.querySelector('[data-sx-id="r1"]');
        expect(raw.className).toBe('sx-raw');
        expect(raw.querySelector('#rawb').textContent).toBe('hi');
    });

    it('changing props.html replaces the raw content wholesale', () => {
        reconcile(surface, n('stack', {}, 'st', [
            n('raw', { html: '<b>one</b>' }, 'r1'),
        ]), ctx());
        const raw = surface.querySelector('[data-sx-id="r1"]');
        reconcile(surface, n('stack', {}, 'st', [
            n('raw', { html: '<i>two</i>' }, 'r1'),
        ]), ctx());
        expect(raw).toBe(surface.querySelector('[data-sx-id="r1"]')); // same container
        expect(raw.querySelector('i').textContent).toBe('two');
        expect(raw.querySelector('b')).toBeNull();
    });

    it('an unchanged raw is left untouched -- dev-managed inner state survives', () => {
        reconcile(surface, n('stack', {}, 'st', [
            n('raw', { html: '<div id="host"></div>' }, 'r1'),
        ]), ctx());
        // Simulate a dev mounting their own widget INSIDE the raw node after render.
        const host = surface.querySelector('#host');
        host.dataset.mounted = 'yes';

        // An unrelated re-render arrives with the SAME html -- raw must NOT rewrite.
        reconcile(surface, n('stack', {}, 'st', [
            n('raw', { html: '<div id="host"></div>' }, 'r1'),
        ]), ctx());

        // Same host node, dev-managed state intact (no innerHTML rewrite).
        expect(surface.querySelector('#host')).toBe(host);
        expect(surface.querySelector('#host').dataset.mounted).toBe('yes');
    });
});

// ----------------------------------------------------------------------------
// PH Task 2: destroyTree wired into ALL FIVE reconcile.js detach sites. Each drop
// of a live element -- a root type-swap, a positional type-swap, a trailing
// positional drop, a keyed sweep, a matched-slot unknown-type replace -- must run
// the dropped subtree's destroy() hooks BEFORE the DOM detach, or a renderer's
// listeners/portals/traps leak. We register throwaway spy-destroy widget types
// into the REAL registry to prove each site fires the hook. destroy() with no
// renderer defining it is inert (the next task adds real ones); these spies stand
// in for that so the WIRING is proven now.
// ----------------------------------------------------------------------------
describe('reconcile -- destroyTree fires at every detach site (PH Task 2)', () => {
    let surface;
    const destroyA = vi.fn();
    const destroyB = vi.fn();

    // Two minimal spy-destroy renderers. They render a bare div carrying the stamped
    // data-sx-type/id (registry.render stamps those centrally) so the morph enumerates
    // them, and their destroy() is the spy the tests assert on.
    const spyRenderer = (cls, destroySpy) => ({
        create: (node) => {
            const el = document.createElement('div');
            el.className = cls;
            return el;
        },
        update: () => {},
        destroy: (el, ctxArg) => destroySpy(el, ctxArg),
    });

    beforeEach(() => {
        surface = document.createElement('div');
        document.body.replaceChildren(surface);
        destroyA.mockClear();
        destroyB.mockClear();
        registry.register('ph.a', spyRenderer('sx-ph-a', destroyA));
        registry.register('ph.b', spyRenderer('sx-ph-b', destroyB));
    });

    afterEach(() => {
        // Leave the shared registry as we found it -- other suites reuse it.
        registry.renderers.delete('ph.a');
        registry.renderers.delete('ph.b');
    });

    it('site 1 -- a root type-swap runs the dropped root type destroy (fullCtx)', () => {
        reconcile(surface, n('ph.a', {}, 'root'), ctx());
        reconcile(surface, n('ph.b', {}, 'root'), ctx()); // type A -> B at the root slot
        expect(destroyA).toHaveBeenCalledTimes(1);
        expect(destroyA.mock.calls[0][0].className).toBe('sx-ph-a');
    });

    it('site 2 -- a positional child type-swap runs the replaced child destroy (ctx)', () => {
        const c = () => ({ ...ctx(), reconcileChildren });
        const container = document.createElement('div');
        container.dataset.sxType = 'window'; // positional container (no key expectation)
        // child[0] is type A...
        reconcileChildren(container, [n('ph.a', {}, 'k')], c());
        // ...then becomes type B at the same position -> replaceChild.
        reconcileChildren(container, [n('ph.b', {}, 'k')], c());
        expect(destroyA).toHaveBeenCalledTimes(1);
    });

    it('site 3 -- a dropped trailing positional child runs its destroy (ctx)', () => {
        const c = () => ({ ...ctx(), reconcileChildren });
        const container = document.createElement('div');
        container.dataset.sxType = 'window';
        reconcileChildren(container, [n('ph.a', {}, 'a'), n('ph.b', {}, 'b')], c());
        reconcileChildren(container, [n('ph.a', {}, 'a')], c()); // drop the trailing B
        expect(destroyB).toHaveBeenCalledTimes(1);
        expect(destroyA).not.toHaveBeenCalled();
    });

    it('site 4 -- a dropped keyed row runs its destroy (ctx)', () => {
        const c = () => ({ ...ctx(), reconcileChildren });
        const container = document.createElement('div');
        container.dataset.sxType = 'list'; // keyed container
        reconcileChildren(container, [
            n('ph.a', { key: 'k1' }, 'a'),
            n('ph.b', { key: 'k2' }, 'b'),
        ], c());
        reconcileChildren(container, [
            n('ph.a', { key: 'k1' }, 'a'),
        ], c()); // drop keyed row k2
        expect(destroyB).toHaveBeenCalledTimes(1);
        expect(destroyA).not.toHaveBeenCalled();
    });

    it('site 5 -- a matched slot whose type lost its renderer runs the replaced subtree destroy (ctx)', () => {
        // A matched slot whose type LOST its renderer mid-session is REPLACED by the
        // sx-unknown placeholder (patch()'s no-renderer branch). destroyTree must walk the
        // dropped element FIRST. The dropped type itself (ph.a) has no renderer to resolve a
        // destroy from once deleted -- that's inherent -- so we nest a STILL-registered ph.b
        // INSIDE the ph.a element: the walk enumerates the descendant and fires ITS destroy,
        // proving destroyTree runs over the replaced subtree at this site.
        const c = () => ({ ...ctx(), reconcileChildren });
        const container = document.createElement('div');
        container.dataset.sxType = 'window';
        // Paint ph.a at a slot, with a nested ph.b child stamped so the walk finds it.
        reconcileChildren(container, [n('ph.a', {}, 'x')], c());
        const aEl = container.querySelector('.sx-ph-a');
        const bChild = registry.render(n('ph.b', {}, 'nested'), c()); // stamps data-sx-type=ph.b
        aEl.appendChild(bChild);

        // ph.a's renderer vanishes; the next frame keeps the SAME type at that slot, so it is a
        // MATCHED slot (sameSlot true) with no renderer -> patch() replaces it with sx-unknown.
        registry.renderers.delete('ph.a');
        const warn = console.warn;
        console.warn = () => {};
        try {
            reconcileChildren(container, [n('ph.a', {}, 'x')], c());
        } finally {
            console.warn = warn;
            registry.register('ph.a', spyRenderer('sx-ph-a', destroyA)); // re-register for afterEach symmetry
        }
        // The nested ph.b (still registered) had its destroy fired as the replaced subtree
        // was torn down -- destroyTree ran at the replace site.
        expect(destroyB).toHaveBeenCalledTimes(1);
        expect(destroyB.mock.calls[0][0]).toBe(bChild);
    });
});

// PH Task 3: the headline regression. A window-close removes a rendered OPEN dialog via the
// reconciler's destroyTree path -- NOT an open -> false flip -- so the dialog's update()-owned
// teardown never runs. destroyTree must invoke the dialog renderer's destroy() and release the
// DOCUMENT-level Escape listener, or that listener (and its stale emit) leaks past the window.
describe('reconcile -- destroyTree releases an open dialog\'s document Escape listener (PH Task 3)', () => {
    it('destroyTree(openDialogEl) fires destroy() so a later document Escape does not emit close', () => {
        const emit = vi.fn();
        const c = { registry, emit };
        // Render a full dialog node (open) through the real registry, then mount it.
        const dialogEl = registry.render(
            { type: 'dialog', id: 'dlg', props: { open: true, title: 'T', dismissible: true, events: ['close'] }, children: [] },
            c,
        );
        document.body.appendChild(dialogEl);

        // Sanity: while mounted+open, an Escape DOES emit close (the leaky listener is live).
        document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        expect(emit).toHaveBeenCalledTimes(1);
        emit.mockClear();

        // The window-close path: destroyTree runs the renderer's destroy() (no open -> false flip).
        destroyTree(dialogEl, { registry });
        dialogEl.remove();

        // The document listener is gone -- Escape is now inert, no residual emit.
        document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        expect(emit).not.toHaveBeenCalled();
    });
});
