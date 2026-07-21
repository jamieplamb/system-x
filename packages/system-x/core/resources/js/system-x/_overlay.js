import { createFocusTrap } from './_focus-trap.js';

// Shared floating-overlay layer for the menu widgets (and 3c Tooltip). Two exports:
//  - placeOverlay(): pure viewport-clamp math (below the anchor by default; flip above on bottom
//    overflow; shift left on right overflow; never off-screen). Its own unit tests.
//  - openOverlay(): portal a built element to document.body, position it, trap focus, wire
//    outside-mousedown (anchor-guarded, mirroring system-menu.js) + Escape dismissal.

// side 'below' (the only side menus use in v1): popup top at anchor.bottom, left at anchor.left,
// flipped above / shifted left to stay in the viewport. anchor/viewport are plain rects so this is
// pure + testable. Coordinates are viewport-relative (for position:fixed).
export function placeOverlay(anchor, popup, viewport, side = 'below') {
    let top = side === 'above' ? anchor.top - popup.height : anchor.bottom;
    if (side !== 'above' && top + popup.height > viewport.height && anchor.top - popup.height >= 0) {
        top = anchor.top - popup.height;
    }
    let left = anchor.left;
    if (left + popup.width > viewport.width) left = viewport.width - popup.width;
    return { left: Math.max(0, left), top: Math.max(0, top) };
}

// Portal + dismiss + focus. build({ close }) returns the popup element (rows wire their own click
// to close). Returns { close }. anchorEl is the in-tree trigger (button/label).
export function openOverlay({ anchorEl, side = 'below', trap = true, build, onDismiss }) {
    let trapHandle = null;
    let popup = null;

    const doClose = () => {
        if (!popup) return;
        document.removeEventListener('mousedown', onOutside, true);
        document.removeEventListener('keydown', onKeydown, true);
        if (trapHandle) { trapHandle.release(); trapHandle = null; }
        popup.remove();
        popup = null;
        if (onDismiss) onDismiss();
    };

    const onOutside = (e) => {
        // Orphan guard: the reconciler/WM removes elements with a bare .remove() (no renderer
        // teardown hook yet), so a window closing or a strip rebuild while this popup is open
        // strands the anchor. Close eagerly on the next input rather than lingering as a ghost.
        if (!anchorEl.isConnected) return doClose();
        if (anchorEl.contains(e.target)) return; // anchor owns its own toggle
        if (popup && !popup.contains(e.target)) {
            // Consume the dismissing press so it can't ALSO dismiss whatever sits BEHIND the
            // popup (e.g. a dialog's backdrop) -- one press dismisses one layer. Capture-phase
            // stopPropagation halts the event before it reaches the layer below; the untouched
            // click/pointerdown events keep the app's delegated dispatcher + WM drag working.
            e.stopPropagation();
            doClose();
        }
    };
    const onKeydown = (e) => {
        if (!anchorEl.isConnected) return doClose();
        if (e.key === 'Escape') {
            // Same layering rule for Escape: this press closes the TOP layer (the popup) only;
            // a dialog underneath (document bubble-phase listener) gets the NEXT press.
            e.stopPropagation();
            doClose();
        }
    };

    popup = build({ close: doClose });
    popup.style.position = 'fixed';
    // z-index DECISION: `.style.zIndex = 'var(--sx-z-overlay)'` is silently DROPPED by the CSSOM
    // (the setter validates against <integer> and rejects a var() reference), so an inline var()
    // zIndex would never take effect. We therefore do NOT set z inline. Instead the popup carries
    // the `.sx-overlay` marker class; the actual `.sx-overlay { z-index: var(--sx-z-overlay) }` rule
    // lives in CSS (Task 5, which owns the menu CSS and also gives the popup its `.sx-menu` class).
    // Positioning (left/top/position:fixed) stays inline here where it's computed at open time.
    popup.classList.add('sx-overlay');
    popup.style.visibility = 'hidden';
    document.body.appendChild(popup);

    const a = anchorEl.getBoundingClientRect();
    const { left, top } = placeOverlay(a, { width: popup.offsetWidth, height: popup.offsetHeight },
        { width: window.innerWidth, height: window.innerHeight }, side);
    popup.style.left = `${left}px`;
    popup.style.top = `${top}px`;
    popup.style.visibility = '';

    document.addEventListener('mousedown', onOutside, true);
    document.addEventListener('keydown', onKeydown, true);
    if (trap) trapHandle = createFocusTrap(popup);

    return { close: doClose };
}

// Centered modal overlay for a lightbox (client-only, no anchor). Portals a backdrop + centered
// content to document.body, traps focus (createFocusTrap remembers + restores the trigger focus),
// and dismisses on backdrop-mousedown-on-self or CAPTURE-phase Escape (stopPropagation so one press
// closes THIS layer only -- a dialog/menu behind gets the next press, mirroring openOverlay's
// layering). Returns { close }; close is idempotent. build() returns the content element.
export function openModalOverlay({ build, onDismiss }) {
    let backdrop = null;
    let trapHandle = null;

    const doClose = () => {
        if (!backdrop) return;
        document.removeEventListener('keydown', onKeydown, true);
        if (trapHandle) { trapHandle.release(); trapHandle = null; } // restores focus to the trigger
        backdrop.remove();
        backdrop = null;
        if (onDismiss) onDismiss();
    };

    const onKeydown = (e) => {
        if (e.key === 'Escape') { e.stopPropagation(); doClose(); }
    };

    backdrop = document.createElement('div');
    backdrop.className = 'sx-lightbox-backdrop';
    backdrop.setAttribute('role', 'dialog');
    backdrop.setAttribute('aria-modal', 'true');
    // mousedown on the backdrop ITSELF (not the content) dismisses -- a drag that releases on the
    // backdrop after starting on the image must not close (e.target === backdrop guard).
    backdrop.addEventListener('mousedown', (e) => { if (e.target === backdrop) doClose(); });

    const content = build({ close: doClose });
    backdrop.appendChild(content);
    document.body.appendChild(backdrop);

    document.addEventListener('keydown', onKeydown, true);
    trapHandle = createFocusTrap(content); // content must contain a focusable (the big img gets tabindex=0)

    return { close: doClose };
}
