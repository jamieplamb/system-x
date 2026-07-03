import { unknownPlaceholder } from './widgets/unknown.js';

// Map-backed so arbitrary (possibly third-party) type strings cannot collide with
// Object.prototype keys. A "renderer" is { create(node, ctx), update(el, node, ctx) }.
export class RendererRegistry {
    constructor() {
        this.renderers = new Map();
    }

    register(type, renderer) {
        if (typeof renderer?.create !== 'function' || typeof renderer?.update !== 'function') {
            throw new Error(`system-x: renderer for "${type}" must have create() and update()`);
        }
        this.renderers.set(type, renderer);
        return this;
    }

    has(type) {
        return this.renderers.has(type);
    }

    get(type) {
        return this.renderers.get(type);
    }

    types() {
        return [...this.renderers.keys()];
    }

    // Build a fresh element for a node. ctx carries { registry, strict,
    // reconcileChildren }. CENTRAL CONTRACT: we stamp el.dataset.sxType AND
    // el.dataset.sxKey here so the morph's sameSlot() type key and the keyed
    // matcher's reconciliation key are guaranteed present, no matter what a
    // (possibly third-party) renderer's create() did or forgot to do.
    render(node, ctx = {}) {
        const renderer = this.renderers.get(node.type);
        if (!renderer) {
            if (ctx.strict) {
                throw new Error(`system-x: unknown widget type "${node.type}"`);
            }
            console.warn(`system-x: no renderer registered for "${node.type}" -- rendering placeholder`);
            return unknownPlaceholder(node);
        }
        const el = renderer.create(node, ctx);
        el.dataset.sxType = node.type; // non-negotiable morph key, stamped centrally
        // The reconciliation key is ALSO stamped centrally: any element built
        // through the registry carries its key when the node has one, so a keyed
        // row that changes TYPE (built by the NEW type's create(), e.g.
        // label.create()) still carries data-sx-key. This mirrors data-sx-type:
        // the keyed morph must not depend on each renderer remembering to stamp it.
        // An EMPTY-STRING key is NOT a real key (the reconciler treats '' the same
        // as missing via normaliseKey()), so we do not stamp data-sx-key for it --
        // keeping the DOM consistent with the matcher, which never matches on ''.
        if (node.props?.key !== undefined && String(node.props.key) !== '') {
            el.dataset.sxKey = String(node.props.key);
        }
        return el;
    }
}

// The shared singleton: core widgets register into THIS at import time, and a
// pro pack registers into the same instance via window.SystemX.renderers BEFORE
// boot()'s first render (D5 registration-timing rule).
export const registry = new RendererRegistry();

if (typeof window !== 'undefined') {
    window.SystemX = window.SystemX ?? {};
    window.SystemX.renderers = registry;
}
