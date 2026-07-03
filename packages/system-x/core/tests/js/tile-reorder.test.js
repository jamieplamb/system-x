import { describe, it, expect } from 'vitest';
import { dropIndexFor } from '../../resources/js/system-x/tile-reorder.js';
import { Launcher } from '../../resources/js/system-x/launcher.js';

describe('dropIndexFor (pure index math, injected rects)', () => {
    // A single row of 3 tiles, each 80px wide at y 0..40.
    const rects = [
        { left: 0, right: 80, top: 0, bottom: 40 },
        { left: 80, right: 160, top: 0, bottom: 40 },
        { left: 160, right: 240, top: 0, bottom: 40 },
    ];

    it('pointer before the first tile centre -> index 0', () => {
        expect(dropIndexFor(rects, 10, 20)).toBe(0);
    });

    it('pointer past the first tile centre, before the second -> index 1', () => {
        expect(dropIndexFor(rects, 100, 20)).toBe(1);
    });

    it('pointer past the last centre -> index 3 (append)', () => {
        expect(dropIndexFor(rects, 230, 20)).toBe(3);
    });
});

describe('launcher reorder mutations', () => {
    const apps = [
        { slug: 'hello', title: 'Hello', icon: 'window' },
        { slug: 'notes', title: 'Notes', icon: 'notes' },
        { slug: 'controls', title: 'Controls', icon: 'gear' },
    ];

    it('reorderRoot moves a root item from one index to another and persists', () => {
        const saved = [];
        document.body.innerHTML = '';
        const l = new Launcher(document.body, {
            apps, onPick() {},
            layout: [
                { type: 'app', slug: 'hello' },
                { type: 'app', slug: 'notes' },
                { type: 'app', slug: 'controls' },
            ],
            transport: { saveLayout: (d) => { saved.push(structuredClone(d)); return Promise.resolve(); } },
        });
        l.reorderRoot(0, 2); // move hello to the end
        expect(l.layout.map((i) => i.slug)).toEqual(['notes', 'controls', 'hello']);
        expect(saved.length).toBeGreaterThan(0);
    });

    it('reorderFolder reorders apps within a folder', () => {
        document.body.innerHTML = '';
        const l = new Launcher(document.body, {
            apps, onPick() {},
            layout: [{ type: 'folder', id: 'f1', name: 'T', apps: ['hello', 'notes', 'controls'] }],
            transport: { saveLayout: () => Promise.resolve() },
        });
        l.reorderFolder('f1', 2, 0); // move controls to the front
        expect(l.layout.find((i) => i.id === 'f1').apps).toEqual(['controls', 'hello', 'notes']);
    });
});

import { dropTargetFor } from '../../resources/js/system-x/tile-reorder.js';

describe('dropTargetFor (onto vs between)', () => {
    const rects = [
        { left: 0, right: 80, top: 0, bottom: 40 },
        { left: 80, right: 160, top: 0, bottom: 40 },
    ];
    it('pointer over a tile centre -> onto that tile', () => {
        expect(dropTargetFor(rects, 40, 20)).toEqual({ kind: 'onto', index: 0 });
    });
    it('pointer near a tile edge -> between (an insertion index)', () => {
        const t = dropTargetFor(rects, 78, 20);
        expect(t.kind).toBe('between');
        expect(typeof t.index).toBe('number');
    });
    it('pointer past all tiles -> between (append)', () => {
        const t = dropTargetFor(rects, 200, 20);
        expect(t.kind).toBe('between');
    });
});
