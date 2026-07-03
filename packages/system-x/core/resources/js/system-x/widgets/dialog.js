import { windowOf, appOf } from '../dispatcher.js';
import { createFocusTrap } from '../_focus-trap.js';

// Window-modal dialog. The root is a stable, layout-neutral mount (display:contents) that the
// morph always patches; when props.open is true it holds a backdrop (dims + blocks the window's
// VISIBLE content) and a centred panel (title bar + body host). Dismiss affordances -- the close
// button (click), Escape (a document-level keydown, see buildOverlay), backdrop click (mousedown
// ON the backdrop itself, not a child; mousedown not click so a drag that releases outside can't
// fire a spurious close) -- emit the 'close' event programmatically via ctx.emit, routed like any
// widget event to onClose. Escape/backdrop are gated on dismissible, which update() re-checks so
// a mid-open flip (e.g. "saving, don't dismiss") takes effect. See the design spec, slice 3a.

const isOpen = (node) => node.props.open === true;
const isDismissible = (node) => node.props.dismissible !== false;

function buildOverlay(root, node, ctx) {
    const backdrop = document.createElement('div');
    backdrop.className = 'sx-dialog-backdrop';

    const panel = document.createElement('div');
    panel.className = 'sx-dialog-panel';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'true');

    const bar = document.createElement('div');
    bar.className = 'sx-dialog-titlebar';
    const title = document.createElement('span');
    title.className = 'sx-dialog-title';
    title.textContent = node.props.title ?? '';
    bar.appendChild(title);

    if (isDismissible(node)) {
        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'sx-dialog-close';
        close.setAttribute('aria-label', 'Close');
        close.textContent = '×'; // multiplication sign as the close glyph
        close.addEventListener('click', () => fireClose(root, node, ctx));
        bar.appendChild(close);
    }
    panel.appendChild(bar);

    const body = document.createElement('div');
    body.className = 'sx-dialog-body';
    for (const child of node.children) body.appendChild(ctx.registry.render(child, ctx));
    panel.appendChild(body);

    backdrop.appendChild(panel);

    if (isDismissible(node)) {
        // mousedown (not click) on the backdrop ITSELF: a click that starts inside the panel and
        // releases on the backdrop (a drag) must not dismiss. e.target === backdrop excludes panel.
        backdrop.addEventListener('mousedown', (e) => { if (e.target === backdrop) fireClose(root, node, ctx); });
    }

    // Escape lives on the DOCUMENT (bubble phase), not the backdrop. A backdrop-scoped keydown
    // only fired while focus sat inside the dialog, which broke two ways: a dialog that BOOTS
    // open (durable state on a reload) never received focus -- create() runs on a detached tree,
    // so the trap's focus() was a silent no-op and Escape was dead until a click inside -- and
    // clicking the still-live window titlebar moved focus out, killing Escape again. Bubble phase
    // also keeps nesting right: an open menu popup's capture-phase Escape stopPropagation()s, so
    // one press closes the popup and the NEXT press reaches this listener. Gated on the LIVE
    // dismissible stamp (update() re-stamps on a flip); stored on the root for teardown.
    root.dataset.sxDismissible = isDismissible(node) ? '1' : '0';
    root._sxEsc = (e) => {
        if (e.key === 'Escape' && root.dataset.sxDismissible === '1') fireClose(root, node, ctx);
    };
    document.addEventListener('keydown', root._sxEsc);

    root.appendChild(backdrop);

    // Window-modal means modal over the window's VISIBLE content. In a sized window the content
    // scrolls (overflow:auto), and the stylesheet's inset:0 backdrop anchors to the content's TOP
    // spanning one viewport-height -- scrolled down, the dimming (and the panel) scroll away. So:
    // pin the backdrop to the current scroll offset with an explicit viewport-sized box (inline
    // beats the stylesheet inset), and LOCK the content's scroll while open so the offset holds.
    const content = root.closest('.sx-content');
    if (content) {
        backdrop.style.top = `${content.scrollTop}px`;
        backdrop.style.left = `${content.scrollLeft}px`;
        backdrop.style.bottom = 'auto';
        backdrop.style.right = 'auto';
        backdrop.style.width = '100%';  // % resolves against the padding box = the visible viewport
        backdrop.style.height = '100%';
        content.classList.add('sx-dialog-scroll-lock');
        root._sxLock = content;
    }

    // Engage the focus trap once CONNECTED: create() runs on a detached tree (the reconciler
    // appends after render), where focus() is a silent no-op. Defer a microtask when detached,
    // guarding that the overlay is still mounted when it runs (an instant close must not trap).
    const engage = () => {
        if (root.isConnected && root.contains(panel) && !root._sxTrap) root._sxTrap = createFocusTrap(panel);
    };
    if (root.isConnected) engage();
    else queueMicrotask(engage);
}

function fireClose(root, node, ctx) {
    ctx.emit(node.id ?? '', 'close', undefined, windowOf(root), appOf(root));
}

function teardown(root) {
    if (root._sxTrap) { root._sxTrap.release(); root._sxTrap = null; }
    if (root._sxEsc) { document.removeEventListener('keydown', root._sxEsc); root._sxEsc = null; }
    if (root._sxLock) { root._sxLock.classList.remove('sx-dialog-scroll-lock'); root._sxLock = null; }
    delete root.dataset.sxDismissible;
    root.replaceChildren();
}

export const dialogRenderer = {
    create(node, ctx) {
        const root = document.createElement('div');
        root.className = 'sx-dialog';
        root.dataset.sxId = node.id ?? '';
        if (isOpen(node)) buildOverlay(root, node, ctx);
        return root;
    },

    update(root, node, ctx) {
        const mounted = root.querySelector('.sx-dialog-backdrop') !== null;
        if (isOpen(node) && !mounted) { buildOverlay(root, node, ctx); return; }
        if (!isOpen(node) && mounted) { teardown(root); return; }
        if (isOpen(node) && mounted) {
            // A dismissible flip mid-open changes the chrome (the close button) AND the dismiss
            // wiring -- rebuild rather than patch (rare prop; children re-render, acceptable).
            const dismissible = isDismissible(node) ? '1' : '0';
            if (root.dataset.sxDismissible !== dismissible) {
                teardown(root);
                buildOverlay(root, node, ctx);
                return;
            }
            const title = root.querySelector('.sx-dialog-title');
            if (title.textContent !== (node.props.title ?? '')) title.textContent = node.props.title ?? '';
            ctx.reconcileChildren(root.querySelector('.sx-dialog-body'), node.children, ctx);
        }
    },

    // A window-close removes the dialog element WITHOUT an open -> false flip, so update()'s
    // teardown never runs and the DOCUMENT-level Escape listener (_sxEsc) would leak. destroyTree
    // invokes this on every removal path; teardown is idempotent + null-guards each _sx* field.
    destroy(el) { teardown(el); },
};
