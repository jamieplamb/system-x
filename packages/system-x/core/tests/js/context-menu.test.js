import { describe, it, expect, beforeEach, vi } from 'vitest';
import { ContextMenu } from '../../resources/js/system-x/context-menu.js';

function buildDesktop() {
    const mount = document.createElement('div');
    mount.id = 'sx-desktop';
    mount.className = 'sx-desktop';
    const surface = document.createElement('div');
    surface.className = 'sx-window-surface';
    surface.dataset.windowId = 'hello';
    mount.appendChild(surface);
    document.body.replaceChildren(mount);
    return { mount, surface };
}

describe('ContextMenu (D7 -- bare-desktop right-click only)', () => {
    let mount, surface, opened, cycled, menu;
    beforeEach(() => {
        ({ mount, surface } = buildDesktop());
        opened = [];
        cycled = 0;
        menu = new ContextMenu({ onOpenApp: (slug) => opened.push(slug), onCycleWallpaper: () => cycled++ });
        menu.attach(mount);
    });

    function rightClick(target) {
        const e = new MouseEvent('contextmenu', { bubbles: true, cancelable: true, clientX: 120, clientY: 90 });
        Object.defineProperty(e, 'target', { value: target });
        target.dispatchEvent(e);
        return e;
    }

    it('opens on a bare-desktop right-click + prevents the native menu', () => {
        const e = rightClick(mount);
        expect(menu.isOpen()).toBe(true);
        expect(e.defaultPrevented).toBe(true);
    });

    it('does NOT open on a right-click over a window (the native menu passes through)', () => {
        const e = rightClick(surface);
        expect(menu.isOpen()).toBe(false);
        expect(e.defaultPrevented).toBe(false);
    });

    it('Open Appearance calls onOpenApp("appearance") + closes', () => {
        rightClick(mount);
        menu.el.querySelector('[data-sx-menu="appearance"]').click();
        expect(opened).toEqual(['appearance']);
        expect(menu.isOpen()).toBe(false);
    });

    it('Cycle wallpaper calls onCycleWallpaper', () => {
        rightClick(mount);
        menu.el.querySelector('[data-sx-menu="cycle-wallpaper"]').click();
        expect(cycled).toBe(1);
    });

    it('About system-x calls onOpenApp("about")', () => {
        rightClick(mount);
        menu.el.querySelector('[data-sx-menu="about"]').click();
        expect(opened).toEqual(['about']);
    });

    it('an outside click closes it', () => {
        rightClick(mount);
        document.body.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
        expect(menu.isOpen()).toBe(false);
    });
});
