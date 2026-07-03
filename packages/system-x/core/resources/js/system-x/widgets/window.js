// Window: titlebar + content. Children render through ctx.registry so any
// (including pro) child type composes correctly. Container is structurally fixed
// (titlebar then content), so its children match positionally in the morph.
// data-sx-type is stamped centrally by registry.render() -- not set here.

// The framework-owned window-chrome glyphs (Plan 5a, D5), ported from the design
// WindowControls. EXPORTED (N1) so window-manager.js can reuse the SAME glyph source
// when it swaps the maximise<->restore control on maximise toggle (Task 5) -- one
// source of truth for the SVG, no duplication. Inner SVG markup only; the wrapping
// <svg> (with stroke + viewBox) is built by controlButton().
export const CONTROL_GLYPHS = {
    minimise: '<line x1="2.5" y1="8" x2="7.5" y2="8"/>',
    close: '<line x1="2.4" y1="2.4" x2="7.6" y2="7.6"/><line x1="7.6" y1="2.4" x2="2.4" y2="7.6"/>',
    maximise: '<rect x="2" y="1.8" width="6" height="6.4" fill="none"/>',
    restore: '<rect x="1.6" y="2.6" width="4.6" height="4.6" fill="none"/><path d="M3.4 2.6V1.4H7.4V5.4H6.2" fill="none"/>',
};

// A single framework-owned chrome control button. WM-local: NO data-sx-events, so it
// never round-trips as a widget event -- the WM layer handles the click (focus/drag
// guards it via [data-sx-control], close calls the close endpoint in Task 9).
function controlButton(kind) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = `sx-window-control sx-window-control-${kind}`;
    btn.dataset.sxControl = kind;
    btn.setAttribute('aria-label', kind);
    btn.title = kind;
    btn.innerHTML = `<svg width="10" height="10" viewBox="0 0 10 10" aria-hidden="true" stroke="currentColor" stroke-width="1.4" stroke-linecap="round">${CONTROL_GLYPHS[kind]}</svg>`;
    return btn;
}

// The eight resize directions (Plan 5d, D8): four edges + four corners. The WM arms
// resize from a [data-sx-resize] pointerdown via delegation (Task 4), exactly as it
// listens for [data-sx-control] -- the handles are inert DOM until then.
const RESIZE_DIRS = ['n', 'e', 's', 'w', 'ne', 'nw', 'se', 'sw'];

// A single framework-owned resize handle (Plan 5d, D8). WM-local, like controlButton:
// NO data-sx-events, so it never round-trips as a widget event -- the WM owns the press
// (data-sx-resize). Positioned + cursored entirely by surface.css. The handles ride
// inside .sx-window (siblings of the titlebar/content, OUTSIDE .sx-content) so the morph
// -- which targets surface.firstElementChild + reconciles .sx-content -- never touches
// them (Landmine 1).
function resizeHandle(dir) {
    const handle = document.createElement('div');
    handle.className = `sx-resize-handle sx-resize-${dir}`;
    handle.dataset.sxResize = dir;
    return handle;
}

export const windowRenderer = {
    create(node, ctx) {
        const el = document.createElement('div');
        el.className = 'sx-window';
        el.dataset.sxId = node.id ?? '';
        // Initial size hints only -- width is a hint, height is a CAP -- the WM owns geometry
        // on the SURFACE after this (D3). No data-sx-active here: focus is WM-owned on the
        // .sx-window-surface now, not the .sx-window, so a server frame can never re-stamp (or
        // reset) it. base.css's inactive selector keys off the surface, so the chrome lights up
        // from real focus.
        el.style.width = `${node.props.width}px`;
        // V1-A: a declared HEIGHT is a CAP, not a floor -- the window is exactly this tall and its
        // content scrolls (base.css .sx-content overflow:auto) rather than growing off-screen. A
        // manual resize sets data-sx-sized; the [data-sx-sized] fill rule's height:100% !important
        // then beats this inline height, so the cap goes inert once the user sizes the window.
        el.style.height = `${node.props.height}px`;

        // Titlebar: a flex-grow title text span + the framework-owned control row. The
        // controls are injected UNCONDITIONALLY by the renderer (chrome is sacred, identical
        // on every window, NOT declared per-app, D5). The title lives in its own span so
        // update() can patch it without touching the controls.
        const titlebar = document.createElement('div');
        titlebar.className = 'sx-titlebar';

        const titleText = document.createElement('span');
        titleText.className = 'sx-titlebar-text';
        titleText.textContent = node.props.title;
        titlebar.appendChild(titleText);

        const controls = document.createElement('div');
        controls.className = 'sx-titlebar-controls';
        // Order: [minimise, maximise/restore, close] -- the mockup's control order (5b D4).
        // Minimise hides the window to its panel button; the panel (Task 4) brings it back.
        controls.appendChild(controlButton('minimise'));
        controls.appendChild(controlButton('maximise'));
        controls.appendChild(controlButton('close'));
        titlebar.appendChild(controls);

        el.appendChild(titlebar);

        const content = document.createElement('div');
        content.className = 'sx-content';
        for (const child of node.children) {
            content.appendChild(ctx.registry.render(child, ctx));
        }
        el.appendChild(content);

        // The eight resize handles (Plan 5d, D8) -- framework chrome, like the controls.
        // Appended LAST so they sit OUTSIDE .sx-content (Landmine 1: the morph reconciles
        // .sx-content, so anything inside it would be wiped; these ride directly under
        // .sx-window). update() leaves them alone (D3). They're inert until the WM arms
        // them (Task 4); surface.css positions them + insets the top ones clear of the
        // titlebar/controls so the drag + close still win (B4).
        for (const dir of RESIZE_DIRS) {
            el.appendChild(resizeHandle(dir));
        }
        return el;
    },

    update(el, node, ctx) {
        // D3: do NOT re-apply width/minHeight or data-sx-active here. Width is a
        // create()-time hint (the WM owns geometry on the surface); active is WM-owned
        // on the surface too. Re-stamping either would let a server frame fight the WM
        // on every render. Only the CONTENT (title text + children) updates -- the
        // framework chrome (controls) is built in create() and the morph leaves it alone.
        el.querySelector('.sx-titlebar .sx-titlebar-text').textContent = node.props.title;
        // Children reconcile against the .sx-content child slot.
        ctx.reconcileChildren(el.querySelector('.sx-content'), node.children, ctx);
    },
};
