import { windowOf, appOf } from '../dispatcher.js';
import { openOverlay } from '../_overlay.js';

// Renders an in-tree button; on click portals a .sx-menu popup of the items prop (DATA, not child
// widgets). A pick emits 'select' + value, with window/app CAPTURED FROM THE TRIGGER (the popup
// portals to body, which has no [data-window-id] ancestor, so deriving from the popup would
// misroute -- see the design spec). Open-state is transient (renderer-owned).

// Build the popup element from items. onPick(value) is called for a live (non-disabled) item.
// EXPORTED so menubar.js reuses it (Task 4).
export function buildMenu(items, onPick) {
    const menu = document.createElement('div');
    menu.className = 'sx-menu';
    menu.setAttribute('role', 'menu');
    for (const item of items ?? []) {
        if (item.divider) {
            const sep = document.createElement('div');
            sep.className = 'sx-menu-divider';
            menu.appendChild(sep);
            continue;
        }
        const row = document.createElement('button');
        row.type = 'button';
        row.className = 'sx-menu-item';
        row.setAttribute('role', 'menuitem');
        if (item.danger) row.classList.add('sx-menu-item-danger');
        if (item.disabled) { row.disabled = true; row.setAttribute('aria-disabled', 'true'); }
        const label = document.createElement('span');
        label.className = 'sx-menu-item-label';
        label.textContent = item.label ?? '';
        row.appendChild(label);
        if (item.accel) {
            const accel = document.createElement('span');
            accel.className = 'sx-menu-item-accel';
            accel.textContent = item.accel;
            row.appendChild(accel);
        }
        if (!item.disabled) row.addEventListener('click', () => onPick(item.value));
        menu.appendChild(row);
    }
    return menu;
}

export const menuButtonRenderer = {
    create(node, ctx) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'sx-menu-button';
        btn.dataset.sxId = node.id ?? '';
        // The click listener must read the CURRENT node, not the create-time one: a server
        // re-render can change props.items (a state-driven menu), and a closure over the original
        // `node` would serve stale items -- and emit stale values -- forever, while the label
        // updates and looks fresh. Stamp the live node on the element (update() re-stamps) and
        // read the stamp at open time. Same bug class as the MenuBar's full-menus signature.
        btn._sxNode = node;
        setLabel(btn, node);
        let handle = null;

        btn.addEventListener('click', () => {
            if (handle) { handle.close(); handle = null; return; } // toggle
            const current = btn._sxNode; // the node from the LATEST render, not create-time
            const win = windowOf(btn); const app = appOf(btn); // capture BEFORE portaling
            handle = openOverlay({
                anchorEl: btn,
                build: ({ close }) => buildMenu(current.props.items, (value) => {
                    ctx.emit(current.id ?? '', 'select', value, win, app);
                    close();
                }),
                onDismiss: () => { handle = null; btn._sxHandle = null; },
            });
            // Mirror the closure handle onto the element so destroy() (a window-close removing the
            // trigger without a dismiss) can close the portaled popup deterministically.
            btn._sxHandle = handle;
        });
        return btn;
    },

    update(btn, node, _ctx) {
        btn._sxNode = node; // keep the click listener's view of the items current
        setLabel(btn, node);
    },

    destroy(btn) { btn._sxHandle?.close(); btn._sxHandle = null; },
};

function setLabel(btn, node) {
    if (btn.textContent !== (node.props.label ?? '')) btn.textContent = node.props.label ?? '';
}
