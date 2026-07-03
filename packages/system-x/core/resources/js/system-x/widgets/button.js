// No addEventListener here -- the delegated dispatcher on the surface owns clicks,
// reading data-sx-events. This is what makes button SAFE to reuse across a morph
// (no stacked listeners on a patched element).
export const buttonRenderer = {
    create(node) {
        const el = document.createElement('button');
        el.className = 'sx-button';
        el.dataset.sxId = node.id ?? '';
        el.dataset.sxEvents = (node.props.events ?? ['click']).join(',');
        el.textContent = node.props.label;
        stampPref(el, node);
        return el;
    },

    update(el, node) {
        if (el.textContent !== node.props.label) {
            el.textContent = node.props.label;
        }
        el.dataset.sxEvents = (node.props.events ?? ['click']).join(',');
        stampPref(el, node);
    },
};

// The Appearance/context-menu pref hook + pressed cue (Plan 5b-2, D5) + the Manage-apps
// app-action hook (App-install plan, D4) -- data-* passthroughs the client interceptors
// (prefs.js / manage-apps.js) read/write. Inert for a normal button (no prop -> no attr).
function stampPref(el, node) {
    if (node.props.pref) {
        el.dataset.sxPref = node.props.pref;
    }
    if (node.props.pressed !== undefined) {
        el.dataset.sxPressed = node.props.pressed ? 'true' : 'false';
    }
    if (node.props.appAction) {
        el.dataset.sxAppAction = node.props.appAction;
        // The app's title/icon ride along (App-install plan, D5) so the manage-apps interceptor can
        // re-add the launcher tile on INSTALL -- an uninstalled app's meta isn't in the boot set.
        if (node.props.appTitle !== undefined) {
            el.dataset.sxAppTitle = node.props.appTitle;
        }
        if (node.props.appIcon !== undefined) {
            el.dataset.sxAppIcon = node.props.appIcon;
        }
    }
}
