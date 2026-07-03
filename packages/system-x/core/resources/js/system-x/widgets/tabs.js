import { syncIfUnfocused, stampEvents } from './_input.js';

// Tabs = a RadioGroup strip (the change round-trips the active tab) + a GroupBox-style body
// host (the panels, shown one at a time). See the design spec section 3.
const stripName = (node) => `sx-tabs-${node.id ?? 'x'}`;
const tabsSig = (tabs) => JSON.stringify(Object.entries(tabs ?? {}));

const buildStrip = (strip, tabs, name, events, active) => {
    for (const tab of [...strip.querySelectorAll('.sx-tab')]) tab.remove();
    for (const [val, label] of Object.entries(tabs ?? {})) {
        const row = document.createElement('label');
        row.className = 'sx-tab';
        const input = document.createElement('input');
        input.type = 'radio';
        input.name = name;
        input.value = val;
        input.checked = val === (active ?? '');
        stampEvents(input, events);
        row.appendChild(input);
        const text = document.createElement('span');
        text.className = 'sx-tab-label';
        text.textContent = label;
        row.appendChild(text);
        strip.appendChild(row);
    }
    strip.dataset.sxTabs = tabsSig(tabs);
};

// Show only the panel whose index matches `value` among the LIVE strip radios (panels pair
// to tabs BY ORDER). Unknown/empty -> first panel. Reads the live DOM, so it is never stale
// after a tab-set rebuild.
const showPanelForValue = (strip, body, value) => {
    const radios = [...strip.querySelectorAll('input[type=radio]')];
    let idx = radios.findIndex((r) => r.value === value);
    if (idx < 0) idx = 0;
    [...body.children].forEach((panel, i) => panel.toggleAttribute('hidden', i !== idx));
};

export const tabsRenderer = {
    create(node, ctx) {
        const wrap = document.createElement('div');
        wrap.className = 'sx-tabs';
        wrap.dataset.sxId = node.id ?? '';

        const strip = document.createElement('div');
        strip.className = 'sx-tabs-strip';
        buildStrip(strip, node.props.tabs, stripName(node), node.props.events, node.props.active);
        wrap.appendChild(strip);

        const body = document.createElement('div');
        body.className = 'sx-tabs-body';
        for (const child of node.children) body.appendChild(ctx.registry.render(child, ctx));
        wrap.appendChild(body);

        showPanelForValue(strip, body, node.props.active);

        // OPTIMISTIC INSTANT-SWITCH -- the one deliberate local listener. UI-only (shows the
        // clicked tab's panel immediately), never POSTs (the durable active-tab round-trips via
        // the radios' data-sx-events on the delegated dispatcher). Attached ONCE here, NOT in
        // update(), so it never stacks. Reads the live strip/body, so it survives tab-set changes.
        strip.addEventListener('change', (e) => showPanelForValue(strip, body, e.target.value));

        return wrap;
    },

    update(wrap, node, ctx) {
        const strip = wrap.querySelector('.sx-tabs-strip');
        const body = wrap.querySelector('.sx-tabs-body');

        if (strip.dataset.sxTabs !== tabsSig(node.props.tabs)) {
            buildStrip(strip, node.props.tabs, stripName(node), node.props.events, node.props.active);
        } else {
            for (const input of strip.querySelectorAll('input[type=radio]')) {
                stampEvents(input, node.props.events);
                syncIfUnfocused(input, input.value === (node.props.active ?? ''), 'checked');
            }
        }

        ctx.reconcileChildren(body, node.children, ctx);

        // COORDINATION RULE (spec 3.2): apply panel visibility from props.active UNCONDITIONALLY
        // every update -- independent of the rebuild-vs-sync branch above -- so a tab-set rebuild
        // never skips re-showing the active panel. (Select's rebuild-THEN-sync shape.)
        showPanelForValue(strip, body, node.props.active);
    },
};
