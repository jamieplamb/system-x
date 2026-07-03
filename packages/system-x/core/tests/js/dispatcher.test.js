import { describe, it, expect, beforeEach, vi } from 'vitest';
import { installDispatcher } from '../../resources/js/system-x/dispatcher.js';

// The dispatcher is the SINGLE delegated listener installed once on the surface.
// It reads data-sx-events (the interaction-contract allowlist) off the event target
// to decide what round-trips, and echoes a field's live value up. Everything not in
// the allowlist stays LOCAL (no emit). These tests pin that contract in jsdom.

describe('installDispatcher', () => {
    let surface;
    let emit;

    beforeEach(() => {
        surface = document.createElement('div');
        document.body.replaceChildren(surface);
        emit = vi.fn();
        installDispatcher(surface, emit);
    });

    // --- click ------------------------------------------------------------------
    it('round-trips a click when the target allowlist includes click', () => {
        const btn = document.createElement('button');
        btn.dataset.sxId = 'clicker';
        btn.dataset.sxEvents = 'click';
        surface.appendChild(btn);

        btn.click();

        expect(emit).toHaveBeenCalledTimes(1);
        // No [data-window-id] ancestor here -> the trailing window id is null, which
        // exercises the resolver's session fallback end-to-end (the legacy path).
        expect(emit).toHaveBeenCalledWith('clicker', 'click', undefined, null, null);
    });

    it('round-trips a click carrying the host window id and app', () => {
        // The {app, window} split (Task 6): the surface carries BOTH data-window-id (the
        // bag key) and data-app (the App to run); both ride up as trailing emit args.
        surface.dataset.windowId = 'w-01';
        surface.dataset.app = 'notes';
        const btn = document.createElement('button');
        btn.dataset.sxId = 'clicker';
        btn.dataset.sxEvents = 'click';
        surface.appendChild(btn);

        btn.click();

        expect(emit).toHaveBeenCalledWith('clicker', 'click', undefined, 'w-01', 'notes');
    });

    it('does NOT round-trip a click when the allowlist omits click', () => {
        const el = document.createElement('div');
        el.dataset.sxId = 'inert';
        el.dataset.sxEvents = 'change'; // declares change, not click
        surface.appendChild(el);

        el.click();

        expect(emit).not.toHaveBeenCalled();
    });

    it('does NOT round-trip a click on an element with no data-sx-events at all', () => {
        const el = document.createElement('div');
        el.dataset.sxId = 'plain';
        surface.appendChild(el);

        el.click();

        expect(emit).not.toHaveBeenCalled();
    });

    it('reports the host WIDGET id when click allowlist is on an inner element, id on an ancestor wrapper', () => {
        // e.g. an icon-button: data-sx-events="click" lives on the inner element, but
        // the widget id lives on the labelled wrapper. The id MUST resolve via host()
        // (walk up to the nearest [data-sx-id]), the SAME path keydown/change use --
        // not be read off the allowlist element, which here has no data-sx-id.
        const wrap = document.createElement('div');
        wrap.dataset.sxId = 'icon-btn'; // id on the wrapper
        const inner = document.createElement('span');
        inner.dataset.sxEvents = 'click'; // allowlist on the inner element (no id)
        wrap.appendChild(inner);
        surface.appendChild(wrap);

        inner.click();

        expect(emit).toHaveBeenCalledWith('icon-btn', 'click', undefined, null, null);
    });

    it('walks up to the nearest ancestor carrying the allowlist (closest)', () => {
        const btn = document.createElement('button');
        btn.dataset.sxId = 'clicker';
        btn.dataset.sxEvents = 'click';
        const inner = document.createElement('span');
        btn.appendChild(inner);
        surface.appendChild(btn);

        inner.click(); // click lands on the inner span

        expect(emit).toHaveBeenCalledWith('clicker', 'click', undefined, null, null);
    });

    // --- submit (Enter) ---------------------------------------------------------
    it('round-trips submit on Enter carrying the live value, addressed by the host id', () => {
        const wrap = document.createElement('div');
        wrap.dataset.sxId = 'name-field'; // id lives on the wrapper
        const input = document.createElement('input');
        input.type = 'text';
        input.dataset.sxEvents = 'submit'; // allowlist lives on the input
        input.value = 'Jamie';
        wrap.appendChild(input);
        surface.appendChild(wrap);

        input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));

        expect(emit).toHaveBeenCalledWith('name-field', 'submit', 'Jamie', null, null);
    });

    it('keystrokes other than Enter stay local (no emit)', () => {
        const input = document.createElement('input');
        input.type = 'text';
        input.dataset.sxId = 'f';
        input.dataset.sxEvents = 'submit';
        surface.appendChild(input);

        input.dispatchEvent(new KeyboardEvent('keydown', { key: 'a', bubbles: true }));

        expect(emit).not.toHaveBeenCalled();
    });

    it('Enter in a field whose allowlist omits submit stays local', () => {
        const input = document.createElement('input');
        input.type = 'text';
        input.dataset.sxId = 'f';
        input.dataset.sxEvents = 'change'; // no submit
        surface.appendChild(input);

        input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));

        expect(emit).not.toHaveBeenCalled();
    });

    // --- change -----------------------------------------------------------------
    it('round-trips a text field change carrying its string value', () => {
        const wrap = document.createElement('div');
        wrap.dataset.sxId = 'name-field';
        const input = document.createElement('input');
        input.type = 'text';
        input.dataset.sxEvents = 'change';
        input.value = 'hello';
        wrap.appendChild(input);
        surface.appendChild(wrap);

        input.dispatchEvent(new Event('change', { bubbles: true }));

        expect(emit).toHaveBeenCalledWith('name-field', 'change', 'hello', null, null);
    });

    it('echoes a checkbox boolean checked (not its string value) on change', () => {
        const wrap = document.createElement('label');
        wrap.dataset.sxId = 'agree';
        const input = document.createElement('input');
        input.type = 'checkbox';
        input.dataset.sxEvents = 'change';
        input.checked = true;
        wrap.appendChild(input);
        surface.appendChild(wrap);

        input.dispatchEvent(new Event('change', { bubbles: true }));

        expect(emit).toHaveBeenCalledWith('agree', 'change', true, null, null);
    });

    it('does NOT round-trip a change when the allowlist omits change', () => {
        const input = document.createElement('input');
        input.type = 'text';
        input.dataset.sxId = 'f';
        input.dataset.sxEvents = 'submit'; // no change
        input.value = 'x';
        surface.appendChild(input);

        input.dispatchEvent(new Event('change', { bubbles: true }));

        expect(emit).not.toHaveBeenCalled();
    });
});
