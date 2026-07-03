// A raised horizontal strip -- a plain container (like Stack). Children reconcile
// positionally; every child IS a toolbar item (no body-host needed). No events of its
// own -- each child (Button, etc.) round-trips its own click through the existing seam.
export const toolbarRenderer = {
    create(node, ctx) {
        const el = document.createElement('div');
        el.className = 'sx-toolbar';
        el.setAttribute('role', 'toolbar');
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
