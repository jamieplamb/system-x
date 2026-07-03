// Dynamic collection. Children are keyed (props.key) so the morph matches rows by
// identity across reorder/insert/delete. The container itself is never replaced on
// a prop-only update, so its scrollTop is retained. create() routes first-paint
// children through ctx.reconcileChildren (not a bare render loop) so the keyed
// validation -- the loud missing/empty-key diagnostic -- fires on the FIRST render
// too, not just on update. An all-unkeyed or mixed List shouts immediately.
export const listRenderer = {
    create(node, ctx) {
        const el = document.createElement('div');
        el.className = 'sx-list';
        el.dataset.sxId = node.id ?? '';
        // data-sx-type is normally stamped centrally by registry.render() AFTER
        // create() returns, but reconcileChildren reads it off the container to know
        // a `list` expects keys. Stamp it here so the first-paint keyed validation
        // sees the right expectation instead of an undefined type.
        el.dataset.sxType = node.type;
        ctx.reconcileChildren(el, node.children, ctx);
        return el;
    },

    update(el, node, ctx) {
        ctx.reconcileChildren(el, node.children, ctx);
    },
};
