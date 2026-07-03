import { describe, it, expect, vi, beforeEach } from 'vitest';
import { RendererRegistry } from '../../resources/js/system-x/renderer-registry.js';

// A deliberately MISBEHAVING renderer: its create() does NOT stamp data-sx-type.
// The registry must stamp it centrally so the morph contract holds regardless.
const stubRenderer = {
    create(node) {
        const el = document.createElement('div');
        el.dataset.sxId = node.id ?? '';
        el.dataset.stub = node.type;
        return el;
    },
    update(el, node) {
        el.dataset.updated = node.type;
    },
};

describe('RendererRegistry', () => {
    let registry;

    beforeEach(() => {
        registry = new RendererRegistry();
    });

    it('registers and retrieves a renderer by type', () => {
        registry.register('gauge', stubRenderer);
        expect(registry.has('gauge')).toBe(true);
        expect(registry.get('gauge')).toBe(stubRenderer);
    });

    it('render() dispatches create() for a known type', () => {
        registry.register('gauge', stubRenderer);
        const el = registry.render({ type: 'gauge', id: 'g1', props: {}, children: [] }, {});
        expect(el.dataset.stub).toBe('gauge');
        expect(el.dataset.sxId).toBe('g1');
    });

    it('render() centrally stamps data-sx-type even when create() forgets it', () => {
        registry.register('gauge', stubRenderer); // stub never sets data-sx-type
        const el = registry.render({ type: 'gauge', id: 'g1', props: {}, children: [] }, {});
        expect(el.dataset.sxType).toBe('gauge'); // stamped by the registry, not the renderer
    });

    it('render() centrally stamps data-sx-key when the node carries props.key', () => {
        registry.register('gauge', stubRenderer); // stub never sets data-sx-key
        const el = registry.render({ type: 'gauge', id: 'g1', props: { key: 7 }, children: [] }, {});
        expect(el.dataset.sxKey).toBe('7'); // stamped by the registry, regardless of the renderer's type
    });

    it('render() does NOT stamp data-sx-key when the node has no key', () => {
        registry.register('gauge', stubRenderer);
        const el = registry.render({ type: 'gauge', id: 'g1', props: {}, children: [] }, {});
        expect(el.dataset.sxKey).toBeUndefined();
    });

    it('render() returns a graceful placeholder + warns for an unknown type', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
        const el = registry.render({ type: 'nope', id: 'x', props: {}, children: [] }, {});
        expect(el.className).toBe('sx-unknown');
        expect(el.dataset.sxType).toBe('nope');
        expect(el.dataset.sxId).toBe('x');
        expect(warn).toHaveBeenCalled();
        warn.mockRestore();
    });

    it('render() throws on unknown type when strict', () => {
        expect(() => registry.render({ type: 'nope', props: {}, children: [] }, { strict: true }))
            .toThrow(/unknown widget type "nope"/);
    });
});
