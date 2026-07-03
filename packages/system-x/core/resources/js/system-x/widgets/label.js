export const labelRenderer = {
    create(node) {
        const el = document.createElement('div');
        el.className = 'sx-label';
        el.dataset.sxId = node.id ?? '';
        el.textContent = node.props.text;
        return el;
    },

    update(el, node) {
        if (el.textContent !== node.props.text) {
            el.textContent = node.props.text;
        }
    },
};
