import { describe, it, expect } from 'vitest';
import { chartRenderer } from '../../resources/js/system-x/widgets/chart.js';
import { plotArea, niceScale, yForValue, xBandCenter } from '../../resources/js/system-x/widgets/chart-scale.js';

const node = (props) => ({ type: 'chart', id: 'c1', props: { type: 'line', categories: [], series: [], height: 220, ...props } });

describe('chart scaffold', () => {
    it('creates a .sx-chart wrapper containing an svg with the pinned viewBox', () => {
        const el = chartRenderer.create(node({ categories: ['a', 'b'], series: [{ label: 'S', data: [0, 15] }] }));
        expect(el.className).toContain('sx-chart');
        const svg = el.querySelector('svg');
        expect(svg.getAttribute('viewBox')).toBe('0 0 600 220');
    });

    it('draws a y gridline + tick label per nice tick (0,5,10,15 for max 15)', () => {
        const el = chartRenderer.create(node({ categories: ['a', 'b'], series: [{ label: 'S', data: [0, 15] }] }));
        expect(el.querySelectorAll('.sx-chart-gridline').length).toBe(4);
        const labels = [...el.querySelectorAll('.sx-chart-ytick')].map((t) => t.textContent);
        expect(labels).toEqual(['0', '5', '10', '15']);
    });

    it('draws an x category label per category', () => {
        const el = chartRenderer.create(node({ categories: ['09:00', '10:00'], series: [{ label: 'S', data: [1, 2] }] }));
        const xs = [...el.querySelectorAll('.sx-chart-xtick')].map((t) => t.textContent);
        expect(xs).toEqual(['09:00', '10:00']);
    });

    it('renders a "No data" message when there are no series or categories', () => {
        const el = chartRenderer.create(node({ categories: [], series: [] }));
        expect(el.querySelector('.sx-chart-empty').textContent).toMatch(/no data/i);
        expect(el.querySelector('.sx-chart-gridline')).toBeNull();
    });
});

describe('chart line/area series (Task 4)', () => {
    it('draws a <path class="sx-chart-line"> per series with points at the pinned scale/xBand coords', () => {
        const categories = ['a', 'b'];
        const series = [
            { label: 'S1', data: [0, 15] },
            { label: 'S2', data: [3, 9] },
        ];
        const el = chartRenderer.create(node({ type: 'line', categories, series, height: 220 }));

        const lines = el.querySelectorAll('.sx-chart-line');
        expect(lines.length).toBe(2);

        const plot = plotArea(220);
        const scale = niceScale(0, 15);
        const x0 = xBandCenter(0, 2, plot);
        const x1 = xBandCenter(1, 2, plot);
        const y0 = yForValue(0, scale, plot);
        const y1 = yForValue(15, scale, plot);

        // pinned per the spec: categories ['a','b'] + data [0,15] @ height 220
        expect(x0).toBe(179);
        expect(y0).toBe(192);
        expect(x1).toBe(449);
        expect(y1).toBe(8);

        expect(lines[0].getAttribute('d')).toBe(`M ${x0} ${y0} L ${x1} ${y1}`);
    });

    it('breaks the path into a fresh M subpath after a null gap in the series data', () => {
        const categories = ['a', 'b', 'c'];
        const series = [{ label: 'S1', data: [1, null, 3] }];
        const el = chartRenderer.create(node({ type: 'line', categories, series, height: 220 }));

        const plot = plotArea(220);
        const scale = niceScale(0, 3);
        const x0 = xBandCenter(0, 3, plot);
        const x2 = xBandCenter(2, 3, plot);
        const y0 = yForValue(1, scale, plot);
        const y2 = yForValue(3, scale, plot);

        const line = el.querySelector('.sx-chart-line');
        const d = line.getAttribute('d');
        expect(d).toBe(`M ${x0} ${y0} M ${x2} ${y2}`);
        // two subpaths (fresh M after the gap), not one continuous line through it
        expect(d.match(/M/g).length).toBe(2);
    });

    it('type: area renders both a fill path (closed to the baseline) and a line path per series', () => {
        const categories = ['a', 'b'];
        const series = [
            { label: 'S1', data: [0, 15] },
            { label: 'S2', data: [3, 9] },
        ];
        const el = chartRenderer.create(node({ type: 'area', categories, series, height: 220 }));

        const areas = el.querySelectorAll('.sx-chart-area');
        const lines = el.querySelectorAll('.sx-chart-line');
        expect(areas.length).toBe(2);
        expect(lines.length).toBe(2);

        const plot = plotArea(220);
        const scale = niceScale(0, 15);
        const x0 = xBandCenter(0, 2, plot);
        const x1 = xBandCenter(1, 2, plot);
        const y0 = yForValue(0, scale, plot);
        const y1 = yForValue(15, scale, plot);
        const yBase = yForValue(scale.min, scale, plot);

        expect(areas[0].getAttribute('d')).toBe(`M ${x0} ${y0} L ${x1} ${y1} L ${x1} ${yBase} L ${x0} ${yBase} Z`);

        // the fill is appended before the line so the line draws on top
        const paths = [...el.querySelectorAll('svg > path')];
        expect(paths.indexOf(areas[0])).toBeLessThan(paths.indexOf(lines[0]));
    });

    it('colours each series by palette index (i % 6), wrapping a 7th series back to series-0', () => {
        const categories = ['a', 'b'];
        const series = Array.from({ length: 7 }, (_, i) => ({ label: `S${i}`, data: [i, i + 1] }));
        const el = chartRenderer.create(node({ type: 'line', categories, series, height: 220 }));

        const lines = el.querySelectorAll('.sx-chart-line');
        expect(lines.length).toBe(7);
        expect(lines[0].classList.contains('sx-chart-series-0')).toBe(true);
        expect(lines[1].classList.contains('sx-chart-series-1')).toBe(true);
        expect(lines[6].classList.contains('sx-chart-series-0')).toBe(true);
    });
});

describe('chart bar series (Task 5)', () => {
    it('draws C*S grouped <rect class="sx-chart-bar sx-chart-series-{s%6}"> at the pinned band/baseline coords', () => {
        const categories = ['a', 'b'];
        const series = [
            { label: 'X', data: [10, 20] },
            { label: 'Y', data: [5, 0] },
        ];
        const el = chartRenderer.create(node({ type: 'bar', categories, series, height: 220 }));

        const rects = el.querySelectorAll('.sx-chart-bar');
        expect(rects.length).toBe(4);

        const plot = plotArea(220);
        const scale = niceScale(0, 20);
        const band = (plot.x1 - plot.x0) / categories.length;
        const barW = (band * 0.8) / series.length;
        const baseY = yForValue(0, scale, plot);

        // spot-check category 'a' (i=0), series X (si=0, value 10)
        const bandStart = plot.x0 + band * 0;
        const expectedX = bandStart + band * 0.1 + 0 * barW;
        const vY = yForValue(10, scale, plot);
        const expectedY = Math.min(baseY, vY);
        const expectedHeight = Math.abs(baseY - vY);

        const rectA_X = [...rects].find(
            (r) => r.classList.contains('sx-chart-series-0') && Number(r.getAttribute('x')) === expectedX
        );
        expect(rectA_X).toBeTruthy();
        expect(Number(rectA_X.getAttribute('y'))).toBeCloseTo(expectedY);
        expect(Number(rectA_X.getAttribute('width'))).toBeCloseTo(barW);
        expect(Number(rectA_X.getAttribute('height'))).toBeCloseTo(expectedHeight);
        expect(rectA_X.classList.contains('sx-chart-bar')).toBe(true);
    });

    it('skips a null value in bar data (no rect emitted for that slot)', () => {
        const categories = ['a', 'b'];
        const series = [{ label: 'X', data: [10, null] }];
        const el = chartRenderer.create(node({ type: 'bar', categories, series, height: 220 }));

        const rects = el.querySelectorAll('.sx-chart-bar');
        // C*S = 2, minus the 1 null = 1
        expect(rects.length).toBe(1);
        expect(rects[0].classList.contains('sx-chart-series-0')).toBe(true);
    });

    it('negative-safe: a negative value bars from the zero baseline downward', () => {
        const categories = ['a'];
        const series = [
            { label: 'X', data: [8] },
            { label: 'Y', data: [-5] },
        ];
        const el = chartRenderer.create(node({ type: 'bar', categories, series, height: 220 }));

        const plot = plotArea(220);
        const scale = niceScale(-5, 8);
        const band = (plot.x1 - plot.x0) / categories.length;
        const barW = (band * 0.8) / series.length;
        const baseY = yForValue(0, scale, plot);
        const vY = yForValue(-5, scale, plot);

        // -5 is below 0 on-screen, so its y sits lower (larger) than the baseline y
        expect(vY).toBeGreaterThan(baseY);

        const bandStart = plot.x0 + band * 0;
        const expectedX = bandStart + band * 0.1 + 1 * barW; // series Y is si=1

        const rectY = [...el.querySelectorAll('.sx-chart-bar')].find(
            (r) => r.classList.contains('sx-chart-series-1') && Number(r.getAttribute('x')) === expectedX
        );
        expect(rectY).toBeTruthy();
        expect(Number(rectY.getAttribute('y'))).toBeCloseTo(baseY);
        expect(Number(rectY.getAttribute('height'))).toBeCloseTo(vY - baseY);
    });
});

describe('chart hover tooltip + legend (Task 6)', () => {
    it('hover over a category shows a tooltip with the category + each series value', () => {
        const el = chartRenderer.create(node({ categories: ['09:00', '10:00'], series: [
            { label: 'Reads', data: [12, 15] }, { label: 'Faults', data: [1, 0] } ] }));
        document.body.appendChild(el);
        const svg = el.querySelector('svg');
        svg.dispatchEvent(new MouseEvent('mousemove', { bubbles: true, clientX: 0, clientY: 0 }));
        const tip = el.querySelector('.sx-chart-tooltip');
        expect(tip).not.toBeNull();
        expect(tip.textContent).toContain('09:00');
        expect(tip.textContent).toContain('Reads');
        expect(tip.textContent).toContain('12');
        el.remove();
    });

    it('THE GUARDRAIL: repeated update() does not stack hover listeners', () => {
        const el = chartRenderer.create(node({ categories: ['a'], series: [{ label: 'S', data: [1] }] }));
        document.body.appendChild(el);
        for (let i = 0; i < 5; i++) chartRenderer.update(el, node({ categories: ['a'], series: [{ label: 'S', data: [i] }] }));
        const svg = el.querySelector('svg');
        svg.dispatchEvent(new MouseEvent('mousemove', { bubbles: true }));
        expect(el.querySelectorAll('.sx-chart-tooltip').length).toBe(1);
        el.remove();
    });

    it('after 5 updates a mousemove shows the LATEST data (update refreshed the stashed ref, not a stale closure)', () => {
        const el = chartRenderer.create(node({ categories: ['a'], series: [{ label: 'S', data: [1] }] }));
        document.body.appendChild(el);
        for (let i = 0; i < 5; i++) chartRenderer.update(el, node({ categories: ['a'], series: [{ label: 'S', data: [i] }] }));
        const svg = el.querySelector('svg');
        svg.dispatchEvent(new MouseEvent('mousemove', { bubbles: true }));
        const tips = el.querySelectorAll('.sx-chart-tooltip');
        expect(tips.length).toBe(1);
        // last update seeded data [4] (i === 4)
        expect(tips[0].textContent).toContain('4');
        el.remove();
    });

    it('mouseleave hides the tooltip', () => {
        const el = chartRenderer.create(node({ categories: ['a'], series: [{ label: 'S', data: [1] }] }));
        document.body.appendChild(el);
        const svg = el.querySelector('svg');
        svg.dispatchEvent(new MouseEvent('mousemove', { bubbles: true }));
        const tip = el.querySelector('.sx-chart-tooltip');
        expect(tip.classList.contains('sx-chart-tooltip-hidden')).toBe(false);
        svg.dispatchEvent(new MouseEvent('mouseleave', { bubbles: true }));
        expect(tip.classList.contains('sx-chart-tooltip-hidden')).toBe(true);
        el.remove();
    });

    it('renders a legend row per series', () => {
        const el = chartRenderer.create(node({ categories: ['a'], series: [{ label: 'Reads', data: [1] }, { label: 'Faults', data: [2] }] }));
        expect([...el.querySelectorAll('.sx-chart-legend-item')].map((i) => i.textContent)).toEqual(['Reads', 'Faults']);
    });

    it('legend swatch carries the series palette class', () => {
        const el = chartRenderer.create(node({ categories: ['a'], series: [{ label: 'Reads', data: [1] }, { label: 'Faults', data: [2] }] }));
        const swatches = [...el.querySelectorAll('.sx-chart-legend-swatch')];
        expect(swatches[0].classList.contains('sx-chart-series-0')).toBe(true);
        expect(swatches[1].classList.contains('sx-chart-series-1')).toBe(true);
    });
});
