// Hand-rolled SVG chart: frame/axes/gridlines/labels/empty state, line/area/bar series, a hover
// tooltip and a legend. The tooltip is the one stateful bit -- its mousemove/mouseleave listeners
// are bound EXACTLY ONCE in create() and read live data from a stashed ref (el.__sxChart) that
// update() refreshes. update() never binds a listener or recreates the tooltip, so a live chart
// that re-renders on every broadcast doesn't stack listeners on the surviving element.

import { COORD_W, plotArea, niceScale, yForValue, xBandCenter } from './chart-scale.js';

const SVG_NS = 'http://www.w3.org/2000/svg';

function svgEl(tag, attrs = {}) {
    const e = document.createElementNS(SVG_NS, tag);
    for (const [key, value] of Object.entries(attrs)) {
        e.setAttribute(key, value);
    }
    return e;
}

// Min/max across all non-null numbers in every series, with the y-axis floor pinned at 0.
// Returns null when there's nothing numeric to plot.
function dataExtent(series) {
    const nums = [];
    for (const s of series ?? []) {
        for (const v of s.data ?? []) {
            if (typeof v === 'number' && Number.isFinite(v)) {
                nums.push(v);
            }
        }
    }
    if (nums.length === 0) return null;
    return { min: Math.min(0, ...nums), max: Math.max(...nums) };
}

function buildEmpty(el) {
    const empty = document.createElement('div');
    empty.className = 'sx-chart-empty';
    empty.textContent = 'No data';
    el.appendChild(empty);
}

// Everything the frame + the tooltip handler need, derived purely from node.props. Both create()
// and update() call this so the stashed ref (el.__sxChart) is refreshed identically each render.
function chartGeometry(node) {
    const { categories = [], series = [] } = node.props;
    const height = node.props.height ?? 220;
    const plot = plotArea(height);
    const extent = dataExtent(series);
    const scale = extent ? niceScale(extent.min, extent.max) : null;
    return { categories, series, height, plot, scale, n: categories.length };
}

function buildFrame(el, node, geom) {
    const { categories, series, height, plot, scale } = geom;

    const svg = svgEl('svg', {
        class: 'sx-chart-svg',
        viewBox: `0 0 ${COORD_W} ${height}`,
    });

    for (const tick of scale.ticks) {
        const y = yForValue(tick, scale, plot);

        const gridline = svgEl('line', {
            class: 'sx-chart-gridline',
            x1: plot.x0,
            y1: y,
            x2: plot.x1,
            y2: y,
        });
        svg.appendChild(gridline);

        const label = svgEl('text', {
            class: 'sx-chart-ytick',
            x: plot.x0 - 8,
            y,
            'text-anchor': 'end',
            'dominant-baseline': 'middle',
        });
        label.textContent = String(tick);
        svg.appendChild(label);
    }

    const n = categories.length;
    categories.forEach((category, i) => {
        const x = xBandCenter(i, n, plot);
        const label = svgEl('text', {
            class: 'sx-chart-xtick',
            x,
            y: plot.y1 + 18,
            'text-anchor': 'middle',
        });
        label.textContent = category;
        svg.appendChild(label);
    });

    // series drawing: Tasks 4-5 (line/area, bar)
    buildSeries(svg, node, plot, scale, categories);

    el.appendChild(svg);

    buildLegend(el, series);
}

// A legend row per series: a colour swatch (palette-classed by index) + the series label. Rebuilt
// on every render alongside the SVG -- it's stateless, so rebuilding it is morph-safe.
function buildLegend(el, series) {
    const legend = document.createElement('div');
    legend.className = 'sx-chart-legend';

    series.forEach((s, si) => {
        const item = document.createElement('div');
        item.className = 'sx-chart-legend-item';

        const swatch = document.createElement('span');
        swatch.className = `sx-chart-legend-swatch sx-chart-series-${si % 6}`;
        item.appendChild(swatch);
        item.appendChild(document.createTextNode(s.label ?? ''));

        legend.appendChild(item);
    });

    el.appendChild(legend);
}

// Turns a series' raw data into per-category points; a null/undefined/non-number leaves a gap
// (null) at that index rather than a point.
function seriesPoints(data, n, plot, scale) {
    return (data ?? []).map((value, i) => {
        if (typeof value === 'number' && Number.isFinite(value)) {
            return { x: xBandCenter(i, n, plot), y: yForValue(value, scale, plot) };
        }
        return null;
    });
}

// Splits a points array (with gaps as null) into contiguous runs of real points, dropping gaps.
function pointRuns(points) {
    const runs = [];
    let current = [];
    for (const point of points) {
        if (point) {
            current.push(point);
        } else if (current.length) {
            runs.push(current);
            current = [];
        }
    }
    if (current.length) runs.push(current);
    return runs;
}

function linePathD(runs) {
    return runs
        .map((run) => `M ${run[0].x} ${run[0].y}` + run.slice(1).map((p) => ` L ${p.x} ${p.y}`).join(''))
        .join(' ');
}

// Fills each contiguous run of points down to the baseline y and closes it -- gaps get their own
// closed shape rather than one path spanning the break.
function areaPathD(runs, yBase) {
    return runs
        .map((run) => {
            const first = run[0];
            const last = run[run.length - 1];
            const line = run.slice(1).map((p) => ` L ${p.x} ${p.y}`).join('');
            return `M ${first.x} ${first.y}${line} L ${last.x} ${yBase} L ${first.x} ${yBase} Z`;
        })
        .join(' ');
}

// Grouped bars within each category band: inner 80% of the band split evenly across series,
// drawn from the zero baseline so positive values sit above it and negatives extend below.
function drawBars(svg, series, categories, plot, scale) {
    const n = categories.length;
    const S = series.length;
    const band = (plot.x1 - plot.x0) / n;
    const barW = (band * 0.8) / S;
    const baseY = yForValue(0, scale, plot);

    series.forEach((s, si) => {
        const data = s.data ?? [];
        const colourClass = `sx-chart-series-${si % 6}`;

        categories.forEach((_, i) => {
            const value = data[i];
            if (typeof value !== 'number' || !Number.isFinite(value)) return;

            const bandStart = plot.x0 + band * i;
            const x = bandStart + band * 0.1 + si * barW;
            const vY = yForValue(value, scale, plot);
            const y = Math.min(baseY, vY);
            const height = Math.abs(baseY - vY);

            const rect = svgEl('rect', {
                class: `sx-chart-bar ${colourClass}`,
                x,
                y,
                width: barW,
                height,
            });
            svg.appendChild(rect);
        });
    });
}

function buildSeries(svg, node, plot, scale, categories) {
    const { type, series = [] } = node.props;
    if (type === 'bar') {
        drawBars(svg, series, categories, plot, scale);
        return;
    }
    if (type !== 'line' && type !== 'area') return;

    const n = categories.length;
    const yBase = yForValue(scale.min, scale, plot);

    series.forEach((s, si) => {
        const runs = pointRuns(seriesPoints(s.data, n, plot, scale));
        const colourClass = `sx-chart-series-${si % 6}`;

        if (type === 'area') {
            const area = svgEl('path', {
                class: `sx-chart-area ${colourClass}`,
                d: areaPathD(runs, yBase),
            });
            svg.appendChild(area);
        }

        const line = svgEl('path', {
            class: `sx-chart-line ${colourClass}`,
            d: linePathD(runs),
        });
        svg.appendChild(line);
    });
}

function hasData(node) {
    const { categories = [], series = [] } = node.props;
    return categories.length > 0 && dataExtent(series) !== null;
}

const clamp = (v, lo, hi) => Math.max(lo, Math.min(hi, v));

// Map a pointer x to the nearest category index. In jsdom offsetX and getBoundingClientRect are
// both 0, so this lands on index 0 -- which is exactly what the hover tests hover for (clientX 0 ->
// the first category). In a real browser the rendered width divides the pointer into n bands.
function categoryIndexFromPointer(e, el, n) {
    if (n <= 0) return -1;
    const width = el.clientWidth || el.getBoundingClientRect().width || 0;
    if (!width) return 0;
    const x = e.offsetX ?? 0;
    return clamp(Math.round((x / width) * n - 0.5), 0, n - 1);
}

// Fill the tooltip from the live stashed geometry at event time -- NOT from a create-time closure.
function fillTooltip(tip, geom, idx) {
    tip.textContent = '';
    if (idx < 0) return;

    const header = document.createElement('div');
    header.className = 'sx-chart-tooltip-category';
    header.textContent = geom.categories[idx] ?? '';
    tip.appendChild(header);

    geom.series.forEach((s, si) => {
        const row = document.createElement('div');
        row.className = 'sx-chart-tooltip-row';

        const swatch = document.createElement('span');
        swatch.className = `sx-chart-tooltip-swatch sx-chart-series-${si % 6}`;
        row.appendChild(swatch);

        const value = (s.data ?? [])[idx];
        row.appendChild(document.createTextNode(`${s.label ?? ''}: ${value ?? ''}`));
        tip.appendChild(row);
    });
}

export const chartRenderer = {
    create(node) {
        const el = document.createElement('div');
        el.className = 'sx-chart';
        el.dataset.sxId = node.id ?? '';
        el.style.position = 'relative';

        const geom = chartGeometry(node);
        el.__sxChart = geom;

        if (hasData(node)) {
            buildFrame(el, node, geom);
        } else {
            buildEmpty(el);
        }

        // The one persistent tooltip node + its listeners. Created and bound ONCE here so repeated
        // update() calls on this same element never stack a listener or spawn a second tooltip.
        const tip = document.createElement('div');
        tip.className = 'sx-chart-tooltip sx-chart-tooltip-hidden';
        tip.style.position = 'absolute';
        tip.style.pointerEvents = 'none';
        el.appendChild(tip);

        // Handlers read el.__sxChart live, so update() only has to refresh that ref -- no rebind.
        el.addEventListener('mousemove', (e) => {
            const g = el.__sxChart;
            if (!g || g.n <= 0) return;
            const idx = categoryIndexFromPointer(e, el, g.n);
            fillTooltip(tip, g, idx);
            tip.classList.remove('sx-chart-tooltip-hidden');
            tip.style.left = `${(e.offsetX ?? 0) + 12}px`;
            tip.style.top = `${(e.offsetY ?? 0) + 12}px`;
        });
        el.addEventListener('mouseleave', () => {
            tip.classList.add('sx-chart-tooltip-hidden');
        });

        return el;
    },

    update(el, node) {
        el.dataset.sxId = node.id ?? '';

        // Refresh the stashed geometry the (already-bound) tooltip handler reads. Binds nothing.
        el.__sxChart = chartGeometry(node);

        // Rebuild only the frame/legend -- keep the persistent tooltip node so we neither recreate
        // it nor drop the listeners bound to el in create().
        const tip = el.querySelector('.sx-chart-tooltip');
        el.querySelector('.sx-chart-svg')?.remove();
        el.querySelector('.sx-chart-legend')?.remove();
        el.querySelector('.sx-chart-empty')?.remove();

        if (hasData(node)) {
            buildFrame(el, node, el.__sxChart);
        } else {
            buildEmpty(el);
        }

        // Keep the tooltip last in the DOM so it paints over the freshly-rebuilt frame.
        if (tip) el.appendChild(tip);
    },
};
