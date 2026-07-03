import { syncIfUnfocused, stampEvents } from './_input.js';

// Native <input type="range"> in a styled wrapper. The WRAPPER carries data-sx-id; the
// range carries data-sx-events only, so the dispatcher resolves up to the wrapper and
// echoes the value (a STRING). The native `change` fires on release, so no drag-spam.
export const sliderRenderer = {
    create(node) {
        const wrap = document.createElement('label');
        wrap.className = 'sx-slider';
        wrap.dataset.sxId = node.id ?? '';

        const text = document.createElement('span');
        text.className = 'sx-slider-label';
        text.textContent = node.props.label ?? '';
        wrap.appendChild(text);

        const input = document.createElement('input');
        input.type = 'range';
        input.className = 'sx-slider-control';
        input.min = String(node.props.min ?? 0);
        input.max = String(node.props.max ?? 100);
        input.step = String(node.props.step ?? 1);
        input.value = String(node.props.value ?? 0);
        stampEvents(input, node.props.events);
        wrap.appendChild(input);
        return wrap;
    },

    update(wrap, node) {
        const input = wrap.querySelector('input[type=range]');
        stampEvents(input, node.props.events);

        const text = wrap.querySelector('.sx-slider-label');
        if (text.textContent !== (node.props.label ?? '')) {
            text.textContent = node.props.label ?? '';
        }

        // patch range bounds if they changed (cheap, idempotent)
        const min = String(node.props.min ?? 0);
        const max = String(node.props.max ?? 100);
        const step = String(node.props.step ?? 1);
        if (input.min !== min) input.min = min;
        if (input.max !== max) input.max = max;
        if (input.step !== step) input.step = step;

        syncIfUnfocused(input, String(node.props.value ?? 0), 'value');
    },
};
