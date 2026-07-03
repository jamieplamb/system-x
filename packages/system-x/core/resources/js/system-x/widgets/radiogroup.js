import { syncIfUnfocused, stampEvents } from './_input.js';

// One widget, N radios sharing a name (= the widget id, so groups never collide). The
// WRAPPER carries data-sx-id; each inner <input> carries only data-sx-events, so the
// delegated dispatcher's closest('[data-sx-id]') resolves up to the group and echoes the
// checked radio's value. Options are rebuilt only when they change (cached by their key
// signature, same approach as Select); update() also repatches the legend label idempotently.
const groupName = (node) => `sx-radio-${node.id ?? 'x'}`;

const optionsSig = (options) => JSON.stringify(Object.entries(options ?? {}));

const buildRadios = (wrap, options, name, events, value) => {
    // Remove any existing radio rows (leave the legend in place).
    for (const row of [...wrap.querySelectorAll('.sx-radio')]) {
        row.remove();
    }

    for (const [val, label] of Object.entries(options ?? {})) {
        const row = document.createElement('label');
        row.className = 'sx-radio';

        const input = document.createElement('input');
        input.type = 'radio';
        input.name = name;
        input.value = val;
        input.checked = val === (value ?? '');
        stampEvents(input, events);
        row.appendChild(input);

        const track = document.createElement('span');
        track.className = 'sx-radio-mark';
        row.appendChild(track);

        const text = document.createElement('span');
        text.className = 'sx-radio-label';
        text.textContent = label;
        row.appendChild(text);

        wrap.appendChild(row);
    }

    wrap.dataset.sxOptions = optionsSig(options);
};

export const radioGroupRenderer = {
    create(node) {
        const wrap = document.createElement('div');
        wrap.className = 'sx-radiogroup';
        wrap.dataset.sxId = node.id ?? '';

        const legend = document.createElement('span');
        legend.className = 'sx-radiogroup-label';
        legend.textContent = node.props.label ?? '';
        wrap.appendChild(legend);

        buildRadios(wrap, node.props.options, groupName(node), node.props.events, node.props.value);

        return wrap;
    },

    update(wrap, node) {
        // Repatch the legend label if it changed.
        const legend = wrap.querySelector('.sx-radiogroup-label');
        if (legend.textContent !== (node.props.label ?? '')) {
            legend.textContent = node.props.label ?? '';
        }

        if (wrap.dataset.sxOptions !== optionsSig(node.props.options)) {
            // Options set changed -- rebuild the radio rows from scratch.
            buildRadios(wrap, node.props.options, groupName(node), node.props.events, node.props.value);
        } else {
            // Options unchanged -- cheap per-radio sync of events + checked state.
            for (const input of wrap.querySelectorAll('input[type=radio]')) {
                stampEvents(input, node.props.events);
                syncIfUnfocused(input, input.value === (node.props.value ?? ''), 'checked');
            }
        }
    },
};
