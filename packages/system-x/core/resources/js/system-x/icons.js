// The system-x icon set (Plan 5b, D2/S5) -- the ONE source of desktop iconography,
// ported verbatim from the design Icon set (docs/.../components/icons/Icon.jsx GLYPHS):
// 16x16 geometric line glyphs, 1.5px stroke, sharp joins, monochrome currentColor so a
// glyph inherits the surrounding text colour + engrave. Stroke glyphs only -- never mix
// in filled/duotone icons (the design's coherence rule).
//
// icon(name, size) builds a live <svg> element with the named glyph; an UNKNOWN name
// ALWAYS falls back to the generic 'window' glyph (S5 -- a bogus/third-party app icon
// degrades to the generic glyph, never a broken or empty button; mirrors Icon.jsx:35
// `GLYPHS[name] || GLYPHS.window`). There is no server-side icon validation -- the server
// forwards whatever the App declares; this client fallback is the single safety net.

// The glyph INNER markup by name, exactly the design GLYPHS (Icon.jsx:12-32). Each value
// is the SVG inner content (the line/rect/path primitives); icon() wraps it in the shared
// <svg> frame. Kept as a string of inner SVG so we can inject it once per render.
const GLYPHS = {
    launcher: '<rect x="2.5" y="2.5" width="4" height="4" /><rect x="9.5" y="2.5" width="4" height="4" /><rect x="2.5" y="9.5" width="4" height="4" /><rect x="9.5" y="9.5" width="4" height="4" />',
    notes: '<rect x="3.5" y="2.5" width="9" height="11" /><line x1="5.5" y1="5.5" x2="10.5" y2="5.5" /><line x1="5.5" y1="8" x2="10.5" y2="8" /><line x1="5.5" y1="10.5" x2="8.5" y2="10.5" />',
    terminal: '<rect x="2.5" y="3" width="11" height="10" /><polyline points="4.5,6.5 6.5,8 4.5,9.5" /><line x1="8" y1="10" x2="11" y2="10" />',
    folder: '<path d="M2.5 4.5H6.5L8 6H13.5V12.5H2.5Z" />',
    gear: '<circle cx="8" cy="8" r="2.2" /><path d="M8 2.5V4M8 12V13.5M2.5 8H4M12 8H13.5M4.4 4.4L5.5 5.5M10.5 10.5L11.6 11.6M11.6 4.4L10.5 5.5M5.5 10.5L4.4 11.6" />',
    clock: '<circle cx="8" cy="8" r="5.5" /><polyline points="8,4.5 8,8 10.5,9.5" />',
    audit: '<rect x="3.5" y="2.5" width="9" height="11" /><line x1="5.5" y1="5.5" x2="10.5" y2="5.5" /><line x1="5.5" y1="8" x2="10.5" y2="8" /><line x1="5.5" y1="10.5" x2="9" y2="10.5" /><circle cx="11.5" cy="11.5" r="2.6" fill="var(--sx-face,#fff)" /><line x1="13.3" y1="13.3" x2="14.6" y2="14.6" />',
    camera: '<rect x="2.5" y="4.5" width="11" height="8" /><circle cx="8" cy="8.5" r="2.3" /><line x1="5.5" y1="4.5" x2="6.5" y2="3" /><line x1="10.5" y1="4.5" x2="9.5" y2="3" />',
    mail: '<rect x="2.5" y="3.5" width="11" height="9" /><polyline points="2.5,4.5 8,8.5 13.5,4.5" />',
    search: '<circle cx="7" cy="7" r="4" /><line x1="10" y1="10" x2="13.5" y2="13.5" />',
    trash: '<polyline points="3.5,4.5 12.5,4.5" /><path d="M4.5 4.5L5.2 13H10.8L11.5 4.5" /><path d="M6 4.5V3H10V4.5" />',
    info: '<circle cx="8" cy="8" r="5.5" /><line x1="8" y1="7" x2="8" y2="11" /><circle cx="8" cy="4.8" r="0.4" fill="currentColor" />',
    warning: '<path d="M8 2.5L14 13H2Z" /><line x1="8" y1="6.5" x2="8" y2="9.5" /><circle cx="8" cy="11.2" r="0.4" fill="currentColor" />',
    ok: '<polyline points="3,8.5 6.5,12 13,4" />',
    close: '<line x1="3.5" y1="3.5" x2="12.5" y2="12.5" /><line x1="12.5" y1="3.5" x2="3.5" y2="12.5" />',
    window: '<rect x="2.5" y="3" width="11" height="10" /><line x1="2.5" y1="5.5" x2="13.5" y2="5.5" />',
    save: '<path d="M3 3H11.5L13 4.5V13H3Z" /><rect x="5" y="3" width="5" height="3.5" /><rect x="5" y="9" width="6" height="2.5" />',
    user: '<circle cx="8" cy="5.5" r="2.5" /><path d="M3.5 13C3.5 10.2 5.5 9 8 9C10.5 9 12.5 10.2 12.5 13" />',
    chevronRight: '<polyline points="6,4 10,8 6,12" />',
};

const SVG_NS = 'http://www.w3.org/2000/svg';

// Build a live <svg> element for the named glyph (fallback 'window' for an unknown name).
// The shared frame mirrors the design Icon: viewBox 0 0 16 16, currentColor stroke, no fill,
// square caps / miter joins, aria-hidden. size sets width/height in px (default 16).
export function icon(name = 'window', size = 16, strokeWidth = 1.5) {
    const svg = document.createElementNS(SVG_NS, 'svg');
    svg.setAttribute('width', String(size));
    svg.setAttribute('height', String(size));
    svg.setAttribute('viewBox', '0 0 16 16');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', String(strokeWidth));
    svg.setAttribute('stroke-linecap', 'square');
    svg.setAttribute('stroke-linejoin', 'miter');
    svg.setAttribute('aria-hidden', 'true');
    svg.innerHTML = GLYPHS[name] || GLYPHS.window; // S5: unknown -> the generic window glyph
    return svg;
}

export const ICON_NAMES = Object.keys(GLYPHS);
