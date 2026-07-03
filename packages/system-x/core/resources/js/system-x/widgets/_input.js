// Shared helpers for stateful leaf inputs (textfield, checkbox, and any future
// radio/select/textarea). The ONE place the framework's most important interaction
// invariant lives -- focused-input-wins -- so a fix here reaches every input.

// FOCUSED-INPUT-WINS (D4): a server frame must NEVER clobber the live state of the
// currently-focused input. While the user is mid-keystroke / mid-toggle, the DOM
// holds the truth and an arriving (stale) committed value must not snap it back.
// When the input is NOT focused, only write when the value actually differs (so we
// don't needlessly churn the DOM / reset a selection on an untouched field).
//
// `prop` is the live-state property name on the element: 'value' for text inputs,
// 'checked' for checkboxes. Keeping the property generic is what lets one helper
// serve every input type instead of each copying the guard.
export function syncIfUnfocused(input, incoming, prop = 'value') {
    if (document.activeElement === input) {
        return; // focused: the user's live state wins, leave it alone
    }
    if (input[prop] !== incoming) {
        input[prop] = incoming;
    }
}

// Inputs declare their round-trip event allowlist (props.events) via data-sx-events,
// read by the shared delegated dispatcher (Task 7). Only inputs carry it, so the
// stamping lives here next to the invariant rather than copied per renderer.
export function stampEvents(input, events) {
    input.dataset.sxEvents = (events ?? []).join(',');
}
