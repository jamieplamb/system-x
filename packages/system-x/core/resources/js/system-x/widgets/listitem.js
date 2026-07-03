export const listItemRenderer = {
    create(node) {
        const el = document.createElement('div');
        el.className = 'sx-listitem';
        el.dataset.sxId = node.id ?? '';
        // data-sx-key is NOT stamped here: it is the reconciliation key the keyed
        // morph matches on, and registry.render() stamps it CENTRALLY for every
        // element it builds (Task 2). Centralizing it means a row that changes TYPE
        // -- rebuilt by the new type's create(), not listitem's -- still carries its
        // key. Stamping it here too would just duplicate the central contract.
        el.textContent = node.props.text ?? '';
        return el;
    },

    update(el, node) {
        // data-sx-key is stamped centrally by registry.render() on create; a matched
        // element being update()d already carries it, so we do not re-stamp it here.
        if (el.textContent !== (node.props.text ?? '')) {
            el.textContent = node.props.text ?? '';
        }
    },
};
