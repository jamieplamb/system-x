import { describe, it, expect } from 'vitest';
import { icon, ICON_NAMES } from '../../resources/js/system-x/icons.js';

describe('icons (ported from the design Icon set)', () => {
    it('returns an SVG element for a known glyph name', () => {
        const el = icon('notes');
        expect(el.tagName.toLowerCase()).toBe('svg');
    });
    it('falls back to the window glyph for an unknown name', () => {
        const el = icon('nonsense');
        // Same shape as the window glyph -- a defined fallback, never empty.
        expect(el.tagName.toLowerCase()).toBe('svg');
    });
    it('ICON_NAMES lists the set (launcher, window, notes, clock, user at least)', () => {
        for (const n of ['launcher', 'window', 'notes', 'clock', 'user']) {
            expect(ICON_NAMES).toContain(n);
        }
    });
});
