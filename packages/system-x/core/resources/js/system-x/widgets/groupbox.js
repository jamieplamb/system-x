// Titled container -- <fieldset><legend> + a dedicated body host for the children.
// The body div isolates the reconciled children from the legend: reconcilePositional
// matches host.children by INDEX, so the legend must never be a child slot.
export const groupBoxRenderer = {
    create(node, ctx) {
        const el = document.createElement('fieldset');
        el.className = 'sx-groupbox';
        el.dataset.sxId = node.id ?? '';

        const legend = document.createElement('legend');
        legend.className = 'sx-groupbox-legend';
        legend.textContent = node.props.legend ?? '';
        el.appendChild(legend);

        const body = document.createElement('div');
        body.className = 'sx-groupbox-body';
        for (const child of node.children) {
            body.appendChild(ctx.registry.render(child, ctx));
        }
        el.appendChild(body);
        return el;
    },

    update(el, node, ctx) {
        const legend = el.querySelector('.sx-groupbox-legend');
        if (legend.textContent !== (node.props.legend ?? '')) {
            legend.textContent = node.props.legend ?? '';
        }
        ctx.reconcileChildren(el.querySelector('.sx-groupbox-body'), node.children, ctx);
    },
};
