import { windowOf, appOf } from '../dispatcher.js';
import { openOverlay } from '../_overlay.js';
import { buildMenu } from './menubutton.js';

// Horizontal label strip (in-tree); each label opens its menu's dropdown via the shared overlay.
// Renderer-local coordination: one "open index". Clicking the open label (or a pick / outside /
// Escape) closes; hovering a sibling label WHILE OPEN switches to it (classic menubar behaviour).

export const menuBarRenderer = {
    create(node, ctx) {
        const bar = document.createElement('div');
        bar.className = 'sx-menubar';
        bar.setAttribute('role', 'menubar');
        bar.dataset.sxId = node.id ?? '';

        let handle = null;
        let openIndex = -1;

        const closeOpen = () => { if (handle) { handle.close(); handle = null; } openIndex = -1; };

        const openAt = (i, labelEl) => {
            closeOpen();
            openIndex = i;
            const win = windowOf(bar); const app = appOf(bar);
            handle = openOverlay({
                anchorEl: labelEl,
                build: ({ close }) => buildMenu(node.props.menus[i].items, (value) => {
                    ctx.emit(node.id ?? '', 'select', value, win, app);
                    close();
                }),
                onDismiss: () => { handle = null; openIndex = -1; bar._sxHandle = null; },
            });
            // Mirror the closure handle onto the element so destroy() (a window-close removing the
            // strip without a dismiss) can close the portaled popup deterministically.
            bar._sxHandle = handle;
        };

        (node.props.menus ?? []).forEach((menu, i) => {
            const label = document.createElement('button');
            label.type = 'button';
            label.className = 'sx-menubar-label';
            label.setAttribute('role', 'menuitem');
            label.textContent = menu.label ?? '';
            label.addEventListener('click', () => { openIndex === i ? closeOpen() : openAt(i, label); });
            label.addEventListener('mouseenter', () => { if (openIndex !== -1 && openIndex !== i) openAt(i, label); });
            bar.appendChild(label);
        });
        // Stamp the FULL menus signature so update() can no-op when unchanged (else it rebuilds on
        // EVERY morph tick -- undefined !== sig forever). Sign on the whole menus (not just labels)
        // so an items-only change -- same labels, new item values -- also triggers a rebuild; a
        // label-only signature would leave the strip closing over stale item data. REQUIRED.
        bar.dataset.sxMenus = JSON.stringify(node.props.menus ?? []);
        return bar;
    },

    update(bar, node, ctx) {
        const sig = JSON.stringify(node.props.menus ?? []);
        if (bar.dataset.sxMenus !== sig) {
            bar.replaceChildren();
            const fresh = menuBarRenderer.create(node, ctx);
            bar.dataset.sxMenus = fresh.dataset.sxMenus;
            for (const child of [...fresh.childNodes]) bar.appendChild(child);
        }
    },

    destroy(bar) { bar._sxHandle?.close(); bar._sxHandle = null; },
};
