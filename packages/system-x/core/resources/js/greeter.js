// The greeter's live clock (Plan 5c, Task 5/D5). The blade SEEDS [data-sx-clock] +
// [data-sx-date] with now() server-side (no blank-then-pop, D5); this keeps them live via the
// shared clock.js util -- the SAME tick the panel uses (en-GB 2-digit HH:MM, a 15s interval).
//
// The greeter has no SPA boot -- it's a plain server-rendered page -- so we wire straight off
// DOMContentLoaded and never tear down (the page is replaced wholesale on sign-in).
import { startClock } from './system-x/clock.js';

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => startClock());
} else {
    startClock();
}
