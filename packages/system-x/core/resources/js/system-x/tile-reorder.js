// A contained tile drag-reorder helper for the launcher (Plan 4a). Grid-index reorder only -- no
// snap zones, no surface transforms (the window drag loop in window-manager.js does NOT transfer).
// pointerdown-hold on a tile -> drag -> drop reorders within the CURRENT container (the root grid or
// the open folder shelf). On drop it fires onReorder(fromIndex, toIndex); the launcher applies it to
// this.layout / the folder apps + persists.

// Pure: given the DOM-order rects of the container's tiles + a pointer position, return the insertion
// index (0..n). Reading order: a tile counts as "before" the pointer if it's on an earlier row, or
// on the same row and its horizontal centre is left of the pointer.
export function dropIndexFor(rects, x, y) {
    let idx = 0;
    for (const r of rects) {
        const cx = (r.left + r.right) / 2;
        if (r.bottom < y) { // an entire earlier row
            idx += 1;
            continue;
        }
        const sameRow = y >= r.top && y <= r.bottom;
        if (sameRow && cx < x) {
            idx += 1;
        }
    }
    return idx;
}

// Pure: onto-vs-between hit test (Slice 4a discoverability -- the Launchpad group gesture). If the
// pointer sits in a tile's CENTRAL band (the middle half horizontally, anywhere vertically within
// the tile), the drop lands ONTO that tile (group it); otherwise it's a between-tiles insertion,
// deferring to dropIndexFor. The 25% edge margins keep near-edge drags as reorders, not accidental groups.
export function dropTargetFor(rects, x, y) {
    for (let i = 0; i < rects.length; i += 1) {
        const r = rects[i];
        const w = r.right - r.left;
        const band = w * 0.25;
        if (x >= r.left + band && x <= r.right - band && y >= r.top && y <= r.bottom) {
            return { kind: 'onto', index: i };
        }
    }
    return { kind: 'between', index: dropIndexFor(rects, x, y) };
}

// Make the direct-child tiles of `container` reorderable. `selector` matches a draggable tile;
// onReorder(from, to) is called on a settled between-tiles drop where to !== from. onOnto(from, onto)
// fires when the drop lands on a DIFFERENT tile's centre (the group gesture -- only the root grid
// passes it). Uses pointer capture + a small move threshold so a click still fires normally (the
// tile's own click handler owns launch/open).
export function makeReorderable(container, { selector = '.sx-launcher-tile', onReorder = () => {}, onOnto = () => {} } = {}) {
    container.addEventListener('pointerdown', (e) => {
        const tile = e.target.closest(selector);
        if (!tile || !container.contains(tile)) {
            return;
        }
        const tiles = () => [...container.querySelectorAll(`:scope > ${selector}`)];
        const from = tiles().indexOf(tile);
        if (from < 0) {
            return;
        }

        const originX = e.clientX;
        const originY = e.clientY;
        let dragging = false;
        let highlighted = null; // the tile currently wearing the drop-target ring (at most one)
        const pointerId = e.pointerId;

        const clearHighlight = () => {
            highlighted?.classList.remove('sx-launcher-tile-drop-target');
            highlighted = null;
        };

        const onMove = (ev) => {
            if (ev.pointerId !== pointerId) {
                return;
            }
            if (!dragging) {
                if (Math.abs(ev.clientX - originX) + Math.abs(ev.clientY - originY) < 6) {
                    return; // below the threshold -- still a click
                }
                dragging = true;
                tile.classList.add('sx-launcher-tile-dragging');
                tile.setPointerCapture?.(pointerId);
            }
            // Live drop cue: ring the tile we'd land ONTO (a different tile -> group gesture). Only
            // one target lit at a time; between-tiles/self clears it. Eyeballed in Dusk (no jsdom layout).
            const list = tiles();
            const rects = list.map((t) => t.getBoundingClientRect());
            const target = dropTargetFor(rects, ev.clientX, ev.clientY);
            if (target.kind === 'onto' && target.index !== from) {
                const next = list[target.index];
                if (next !== highlighted) {
                    clearHighlight();
                    next?.classList.add('sx-launcher-tile-drop-target');
                    highlighted = next;
                }
            } else {
                clearHighlight();
            }
        };

        const onUp = (ev) => {
            container.removeEventListener('pointermove', onMove);
            window.removeEventListener('pointerup', onUp);
            clearHighlight();
            if (!dragging) {
                return; // a plain click -- let the tile's click handler run
            }
            tile.classList.remove('sx-launcher-tile-dragging');
            tile.releasePointerCapture?.(pointerId);
            const rects = tiles().map((t) => t.getBoundingClientRect());
            const target = dropTargetFor(rects, ev.clientX, ev.clientY);
            if (target.kind === 'onto' && target.index !== from) {
                onOnto(from, target.index);
                return;
            }
            let to = target.index;
            // Removing `from` before re-inserting shifts indices after it.
            if (to > from) {
                to -= 1;
            }
            if (to !== from && to >= 0) {
                onReorder(from, to);
            }
        };

        container.addEventListener('pointermove', onMove);
        window.addEventListener('pointerup', onUp);
    });
}
