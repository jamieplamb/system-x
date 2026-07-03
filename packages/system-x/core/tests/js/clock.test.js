import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { formatTime, formatDate, startClock } from '../../resources/js/system-x/clock.js';

describe('clock format fns (the shared en-GB treatment)', () => {
    it('formatTime renders en-GB 2-digit HH:MM for a given Date', () => {
        // 09:05 -- the panel pins the 2-digit zero-pad (09, not 9).
        expect(formatTime(new Date(2026, 5, 28, 9, 5, 0))).toBe('09:05');
    });

    it('formatTime is 24-hour (afternoon reads 14:30, not 02:30 pm)', () => {
        expect(formatTime(new Date(2026, 5, 28, 14, 30, 0))).toBe('14:30');
    });

    it('formatDate renders the greeter date string (en-GB long weekday + day + month)', () => {
        // 28 June 2026 is a Sunday.
        const out = formatDate(new Date(2026, 5, 28, 9, 5, 0));
        expect(out).toContain('Sunday');
        expect(out).toContain('28');
        expect(out).toContain('June');
    });
});

describe('startClock tick (writes the clock + date into a root)', () => {
    let root;

    beforeEach(() => {
        vi.useFakeTimers();
        root = document.createElement('div');
        document.body.replaceChildren(root);
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('paints the time into [data-sx-clock] and the date into [data-sx-date] at once', () => {
        const clock = document.createElement('span');
        clock.setAttribute('data-sx-clock', '');
        const date = document.createElement('span');
        date.setAttribute('data-sx-date', '');
        root.append(clock, date);

        vi.setSystemTime(new Date(2026, 5, 28, 9, 5, 0));
        const stop = startClock(root);

        expect(clock.textContent).toBe('09:05');
        expect(date.textContent).toContain('Sunday');
        stop();
    });

    it('repaints on the 15000ms interval as the clock advances', () => {
        const clock = document.createElement('span');
        clock.setAttribute('data-sx-clock', '');
        root.append(clock);

        vi.setSystemTime(new Date(2026, 5, 28, 9, 5, 0));
        const stop = startClock(root);
        expect(clock.textContent).toBe('09:05');

        vi.setSystemTime(new Date(2026, 5, 28, 9, 6, 30));
        vi.advanceTimersByTime(15000);
        expect(clock.textContent).toBe('09:06');
        stop();
    });

    it('the returned stop fn clears the interval so it no longer ticks', () => {
        const clock = document.createElement('span');
        clock.setAttribute('data-sx-clock', '');
        root.append(clock);

        vi.setSystemTime(new Date(2026, 5, 28, 9, 5, 0));
        const stop = startClock(root);
        stop();

        vi.setSystemTime(new Date(2026, 5, 28, 9, 6, 30));
        vi.advanceTimersByTime(15000);
        expect(clock.textContent).toBe('09:05');
    });

    it('a missing [data-sx-clock] or [data-sx-date] is a safe no-op (no throw)', () => {
        // An empty root has neither element (the panel has no [data-sx-date]).
        vi.setSystemTime(new Date(2026, 5, 28, 9, 5, 0));
        expect(() => {
            const stop = startClock(root);
            vi.advanceTimersByTime(15000);
            stop();
        }).not.toThrow();
    });

    it('defaults to document when no root is given', () => {
        const clock = document.createElement('span');
        clock.setAttribute('data-sx-clock', '');
        document.body.append(clock);

        vi.setSystemTime(new Date(2026, 5, 28, 9, 5, 0));
        const stop = startClock();
        expect(clock.textContent).toBe('09:05');
        stop();
    });
});
