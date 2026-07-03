// The escape hatch (D7). Renders developer-supplied HTML verbatim inside .sx-raw.
// system-x does NOT sanitise it -- the XSS responsibility sits with the APP AUTHOR
// (like React's dangerouslySetInnerHTML); raw must NEVER carry unescaped end-user
// input. OPAQUE TO THE MORPH: the reconciler never keyed-morphs INSIDE a raw node.
// create() stamps innerHTML once; update() does nothing UNLESS props.html changed,
// then it replaces innerHTML wholesale (replace-on-change). Leaving an unchanged raw
// untouched means any iframe / dev-managed state inside survives a re-render. raw
// has NO interaction contract -- it sets no data-sx-events.
export const rawRenderer = {
    create(node) {
        const el = document.createElement('div');
        el.className = 'sx-raw';
        el.dataset.sxId = node.id ?? '';
        el.dataset.sxHtml = node.props.html ?? ''; // remembered so update() can diff
        el.innerHTML = node.props.html ?? '';
        return el;
    },

    update(el, node) {
        const incoming = node.props.html ?? '';
        // Replace-on-change ONLY. An unchanged raw is left fully untouched so any
        // iframe / dev-managed widget inside it is not torn down and rebuilt.
        if (el.dataset.sxHtml !== incoming) {
            el.dataset.sxHtml = incoming;
            el.innerHTML = incoming;
        }
    },
};
