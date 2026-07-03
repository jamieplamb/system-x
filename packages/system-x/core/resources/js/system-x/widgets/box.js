// Layout container: a horizontal ROW of children rendered through the registry. Children are
// structurally fixed (no props.key), so they reconcile positionally -- the same contract as
// stack.js. update() delegates to ctx.reconcileChildren so a broken ctx thread fails loudly.
export const boxRenderer = {
    create(node, ctx) {
        const el = document.createElement('div');
        el.className = 'sx-box';
        el.dataset.sxId = node.id ?? '';
        for (const child of node.children) {
            el.appendChild(ctx.registry.render(child, ctx));
        }
        return el;
    },

    update(el, node, ctx) {
        ctx.reconcileChildren(el, node.children, ctx);
    },
};
