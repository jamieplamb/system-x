import { describe, it, expect, beforeEach, vi } from 'vitest';
import { applyPref, interceptPrefClick, cycleWallpaper, seedPressed } from '../../resources/js/system-x/prefs.js';

function buildDesktop() {
    document.documentElement.removeAttribute('data-sx-theme');
    document.documentElement.removeAttribute('data-sx-accent');
    document.documentElement.removeAttribute('data-sx-panel');
    const desktop = document.createElement('div');
    desktop.id = 'sx-desktop';
    desktop.className = 'sx-desktop';
    document.body.replaceChildren(desktop);
    return desktop;
}

describe('prefs apply (D2/D3 -- instant client attribute on the right root)', () => {
    let desktop;
    beforeEach(() => { desktop = buildDesktop(); });

    it('applyPref theme/accent/panel set the attribute on <html>', () => {
        applyPref('theme', 'pewter');
        applyPref('accent', 'amber');
        applyPref('panel', 'bottom');
        expect(document.documentElement.dataset.sxTheme).toBe('pewter');
        expect(document.documentElement.dataset.sxAccent).toBe('amber');
        expect(document.documentElement.dataset.sxPanel).toBe('bottom');
    });

    it('applyPref wallpaper sets the attribute on #sx-desktop (it owns the background)', () => {
        applyPref('wallpaper', 'grid');
        expect(desktop.dataset.sxWallpaper).toBe('grid');
        // NOT on <html> -- the wallpaper background lives on #sx-desktop (D3).
        expect(document.documentElement.dataset.sxWallpaper).toBeUndefined();
    });

    it('cycleWallpaper steps gradient -> grid -> lines -> solid -> gradient', () => {
        desktop.dataset.sxWallpaper = 'gradient';
        cycleWallpaper(); expect(desktop.dataset.sxWallpaper).toBe('grid');
        cycleWallpaper(); expect(desktop.dataset.sxWallpaper).toBe('lines');
        cycleWallpaper(); expect(desktop.dataset.sxWallpaper).toBe('solid');
        cycleWallpaper(); expect(desktop.dataset.sxWallpaper).toBe('gradient');
    });

    it('cycleWallpaper persists the next style fire-and-forget (the menu cycle must stick)', () => {
        desktop.dataset.sxWallpaper = 'gradient';
        const save = vi.fn();
        const next = cycleWallpaper(save);
        expect(next).toBe('grid');
        expect(save).toHaveBeenCalledWith('wallpaper', 'grid'); // forwards to the transport POST
    });
});

describe('seedPressed (B1/D5 -- the client seeds the pressed-state from the live root)', () => {
    let desktop;
    beforeEach(() => { desktop = buildDesktop(); });

    it('presses the control matching the live root attribute (the App seeds none)', () => {
        // The no-flash boot stamped the live truth on the root; the static App emits buttons
        // with NO pressed cue. seedPressed reads the root + presses the matching control.
        document.documentElement.dataset.sxTheme = 'pewter';
        const pewter = document.createElement('button');
        pewter.setAttribute('data-sx-pref', 'theme:pewter');
        const modern = document.createElement('button');
        modern.setAttribute('data-sx-pref', 'theme:modern');
        desktop.append(pewter, modern);

        seedPressed();

        expect(pewter.dataset.sxPressed).toBe('true');
        expect(modern.dataset.sxPressed).toBe('false');
    });
});

describe('the [data-sx-pref] interceptor (D5 -- client chrome, not a round-trip)', () => {
    let desktop, posted, wm;
    beforeEach(() => {
        desktop = buildDesktop();
        posted = [];
        // savePref is fire-and-forget -- stub it so the test doesn't hit the network.
        wm = { setPanelPosition: vi.fn() };
    });

    it('a pref-button click applies + persists + (for panel) calls the WM setter', () => {
        const btn = document.createElement('button');
        btn.setAttribute('data-sx-pref', 'theme:pewter');
        desktop.appendChild(btn);

        // interceptPrefClick takes a save fn (the transport POST) so the test can capture it.
        const e = { target: btn };
        interceptPrefClick(e, wm, (k, v) => posted.push([k, v]));

        expect(document.documentElement.dataset.sxTheme).toBe('pewter'); // applied instantly
        expect(posted).toEqual([['theme', 'pewter']]);                    // persisted
    });

    it('a panel pref also drives the WM panel setter (D6)', () => {
        const btn = document.createElement('button');
        btn.setAttribute('data-sx-pref', 'panel:bottom');
        desktop.appendChild(btn);

        interceptPrefClick({ target: btn }, wm, () => {});

        expect(document.documentElement.dataset.sxPanel).toBe('bottom');
        expect(wm.setPanelPosition).toHaveBeenCalledWith('bottom');
    });

    it('a click NOT on a [data-sx-pref] element is ignored', () => {
        const plain = document.createElement('button');
        desktop.appendChild(plain);
        interceptPrefClick({ target: plain }, wm, () => { throw new Error('must not post'); });
        expect(document.documentElement.dataset.sxTheme).toBeUndefined();
    });
});
