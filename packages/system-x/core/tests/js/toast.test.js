import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { showToast } from '../../resources/js/system-x/toast.js';

describe('showToast', () => {
    beforeEach(() => { vi.useFakeTimers(); document.body.replaceChildren(); });
    afterEach(() => vi.useRealTimers());

    it('appends a .sx-toast (+ .sx-overlay) with the message text', () => {
        showToast('boom');
        const el = document.querySelector('.sx-toast');
        expect(el).not.toBeNull();
        expect(el.classList.contains('sx-overlay')).toBe(true);
        expect(el.textContent).toBe('boom');
        expect(el.getAttribute('role')).toBe('status');
    });

    it('auto-dismisses after the duration', () => {
        showToast('boom', { duration: 1000 });
        expect(document.querySelector('.sx-toast')).not.toBeNull();
        vi.advanceTimersByTime(1000);
        expect(document.querySelector('.sx-toast')).toBeNull();
    });
});
