import { syncIfUnfocused, stampEvents } from './_input.js';

// Stateful leaf input, TextField's simpler cousin. It reuses the SAME interaction
// machinery: the live checked state lives in the DOM, and the declared round-trip
// events ride up via data-sx-events read by the shared delegated dispatcher. A
// 'change' fires when toggled and the dispatcher echoes the checkbox's boolean
// `checked` as the value, addressed by data-sx-id (the box has no `name` attr --
// addressing is id-based, matching TextField's dispatcher contract). update()
// honours focused-input-wins via the shared syncIfUnfocused helper.
export const checkboxRenderer = {
    create(node) {
        const wrap = document.createElement('label');
        wrap.className = 'sx-checkbox';
        wrap.dataset.sxId = node.id ?? '';

        const input = document.createElement('input');
        input.type = 'checkbox';
        input.checked = node.props.checked ?? false;
        stampEvents(input, node.props.events);
        wrap.appendChild(input);

        const text = document.createElement('span');
        text.className = 'sx-checkbox-label';
        text.textContent = node.props.label ?? '';
        wrap.appendChild(text);
        return wrap;
    },

    update(wrap, node) {
        const input = wrap.querySelector('input');
        stampEvents(input, node.props.events);

        const text = wrap.querySelector('.sx-checkbox-label');
        if (text.textContent !== (node.props.label ?? '')) {
            text.textContent = node.props.label ?? '';
        }

        // Focused-input-wins is owned by syncIfUnfocused (one shared place).
        syncIfUnfocused(input, node.props.checked ?? false, 'checked');
    },
};
