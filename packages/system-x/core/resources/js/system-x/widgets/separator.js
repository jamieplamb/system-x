// A divider rule -- display-only, no events. Orientation drives the
// .sx-separator--{orientation} modifier; update() swaps the whole className.
const orientClass = (o) => `sx-separator--${o ?? 'horizontal'}`;

export const separatorRenderer = {
    create(node) {
        const el = document.createElement('div');
        el.className = `sx-separator ${orientClass(node.props.orientation)}`;
        el.setAttribute('role', 'separator');
        el.setAttribute('aria-orientation', node.props.orientation ?? 'horizontal');
        el.dataset.sxId = node.id ?? '';
        return el;
    },
    update(el, node) {
        const wanted = `sx-separator ${orientClass(node.props.orientation)}`;
        if (el.className !== wanted) {
            el.className = wanted;
        }
        el.setAttribute('aria-orientation', node.props.orientation ?? 'horizontal');
    },
};
