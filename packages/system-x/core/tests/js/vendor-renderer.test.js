import { describe, it, expect, beforeEach, vi } from 'vitest';

// The vendor bundle is a side-effecting IIFE that self-registers into window.SystemX.renderers.
// We stub the registry to capture the renderer, then exercise its create/update contract.
describe('example-todo vendor renderer', () => {
    let captured;

    beforeEach(async () => {
        captured = {};
        window.SystemX = { renderers: { register: (type, r) => { captured[type] = r; } } };
        vi.resetModules();
        // Path: core/tests/js -> repo/packages/example-todo/dist
        await import('../../../../example-todo/dist/example-todo.js');
    });

    it('registers the example.gauge renderer', () => {
        expect(captured['example.gauge']).toBeTruthy();
        expect(typeof captured['example.gauge'].create).toBe('function');
        expect(typeof captured['example.gauge'].update).toBe('function');
    });

    it('create stamps the delegated-dispatch attributes and value', () => {
        const el = captured['example.gauge'].create({ id: 'g', props: { value: 3, events: ['click'] } });
        expect(el.dataset.sxId).toBe('g');
        expect(el.dataset.sxEvents).toBe('click');
        expect(el.textContent).toBe('3');
        expect(el.dataset.sxType).toBeUndefined();
    });

    it('update reflects a new value', () => {
        const el = captured['example.gauge'].create({ id: 'g', props: { value: 3, events: ['click'] } });
        captured['example.gauge'].update(el, { id: 'g', props: { value: 4, events: ['click'] } });
        expect(el.textContent).toBe('4');
    });
});
