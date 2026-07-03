// One delegated listener per surface. Reads data-sx-events (the interaction-contract
// allowlist, D4) off the target to decide what round-trips. Everything else is local.
// emit(widgetId, eventName, value?, windowId?, app?) is the display server's onEvent --
// the window id (D7) is the nearest [data-window-id] ancestor (null on an un-migrated
// surface); the trailing `app` (Task 6, the {app, window} split) is the same ancestor's
// data-app, so the POST carries BOTH the instance id and the App to run.
export function installDispatcher(surface, emit) {
    // Click: a target (or ancestor) whose allowlist includes 'click' round-trips.
    // Like keydown/change, the id we report is the host WIDGET's (via host()), NOT the
    // element that carried the allowlist -- they're the same on a button (both attrs on
    // the <button>) but split on widgets that put data-sx-events on an inner element
    // (e.g. an icon-button inside a labelled wrapper), where the id lives on the wrapper.
    surface.addEventListener('click', (e) => {
        const el = e.target.closest('[data-sx-events]');
        if (!el) {
            return;
        }
        if (allow(el).includes('click')) {
            emit(host(el).dataset.sxId, 'click', undefined, windowOf(el), appOf(el));
        }
    });

    // Enter in a field whose allowlist includes 'submit' round-trips, carrying the
    // live value. Keystrokes themselves are LOCAL -- no POST per key.
    surface.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter') {
            return;
        }
        const el = e.target.closest('[data-sx-events]');
        if (el && allow(el).includes('submit')) {
            emit(host(el).dataset.sxId, 'submit', e.target.value, windowOf(el), appOf(el));
        }
    });

    // change (debounce deferred -- fires on native change/blur) round-trips the value.
    // A checkbox echoes its boolean `checked`; everything else echoes its string value.
    surface.addEventListener('change', (e) => {
        const el = e.target.closest('[data-sx-events]');
        if (el && allow(el).includes('change')) {
            emit(host(el).dataset.sxId, 'change', liveValue(e.target), windowOf(el), appOf(el));
        }
    });
}

// The value a field echoes up: a checkbox round-trips its boolean checked state;
// any other input round-trips its string value.
function liveValue(target) {
    return target.type === 'checkbox' ? target.checked : target.value;
}

function allow(el) {
    return (el.dataset.sxEvents ?? '').split(',').filter(Boolean);
}

// The window the event belongs to -- the nearest [data-window-id] ancestor (D7/D8).
// Returns null on an un-migrated single surface; the resolver's session fallback then
// keys the bag, so legacy callers stay green.
export function windowOf(el) {
    return el.closest('[data-window-id]')?.dataset.windowId ?? null;
}

// The App to RUN for the event -- the same surface's data-app (the {app, window} split,
// Task 6). Distinct from the window id (the instance/bag key): a launched ULID window
// has a data-app naming its app, since the ULID is no slug to fall back to server-side.
// Null when absent (an un-migrated surface); the server then resolves the app from the
// open-set by (user, window), so legacy callers stay green.
export function appOf(el) {
    return el.closest('[data-window-id]')?.dataset.app ?? null;
}

// The data-sx-id we report is the WIDGET's id. For a TextField the input carries
// data-sx-events but the id lives on the .sx-textfield wrapper.
function host(el) {
    // The `?? el` is a should-never-happen guard: every interactive element is rendered
    // under an id-stamped widget, so closest() always finds one. A misconfigured tree
    // with no id ancestor would emit an empty widget -- not a supported case.
    return el.closest('[data-sx-id]') ?? el;
}
