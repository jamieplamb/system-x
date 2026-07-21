import { openModalOverlay } from '../_overlay.js';

// Display-only image. src is a URL (never base64 -- 256KB frame cap). A broken URL falls back to
// .sx-image--broken (keeps alt); a missing src renders blank. Enlarge (Task 3) adds a client-side
// lightbox that reuses the hardened openModalOverlay (focus-trap + layered dismiss).
export const imageRenderer = {
    create(node) {
        const el = document.createElement('img');
        el.className = 'sx-image';
        el.dataset.sxId = node.id ?? '';
        el.setAttribute('loading', 'lazy');
        el.setAttribute('src', node.props.src ?? '');
        el.setAttribute('alt', node.props.alt ?? '');
        el.addEventListener('error', () => el.classList.add('sx-image--broken'));

        if (node.props.enlarge === true) {
            el.classList.add('sx-image--enlargeable');
            el.setAttribute('tabindex', '0');
            el.setAttribute('role', 'button');
            el.setAttribute('aria-label', `Enlarge ${node.props.alt || 'image'}`);
            const fullSrc = node.props.full ?? node.props.src ?? '';
            const open = () => {
                if (el._sxLightbox) return; // one at a time
                el._sxLightbox = openModalOverlay({
                    build: () => {
                        const big = document.createElement('img');
                        big.className = 'sx-lightbox-img';
                        big.setAttribute('src', fullSrc);
                        big.setAttribute('alt', node.props.alt ?? '');
                        big.setAttribute('tabindex', '0'); // gives the focus-trap something to hold
                        return big;
                    },
                    onDismiss: () => { el._sxLightbox = null; },
                });
            };
            el.addEventListener('click', open);
            el.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); } });
        }

        return el;
    },

    // src/alt only. An enlarge-state flip between renders is out of scope for v1 (an image cell's
    // enlarge is fixed per column); we don't re-bind the click handler here.
    update(el, node) {
        if (el.getAttribute('src') !== (node.props.src ?? '')) {
            el.classList.remove('sx-image--broken');
            el.setAttribute('src', node.props.src ?? '');
        }
        if (el.getAttribute('alt') !== (node.props.alt ?? '')) {
            el.setAttribute('alt', node.props.alt ?? '');
        }
    },

    destroy(el) {
        if (el._sxLightbox) { el._sxLightbox.close(); el._sxLightbox = null; }
    },
};
