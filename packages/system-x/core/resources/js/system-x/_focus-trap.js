// A minimal focus-trap for the overlay layer. createFocusTrap(panel) remembers the currently
// focused element, moves focus to the first focusable inside `panel`, and keeps Tab/Shift+Tab
// cycling within the panel until release() restores focus. Kept tiny + standalone so the Menu
// (3b) and Tooltip (3c) work can reuse or adapt it. Focus order is DOM order (not tabindex-
// aware) -- the sane default for a dialog whose body is authored top-to-bottom.
const FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';

export function createFocusTrap(panel) {
    const previouslyFocused = document.activeElement;
    // No visibility (offsetParent) filter: a dialog's body is authored visible, and an
    // offsetParent check is both YAGNI here and dead under jsdom (no layout -> always null).
    const focusables = () => [...panel.querySelectorAll(FOCUSABLE)];

    const first = focusables()[0] ?? panel;
    first.focus();

    const onKeydown = (e) => {
        if (e.key !== 'Tab') return;
        const items = focusables();
        if (items.length === 0) { e.preventDefault(); return; }
        const idx = items.indexOf(document.activeElement);
        if (e.shiftKey && (idx <= 0)) { e.preventDefault(); items[items.length - 1].focus(); }
        else if (!e.shiftKey && idx === items.length - 1) { e.preventDefault(); items[0].focus(); }
    };
    panel.addEventListener('keydown', onKeydown);

    return {
        release() {
            panel.removeEventListener('keydown', onKeydown);
            if (previouslyFocused && typeof previouslyFocused.focus === 'function') previouslyFocused.focus();
        },
    };
}
