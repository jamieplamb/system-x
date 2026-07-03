// Wrapper widget: a child + a hint bubble. Body-host pattern -- children reconcile into
// .sx-tooltip-content; the .sx-tooltip-bubble is a SIBLING of that host so a positional reconcile
// never clobbers it (cf. groupbox legend/body). Display-only: NO listeners, NO events. Show/hide +
// delay are pure CSS (:hover/:focus-within + transition-delay) -- see widgets.css.

export const tooltipRenderer = {
    create(node, ctx) {
        const wrap = document.createElement('span');
        wrap.className = 'sx-tooltip';
        wrap.dataset.sxId = node.id ?? '';
        wrap.dataset.side = node.props.side ?? 'top';

        const content = document.createElement('span');
        content.className = 'sx-tooltip-content';
        for (const child of node.children) content.appendChild(ctx.registry.render(child, ctx));
        wrap.appendChild(content);

        const bubble = document.createElement('span');
        bubble.className = 'sx-tooltip-bubble';
        bubble.setAttribute('role', 'tooltip');
        bubble.textContent = node.props.text ?? '';
        wrap.appendChild(bubble);

        return wrap;
    },

    update(wrap, node, ctx) {
        const side = node.props.side ?? 'top';
        if (wrap.dataset.side !== side) wrap.dataset.side = side;

        const bubble = wrap.querySelector('.sx-tooltip-bubble');
        if (bubble.textContent !== (node.props.text ?? '')) bubble.textContent = node.props.text ?? '';

        ctx.reconcileChildren(wrap.querySelector('.sx-tooltip-content'), node.children, ctx);
    },
};
