// The shared live clock util (Plan 5c, Task 5/D5) -- ONE tested tick the greeter AND the
// panel both consume. The panel's tray clock and the greeter's big clock both read en-GB
// 2-digit HH:MM on a 15s cadence (a minute-granular HH:MM only needs a coarse tick); the
// greeter ALSO shows a date. The panel keeps its startClock/stopClock/renderClock methods --
// they delegate the format here so there is one source of truth for the treatment.

// Format a Date to en-GB 24h 2-digit HH:MM (09:05, 14:30). The panel test pins 09:05 -> 09:06.
export function formatTime(date) {
    return date.toLocaleTimeString('en-GB', {
        hour: '2-digit',
        minute: '2-digit',
    });
}

// Format a Date to the greeter's date line: en-GB long weekday + day + month (Sunday 28 June).
// The blade seeds [data-sx-date] server-side with now() so the first paint is never blank.
export function formatDate(date) {
    return date.toLocaleDateString('en-GB', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    });
}

// Paint the current time into [data-sx-clock] and the date into [data-sx-date] within root.
// A missing element is a safe no-op -- the panel has a clock but NO date element; the greeter
// has both. Used for both the immediate paint and each interval tick.
export function paintClock(root = document) {
    const now = new Date();

    const clock = root.querySelector('[data-sx-clock]');
    if (clock) {
        clock.textContent = formatTime(now);
    }

    const date = root.querySelector('[data-sx-date]');
    if (date) {
        date.textContent = formatDate(now);
    }
}

// Start a live clock within root: paint at ONCE (no full-interval wait for the first tick),
// then repaint on a 15000ms setInterval. Returns a stop fn that clears the interval (the panel
// re-start + a unit test both rely on a clean teardown). root defaults to document.
export function startClock(root = document) {
    paintClock(root);
    const timer = setInterval(() => paintClock(root), 15000);
    return () => clearInterval(timer);
}
