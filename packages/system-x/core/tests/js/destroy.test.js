import { describe, it, expect, vi } from 'vitest';
import { destroyTree } from '../../resources/js/system-x/reconcile.js';

// a fake registry whose get(type) returns a renderer with a spy destroy (or nothing)
const reg = (spies) => ({ get: (t) => (spies[t] ? { destroy: spies[t] } : undefined) });
const elOfType = (type, ...kids) => {
    const el = document.createElement('div');
    el.dataset.sxType = type;
    kids.forEach((k) => el.appendChild(k));
    return el;
};

describe('destroyTree', () => {
    it('calls destroy() on the element and every descendant that has one', () => {
        const dSpy = vi.fn(); const cSpy = vi.fn();
        const child = elOfType('child');
        const root = elOfType('dialog', child);
        destroyTree(root, { registry: reg({ dialog: dSpy, child: cSpy }) });
        expect(dSpy).toHaveBeenCalledTimes(1);
        expect(cSpy).toHaveBeenCalledTimes(1);
        expect(dSpy).toHaveBeenCalledWith(root, expect.anything());
    });

    it('skips elements whose renderer has no destroy()', () => {
        const root = elOfType('plain', elOfType('alsoPlain'));
        expect(() => destroyTree(root, { registry: reg({}) })).not.toThrow();
    });

    it('does not remove the element itself (the caller owns removal)', () => {
        const root = elOfType('dialog');
        document.body.appendChild(root);
        destroyTree(root, { registry: reg({ dialog: vi.fn() }) });
        expect(root.isConnected).toBe(true); // destroy != remove
        root.remove();
    });

    it('is a no-op on a null or non-element argument', () => {
        expect(() => destroyTree(null, { registry: reg({}) })).not.toThrow();
        expect(() => destroyTree(document.createTextNode('x'), { registry: reg({}) })).not.toThrow();
    });
});
