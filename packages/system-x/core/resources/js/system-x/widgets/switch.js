import { syncIfUnfocused, stampEvents } from './_input.js';

// Pill-styled boolean toggle -- Checkbox's re-skinned cousin. Uses the SAME
// interaction contract: the real <input type="checkbox"> is visually hidden but
// stays in the DOM (accessible + clickable), carrying data-sx-events so the shared
// delegated dispatcher can echo `checked` keyed by the wrapper's data-sx-id. The
// visible pill + thumb are pure CSS on .sx-switch-track -- the :checked sibling
// selector does the accent fill + thumb slide. update() honours focused-input-wins
// via syncIfUnfocused, exactly as Checkbox does.
export const switchRenderer = {
    create(node) {
        const wrap = document.createElement('label');
        wrap.className = 'sx-switch';
        wrap.dataset.sxId = node.id ?? '';

        const input = document.createElement('input');
        input.type = 'checkbox';
        input.checked = node.props.checked ?? false;
        stampEvents(input, node.props.events);
        wrap.appendChild(input);

        const track = document.createElement('span');
        track.className = 'sx-switch-track';
        wrap.appendChild(track);

        const text = document.createElement('span');
        text.className = 'sx-switch-label';
        text.textContent = node.props.label ?? '';
        wrap.appendChild(text);

        return wrap;
    },

    update(wrap, node) {
        const input = wrap.querySelector('input');
        stampEvents(input, node.props.events);

        const text = wrap.querySelector('.sx-switch-label');
        if (text.textContent !== (node.props.label ?? '')) {
            text.textContent = node.props.label ?? '';
        }

        // Focused-input-wins -- same guard Checkbox uses.
        syncIfUnfocused(input, node.props.checked ?? false, 'checked');
    },
};
