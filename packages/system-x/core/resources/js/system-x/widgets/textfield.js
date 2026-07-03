import { syncIfUnfocused, stampEvents } from './_input.js';

// Stateful leaf input. The live value lives in the DOM (local, zero POST per
// keystroke). update() honours focused-input-wins via the shared syncIfUnfocused
// helper: it never overwrites the value or selection of the currently-focused field
// (D4). The delegated dispatcher owns the declared round-trip events via
// data-sx-events.
export const textFieldRenderer = {
    create(node) {
        const wrap = document.createElement('div');
        wrap.className = 'sx-textfield';
        wrap.dataset.sxId = node.id ?? '';

        const input = document.createElement('input');
        input.type = 'text';
        input.name = node.props.name ?? '';
        input.value = node.props.value ?? '';
        stampEvents(input, node.props.events);
        wrap.appendChild(input);
        return wrap;
    },

    update(wrap, node) {
        const input = wrap.querySelector('input');
        stampEvents(input, node.props.events);
        // Focused-input-wins is owned by syncIfUnfocused (one shared place).
        syncIfUnfocused(input, node.props.value ?? '', 'value');
    },
};
