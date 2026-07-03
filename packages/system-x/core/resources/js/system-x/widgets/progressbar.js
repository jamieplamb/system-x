// Determinate or indeterminate progress bar -- display-only, no events.
// Determinate: fill width tracks value (0-100). Indeterminate: CSS sweep animation
// drives the fill; aria-valuenow is omitted per ARIA spec.
// update() is idempotent -- patches fill, modifier, ARIA, and label span in place.

const baseClass = 'sx-progressbar';
const indeterminateClass = 'sx-progressbar--indeterminate';

const buildLabel = (value, label) => {
    const row = document.createElement('span');
    row.className = 'sx-progressbar-label';

    const name = document.createElement('span');
    name.textContent = label;
    row.appendChild(name);

    const pct = document.createElement('span');
    pct.className = 'sx-progressbar-pct';
    pct.textContent = `${value}%`;
    row.appendChild(pct);

    return row;
};

const updateLabel = (labelEl, value, label) => {
    labelEl.firstChild.textContent = label;
    labelEl.querySelector('.sx-progressbar-pct').textContent = `${value}%`;
};

export const progressBarRenderer = {
    create(node) {
        const { value = 0, indeterminate = false, label = null } = node.props;

        const el = document.createElement('div');
        el.className = indeterminate ? `${baseClass} ${indeterminateClass}` : baseClass;
        el.dataset.sxId = node.id ?? '';
        el.setAttribute('role', 'progressbar');
        el.setAttribute('aria-valuemin', '0');
        el.setAttribute('aria-valuemax', '100');
        if (!indeterminate) {
            el.setAttribute('aria-valuenow', String(value));
        }

        if (label != null) {
            el.appendChild(buildLabel(value, label));
        }

        const fill = document.createElement('div');
        fill.className = 'sx-progressbar-track';

        const fillInner = document.createElement('div');
        fillInner.className = 'sx-progressbar-fill';
        fillInner.style.width = `${value}%`;

        fill.appendChild(fillInner);
        el.appendChild(fill);

        return el;
    },

    update(el, node) {
        const { value = 0, indeterminate = false, label = null } = node.props;

        // Modifier -- full className swap to stay clean
        const wanted = indeterminate ? `${baseClass} ${indeterminateClass}` : baseClass;
        if (el.className !== wanted) {
            el.className = wanted;
        }

        // ARIA -- valuenow present only when determinate
        if (indeterminate) {
            el.removeAttribute('aria-valuenow');
        } else {
            el.setAttribute('aria-valuenow', String(value));
        }

        // Fill width
        const fill = el.querySelector('.sx-progressbar-fill');
        if (fill) {
            fill.style.width = `${value}%`;
        }

        // Label span -- create, patch, or remove as label goes null<->set
        let labelEl = el.querySelector('.sx-progressbar-label');
        if (label != null) {
            if (!labelEl) {
                labelEl = buildLabel(value, label);
                // label sits before the track
                const track = el.querySelector('.sx-progressbar-track');
                el.insertBefore(labelEl, track);
            } else {
                updateLabel(labelEl, value, label);
            }
        } else if (labelEl) {
            labelEl.remove();
        }
    },
};
