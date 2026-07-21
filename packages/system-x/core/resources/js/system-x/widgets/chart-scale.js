// Pure scale + geometry helpers for the Chart widget. No DOM. All render coordinates derive from
// these + the pinned constants, so render tests are reproducible.
export const COORD_W = 600;
export const MARGINS = { top: 8, right: 16, bottom: 28, left: 44 };
export const TARGET_TICKS = 5;

export function plotArea(height) {
    return {
        x0: MARGINS.left,
        x1: COORD_W - MARGINS.right,
        y0: MARGINS.top,
        y1: height - MARGINS.bottom,
    };
}

// "nice" 1-2-5 scale: expands [min,max] to round tick boundaries at ~TARGET_TICKS intervals.
export function niceScale(min, max, targetTicks = TARGET_TICKS) {
    if (max <= min) max = min + 1;                 // flat data -> a unit range (no zero-division)
    const rawStep = (max - min) / targetTicks;
    const mag = Math.pow(10, Math.floor(Math.log10(rawStep)));
    const norm = rawStep / mag;
    const step = mag * (norm <= 1 ? 1 : norm <= 2 ? 2 : norm <= 5 ? 5 : 10);
    const niceMin = Math.floor(min / step) * step;
    const niceMax = Math.ceil(max / step) * step;
    const ticks = [];
    for (let v = niceMin; v <= niceMax + step * 1e-9; v += step) ticks.push(Number(v.toFixed(10)));
    return { min: niceMin, max: niceMax, step, ticks };
}

export function yForValue(value, scale, plot) {
    const frac = (value - scale.min) / (scale.max - scale.min);
    return plot.y1 - frac * (plot.y1 - plot.y0);
}

// Centre of category band i, when there are n bands spanning the plot width. Used for line/area
// point x and as the bar-band centre.
export function xBandCenter(i, n, plot) {
    const band = (plot.x1 - plot.x0) / n;
    return plot.x0 + band * (i + 0.5);
}
