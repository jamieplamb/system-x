import { describe, it, expect, beforeEach } from 'vitest';
import { createFocusTrap } from '../../resources/js/system-x/_focus-trap.js';

function panelWith(...ids) {
    const panel = document.createElement('div');
    for (const id of ids) {
        const b = document.createElement('button');
        b.id = id;
        b.textContent = id;
        panel.appendChild(b);
    }
    document.body.appendChild(panel);
    return panel;
}

describe('focus-trap', () => {
    beforeEach(() => { document.body.replaceChildren(); });

    it('moves focus to the first focusable on activate', () => {
        const panel = panelWith('a', 'b');
        createFocusTrap(panel);
        expect(document.activeElement.id).toBe('a');
    });

    it('wraps Tab from last back to first and Shift+Tab from first to last', () => {
        const panel = panelWith('a', 'b');
        createFocusTrap(panel);
        document.getElementById('b').focus();
        panel.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', bubbles: true }));
        expect(document.activeElement.id).toBe('a');
        document.getElementById('a').focus();
        panel.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', shiftKey: true, bubbles: true }));
        expect(document.activeElement.id).toBe('b');
    });

    it('restores focus to the previously-focused element on release', () => {
        const outside = document.createElement('button');
        document.body.appendChild(outside);
        outside.focus();
        const panel = panelWith('a');
        const trap = createFocusTrap(panel);
        expect(document.activeElement.id).toBe('a');
        trap.release();
        expect(document.activeElement).toBe(outside);
    });
});
