// Inline status pill -- display-only, no events. tone drives the .sx-badge--{tone}
// modifier; update() swaps text + tone class in place (idempotent, no listeners).
const toneClass = (tone) => `sx-badge--${tone ?? 'neutral'}`;

export const badgeRenderer = {
    create(node) {
        const el = document.createElement('span');
        el.className = `sx-badge ${toneClass(node.props.tone)}`;
        el.dataset.sxId = node.id ?? '';
        el.textContent = node.props.text ?? '';
        return el;
    },

    update(el, node) {
        const wanted = `sx-badge ${toneClass(node.props.tone)}`;
        if (el.className !== wanted) {
            el.className = wanted;
        }
        if (el.textContent !== (node.props.text ?? '')) {
            el.textContent = node.props.text ?? '';
        }
    },
};
