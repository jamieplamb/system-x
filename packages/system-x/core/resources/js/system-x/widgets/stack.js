// Layout container: a column of children rendered through the registry. Children
// are structurally fixed (no props.key), so they reconcile positionally. update()
// asserts ctx.reconcileChildren so a broken ctx thread fails loudly (D-ctx).
export const stackRenderer = {
    create(node, ctx) {
        const el = document.createElement('div');
        el.className = 'sx-stack';
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
