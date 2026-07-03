// Layout container: a CSS GRID of children rendered through the registry. columns drives
// grid-template-columns: repeat(N, 1fr), set inline (dynamic value, not a class). Children
// reconcile positionally like stack.js/box.js; update() ALSO re-applies the column count so a
// changed columns value repaints (the one step beyond Stack's children-only update).
function applyColumns(el, node) {
    const columns = node.props?.columns ?? 1;
    el.style.gridTemplateColumns = `repeat(${columns}, 1fr)`;
}

export const gridRenderer = {
    create(node, ctx) {
        const el = document.createElement('div');
        el.className = 'sx-grid';
        el.dataset.sxId = node.id ?? '';
        applyColumns(el, node);
        for (const child of node.children) {
            el.appendChild(ctx.registry.render(child, ctx));
        }
        return el;
    },

    update(el, node, ctx) {
        applyColumns(el, node);
        ctx.reconcileChildren(el, node.children, ctx);
    },
};
