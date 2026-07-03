import { syncIfUnfocused, stampEvents } from './_input.js';

// Native <select> in a styled wrapper. The WRAPPER carries data-sx-id; the <select>
// carries data-sx-events only, so the delegated dispatcher resolves up to the wrapper
// and echoes the selected option's value. Options are rebuilt only when they change
// (cached by their key signature); the selected value syncs via syncIfUnfocused.
const optionsSig = (options) => JSON.stringify(Object.entries(options ?? {}));

const buildOptions = (select, options) => {
    select.replaceChildren(
        ...Object.entries(options ?? {}).map(([val, label]) => {
            const opt = document.createElement('option');
            opt.value = val;
            opt.textContent = label;
            return opt;
        }),
    );
    select.dataset.sxOptions = optionsSig(options);
};

export const selectRenderer = {
    create(node) {
        const wrap = document.createElement('label');
        wrap.className = 'sx-select';
        wrap.dataset.sxId = node.id ?? '';

        const text = document.createElement('span');
        text.className = 'sx-select-label';
        text.textContent = node.props.label ?? '';
        wrap.appendChild(text);

        const select = document.createElement('select');
        select.className = 'sx-select-control';
        stampEvents(select, node.props.events);
        buildOptions(select, node.props.options);
        select.value = node.props.value ?? '';
        wrap.appendChild(select);
        return wrap;
    },

    update(wrap, node) {
        const select = wrap.querySelector('select');
        stampEvents(select, node.props.events);

        const text = wrap.querySelector('.sx-select-label');
        if (text.textContent !== (node.props.label ?? '')) {
            text.textContent = node.props.label ?? '';
        }

        // Rebuild options only if the set changed (avoids clobbering an open dropdown).
        if (select.dataset.sxOptions !== optionsSig(node.props.options)) {
            buildOptions(select, node.props.options);
        }

        syncIfUnfocused(select, node.props.value ?? '', 'value');
    },
};
