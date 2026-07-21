import { describe, it, expect } from 'vitest';
import { niceScale, plotArea, yForValue, xBandCenter } from '../../resources/js/system-x/widgets/chart-scale.js';

describe('niceScale (1-2-5 ramp, TARGET_TICKS=5)', () => {
    it('0..15 -> step 5, ticks [0,5,10,15]', () => {
        expect(niceScale(0, 15)).toEqual({ min: 0, max: 15, step: 5, ticks: [0, 5, 10, 15] });
    });
    it('0..8 -> step 2, ticks [0,2,4,6,8]', () => {
        expect(niceScale(0, 8)).toEqual({ min: 0, max: 8, step: 2, ticks: [0, 2, 4, 6, 8] });
    });
    it('flat data (0..0) -> a unit range, never a zero-division', () => {
        const s = niceScale(0, 0);
        expect(s.max).toBeGreaterThan(s.min);
        expect(s.step).toBeGreaterThan(0);
    });
    it('negatives: -3..12 includes 0 in the domain', () => {
        const s = niceScale(-3, 12);
        expect(s.min).toBeLessThanOrEqual(-3);
        expect(s.max).toBeGreaterThanOrEqual(12);
        expect(s.ticks).toContain(0);
    });
});

describe('plotArea (pinned constants COORD_W=600, MARGINS)', () => {
    it('height 220 -> x0=44,x1=584,y0=8,y1=192', () => {
        expect(plotArea(220)).toEqual({ x0: 44, x1: 584, y0: 8, y1: 192 });
    });
});

describe('yForValue', () => {
    it('maps scale.min -> y1 (bottom) and scale.max -> y0 (top)', () => {
        const p = plotArea(220);
        const s = niceScale(0, 15);
        expect(yForValue(0, s, p)).toBeCloseTo(192);
        expect(yForValue(15, s, p)).toBeCloseTo(8);
        expect(yForValue(7.5, s, p)).toBeCloseTo(100);
    });
});

describe('xBandCenter', () => {
    it('centres category i in its band across the plot width', () => {
        const p = plotArea(220);
        expect(xBandCenter(0, 2, p)).toBeCloseTo(179);   // 44 + 135
        expect(xBandCenter(1, 2, p)).toBeCloseTo(449);   // 44 + 405
    });
});
