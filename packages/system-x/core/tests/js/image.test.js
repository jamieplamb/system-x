import { describe, it, expect } from 'vitest';
import { imageRenderer } from '../../resources/js/system-x/widgets/image.js';

const node = (props) => ({ type: 'image', id: 'i1', props });

describe('image renderer', () => {
    it('creates an <img> with src, alt, lazy loading', () => {
        const el = imageRenderer.create(node({ src: 'a.jpg', alt: 'A' }));
        expect(el.tagName).toBe('IMG');
        expect(el.getAttribute('src')).toBe('a.jpg');
        expect(el.getAttribute('alt')).toBe('A');
        expect(el.getAttribute('loading')).toBe('lazy');
        expect(el.className).toContain('sx-image');
    });

    it('update swaps src/alt in place', () => {
        const el = imageRenderer.create(node({ src: 'a.jpg', alt: 'A' }));
        imageRenderer.update(el, node({ src: 'b.jpg', alt: 'B' }));
        expect(el.getAttribute('src')).toBe('b.jpg');
        expect(el.getAttribute('alt')).toBe('B');
    });

    it('a broken image gets .sx-image--broken on error and keeps alt', () => {
        const el = imageRenderer.create(node({ src: 'x', alt: 'A' }));
        el.dispatchEvent(new Event('error'));
        expect(el.className).toContain('sx-image--broken');
        expect(el.getAttribute('alt')).toBe('A');
    });

    it('a non-enlargeable image is not focusable and has no zoom affordance', () => {
        const el = imageRenderer.create(node({ src: 'a.jpg', alt: '' }));
        expect(el.className).not.toContain('sx-image--enlargeable');
        expect(el.getAttribute('tabindex')).toBeNull();
    });
});

function enlargeable(extra = {}) {
    return { type: 'image', id: 'i1', props: { src: 'thumb.jpg', alt: 'Plate', enlarge: true, ...extra } };
}

describe('image enlarge lightbox', () => {
    it('an enlargeable image is focusable with a zoom affordance', () => {
        const el = imageRenderer.create(enlargeable());
        expect(el.className).toContain('sx-image--enlargeable');
        expect(el.getAttribute('tabindex')).toBe('0');
        expect(el.getAttribute('role')).toBe('button');
    });

    it('click opens a lightbox showing src (no full), Escape closes it', () => {
        const el = imageRenderer.create(enlargeable());
        document.body.appendChild(el);
        el.click();
        const box = document.querySelector('.sx-lightbox-backdrop');
        expect(box).not.toBeNull();
        expect(box.querySelector('img').getAttribute('src')).toBe('thumb.jpg');
        expect(box.getAttribute('role')).toBe('dialog');
        expect(box.getAttribute('aria-modal')).toBe('true');
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        expect(document.querySelector('.sx-lightbox-backdrop')).toBeNull();
        el.remove();
    });

    it('the lightbox shows full when set', () => {
        const el = imageRenderer.create(enlargeable({ full: 'full.jpg' }));
        document.body.appendChild(el);
        el.click();
        expect(document.querySelector('.sx-lightbox-backdrop img').getAttribute('src')).toBe('full.jpg');
        el.remove();
        document.querySelector('.sx-lightbox-backdrop')?.remove();
    });

    it('backdrop mousedown-on-self dismisses; mousedown on the image does not', () => {
        const el = imageRenderer.create(enlargeable());
        document.body.appendChild(el);
        el.click();
        const box = document.querySelector('.sx-lightbox-backdrop');
        box.querySelector('img').dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
        expect(document.querySelector('.sx-lightbox-backdrop')).not.toBeNull();
        box.dispatchEvent(new MouseEvent('mousedown'));
        expect(document.querySelector('.sx-lightbox-backdrop')).toBeNull();
        el.remove();
    });

    it('destroy closes an open lightbox and leaks nothing', () => {
        const el = imageRenderer.create(enlargeable());
        document.body.appendChild(el);
        el.click();
        expect(document.querySelector('.sx-lightbox-backdrop')).not.toBeNull();
        imageRenderer.destroy(el);
        expect(document.querySelector('.sx-lightbox-backdrop')).toBeNull();
        el.remove();
    });

    it('Enter on the focused image opens the lightbox', () => {
        const el = imageRenderer.create(enlargeable());
        document.body.appendChild(el);
        el.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
        expect(document.querySelector('.sx-lightbox-backdrop')).not.toBeNull();
        imageRenderer.destroy(el);
        el.remove();
    });
});
