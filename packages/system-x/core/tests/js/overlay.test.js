import { describe, it, expect } from 'vitest';
import { placeOverlay } from '../../resources/js/system-x/_overlay.js';

const vp = { width: 1000, height: 800 };
const anchor = (left, top, w = 80, h = 24) => ({ left, top, right: left + w, bottom: top + h, width: w, height: h });

describe('placeOverlay (side=below)', () => {
    it('sits directly below-left of the anchor when it fits', () => {
        expect(placeOverlay(anchor(100, 100), { width: 160, height: 200 }, vp)).toEqual({ left: 100, top: 124 });
    });
    it('flips above when it would overflow the bottom and there is room above', () => {
        const r = placeOverlay(anchor(100, 700), { width: 160, height: 200 }, vp);
        expect(r.top).toBe(500); // 700 - 200
    });
    it('shifts left when it would overflow the right edge', () => {
        const r = placeOverlay(anchor(900, 100), { width: 160, height: 200 }, vp);
        expect(r.left).toBe(840); // 1000 - 160
    });
    it('never returns negative left/top (degenerate: popup bigger than viewport)', () => {
        const r = placeOverlay(anchor(10, 10), { width: 1200, height: 900 }, vp);
        expect(r.left).toBeGreaterThanOrEqual(0);
        expect(r.top).toBeGreaterThanOrEqual(0);
    });
});
