// The client window manager (Plan 5a, D1/D3/D6). Owns the per-window SURFACES and ALL
// their display state -- geometry (transform), z-order, focus (data-sx-active) -- which
// is CLIENT-ONLY and never touches PHP. The morph reconciler operates on the .sx-window
// INSIDE a surface and never the surface itself, so WM state on the surface survives
// every server frame (D3). This scaffold adopts the boot's static surfaces, cascades
// them, and focuses the first; Tasks 3-5 add real focus/z, drag, and maximise/restore
// onto this same layer.
import { CONTROL_GLYPHS } from './widgets/window.js';

const CASCADE_STEP = 28; // px offset per window so a fresh stack does not overlap
const CASCADE_ORIGIN = { x: 40, y: 40 };

// The panel's height (Plan 5b, D7). Mirrors the --sx-panel-h token (tokens/spacing.css) so
// the WM insets the work area off the panel WITHOUT a layout read in the hot drag path. This
// is the HEIGHT -- ONE constant; the panel is 34px tall wherever it sits. Only the panel
// POSITION (top vs bottom, 5b-2 D6) changes which EDGE the work area insets -- never add a
// second constant or read the live panel height in the drag path.
const PANEL_H = 34;

// The size floor for resize (Plan 5d, D4). MIN_W keeps the titlebar's title + the 3 control
// buttons reachable; MIN_H keeps the titlebar + a usable sliver of content. Module constants
// alongside PANEL_H -- the WM's geometry floor, not an app-declared min (that's a later plan).
const MIN_W = 180;
const MIN_H = 90;

// The snap margins (Plan 5f, D4) -- two DISTINCT bands, NOT one. EDGE is the thin strip off each
// edge that ARMS a snap at all. CORNER is a SEPARATE, much larger run along each edge near a
// corner (~a quarter of the shorter edge): a pointer within EDGE of a vertical edge AND within
// CORNER of the top/bottom snaps to a QUARTER. CORNER must be >> EDGE -- a 24px corner box ships
// unhittable quarters (B1). The corner is a generous band, not an EDGE x EDGE square.
const EDGE = 24;
const CORNER = 120;

export class WindowManager {
    // onClose is a callback the display server wires (Task 9): the WM stays pure-DOM and
    // raises a `close` INTENT, the display server owns the network call (closeWindow POST).
    // The WM removes the surface itself (it owns surfaces); onClose handles the wire side.
    //
    // onChange is the panel's seam (5b D3): ONE callback the display server passes,
    // mirroring onClose. notifyChange() pushes the current windows() list to it at the
    // tail of every list-changing mutator -- push, never poll. It defaults to a no-op so
    // the boot-focus notify (which fires during construction, BEFORE any panel exists --
    // B2 null-safe) is harmless when no real onChange is wired yet (Task 4 passes the
    // panel render). The display server keeps it null-safe with `this.panel?.render(...)`.
    // onGeometry is the geometry-persist seam (Plan 5e, D3): ONE injected callback, mirroring
    // onClose/onChange, fired with (windowId, snapshot) at each discrete settle point -- drag-end,
    // resize-end, maximise, restore, minimise, un-minimise, and a REAL raise. It keeps the WM
    // transport-agnostic + testable: the WM never POSTs; the display server wires this to a
    // fire-and-forget saveGeometry. It defaults to a no-op so the boot-focus bring() (which fires
    // during construction) is harmless when no real callback is wired (and Task 4's restoring guard
    // is the boot-suppress -- not built here).
    constructor(mount, { onClose = () => {}, onChange = () => {}, onGeometry = () => {}, destroySubtree = () => {}, panelPosition = 'top' } = {}) {
        this.mount = mount;
        this.onClose = onClose;
        this.onChange = onChange;
        this.onGeometry = onGeometry;
        // destroySubtree is the teardown seam (PH Task 2): ONE injected callback, mirroring
        // onClose/onChange/onGeometry. removeSurface() calls it with the surface BEFORE detaching,
        // so each renderer's optional destroy() hook (listeners, portaled popups, focus traps) runs
        // deterministically. It keeps the WM registry-agnostic -- the display server wires this to
        // destroyTree(el, { registry }); the WM never imports the registry. Defaults to a no-op.
        this.destroySubtree = destroySubtree;
        // The panel edge (5b-2 D6), from the boot blob (default 'top'). The WM must KNOW the
        // edge so maximise/clamp inset the work area on the correct side -- the same PANEL_H
        // height is lost to the top or the bottom. setPanelPosition flips it live (the apply
        // path, Task 3, calls it when the panel pref toggles).
        this.panelPosition = panelPosition;
        this.surfaces = new Map();   // windowId -> surface element
        this.maxZ = 0;               // monotonic z (Task 3)
        this.cascadeCount = 0;       // how many windows we've placed (cascade offset)
        this.focused = null;         // the active window id, or null when nothing is focused

        // The boot-restore suppress guard (Plan 5e, D5/S1). The adopt/apply/boot-focus block
        // below calls move/resize/bring/maximise/minimise -- the SAME methods that fire
        // onGeometry during normal use. onGeometry is a ctor option (already wired when this
        // runs), so "wire after" does NOT suppress -- a guard is REQUIRED. notifyGeometry
        // early-returns while this is true, so boot fires ZERO saves; it's cleared before the
        // ctor returns so the FIRST post-boot user action saves normally.
        this.restoring = true;

        // Adopt the boot's static surfaces (the shell painted them, D8). A surface the blade
        // STAMPED with persisted geometry (data-sx-x present, Plan 5e D4) is APPLIED -- moved/
        // resized/z'd/max'd/min'd into place, re-clamped to the live work area -- NOT cascaded.
        // A NULL-geometry surface still cascades. The TWO-PASS split (B3/probe#4): apply all the
        // positioned ones FIRST so we can rebase maxZ off the highest restored z, THEN cascade
        // the null ones ABOVE that stack (so a fresh window never z-fights a restored one).
        const cascadeLater = [];
        for (const el of this.mount.querySelectorAll('[data-window-id]')) {
            if (this.hasStampedGeometry(el)) {
                this.adopt(el);                       // apply the persisted rect/flags/z
            } else {
                this.surfaces.set(el.dataset.windowId, el);
                el.dataset.sxActive = 'false';
                cascadeLater.push(el);                // defer the cascade until after the rebase
            }
        }

        // Rebase maxZ to the highest restored z (Plan 5e D4) so a NEW raise stacks ABOVE the
        // restored windows. Read the applied z off each surface; default 0 when nothing restored.
        for (const surface of this.surfaces.values()) {
            this.maxZ = Math.max(this.maxZ, Number(surface.style.zIndex || 0));
        }

        // Now cascade the never-positioned windows -- ABOVE the restored stack (Plan 5e D4,
        // B3/probe#4). place() positions them; each then gets the next monotonic z so it sits
        // ON TOP of the restored stack rather than z-fighting it (a fresh window is the newest).
        for (const el of cascadeLater) {
            this.place(el);
            el.style.zIndex = String(++this.maxZ);
        }

        // ONE mount-level pointerdown raises the window the pointer landed in -- ANYWHERE
        // in it, content included (D6), not just the titlebar. This is a DIFFERENT concern
        // and phase from the delegated click/change dispatcher (which round-trips widget
        // events, D8): the pointerdown raises, a subsequent click still POSTs. Focus/z is
        // CLIENT-ONLY -- it NEVER touches the wire (D1). A pointerdown on the bare desktop
        // (the mount or .sx-desktop, no window under it) clears focus (the mockup's
        // root-click). pointerdown, NOT click -- a drag (Task 4) starts on pointerdown, so
        // the raise must land first.
        this.mount.addEventListener('pointerdown', (e) => {
            const surface = e.target.closest('[data-window-id]');
            if (surface) {
                this.bring(surface.dataset.windowId);
                // ...then, on the SAME press, arm a drag if the titlebar (not a control)
                // was hit, OR a resize if a [data-sx-resize] handle was hit. Raise always;
                // drag only from the titlebar, resize only from a handle (D6). The two arm
                // from DISTINCT elements (.sx-titlebar vs [data-sx-resize]) -- no overlap, so
                // a single press is at most one of the two. NO extra bring() in either (S3):
                // the mount-level raise above already fired the single notify.
                this.maybeStartDrag(e, surface);
                this.maybeStartResize(e, surface);
            } else if (e.target === this.mount || e.target.classList.contains('sx-desktop')) {
                this.blurAll();
            }
        });

        // ONE mount-level click for the chrome CONTROLS: maximise/restore toggles maximise
        // (D5, client-only -- NEVER POSTs), close removes the surface + raises the close intent
        // (Task 9). These are SEPARATE from the pointerdown raise/drag concern (the control
        // buttons carry no data-sx-events, so they never round-trip as a widget event -- the WM
        // owns them, Task 4 already ignores [data-sx-control] for drags).
        this.mount.addEventListener('click', (e) => {
            const control = e.target.closest('[data-sx-control]');
            if (!control) {
                return;
            }
            const surface = control.closest('[data-window-id]');
            if (!surface) {
                return;
            }
            const windowId = surface.dataset.windowId;

            if (control.dataset.sxControl === 'close') {
                // Optimistic: the surface is gone the instant you click close (removeSurface
                // drops the DOM node + the map entry + clears focus if it was focused). The
                // display server's onClose then fires the close POST (server-side forget, D7).
                this.removeSurface(windowId);
                this.onClose(windowId);
                return;
            }

            if (control.dataset.sxControl === 'minimise') {
                // Minimise hides the surface to its panel button (5b D4) -- client-only,
                // never POSTs. Un-minimise is the panel button (bring), not a control.
                this.minimise(windowId);
                return;
            }

            if (control.dataset.sxControl === 'maximise' || control.dataset.sxControl === 'restore') {
                this.toggleMaximise(windowId);
            }
        });
        this.mount.addEventListener('dblclick', (e) => {
            // Double-click the titlebar (but NOT a control) toggles maximise, like the
            // mockup's onDoubleClick. The control has its own single-click path above.
            //
            // Resolve the hit element from COORDINATES, not e.target: maybeStartDrag takes
            // setPointerCapture on the surface for the same press, and capture retargets the
            // click/dblclick to the capture element. e.target is therefore the SURFACE, and
            // .closest('.sx-titlebar') from there can never match (the titlebar is a
            // descendant, not an ancestor) -- which silently killed every real double-click
            // while the jsdom test, dispatching a synthetic event straight at the titlebar,
            // stayed green.
            const hit = this.hitElement(e);
            if (!hit || !hit.closest('.sx-titlebar') || hit.closest('[data-sx-control]')) {
                return;
            }
            const surface = hit.closest('[data-window-id]');
            if (surface) {
                this.toggleMaximise(surface.dataset.windowId);
            }
        });

        // The one in-flight drag, or null. A drag is pure CLIENT state (D1) -- no
        // pointermove ever POSTs; geometry is ephemeral (D2), nothing persists on drop.
        this.drag = null;

        // The one in-flight resize, or null (Plan 5d, D6). Same model as the drag: pure
        // client state, rAF-batched, ephemeral on drop -- nothing persists.
        this.resize_ = null;

        // The snap ghost-preview element (Plan 5f, D2), or null until first shown. ONE element,
        // lazily created on the FIRST showSnapGhost and reused thereafter (no duplicates) -- the
        // body-mounted translucent rect that previews the snap target rect mid-drag. Task 3 calls
        // show/hide from the drag; the ghost is pure CLIENT chrome (D1) -- it never POSTs.
        this.snapGhost = null;

        // The boot-focus (Plan 5e D4): focus the highest-z NON-minimised window so the desktop
        // opens with the right active surface AND the restored stacking is honoured.
        //   - When there are never-positioned (cascade) windows, the FIRST of them is the desktop's
        //     fresh top -- bring() it (it's above the restored stack from the z-bump above; this
        //     lands it at the very top + focuses it, preserving the old "first window focused" boot
        //     for an all-cascade desktop).
        //   - Otherwise (a pure restore) focus the highest-z non-minimised RESTORED window WITHOUT
        //     a bring() -- bring would churn z and break the restored stacking. applyFocus only
        //     sets the active cue + this.focused, leaving every z exactly as restored.
        //   - All windows minimised -> focus NOTHING (this.focused stays null, no crash, no save).
        if (cascadeLater.length > 0) {
            this.bring(cascadeLater[0].dataset.windowId);
        } else {
            const top = this.topNonMinimised();
            if (top !== null) {
                this.applyFocus(top);
            }
        }

        // The boot-restore is done -- clear the suppress guard BEFORE the ctor returns so the
        // FIRST real user action (drag/resize/maximise/minimise/raise) saves normally (D5/S1).
        this.restoring = false;
        this.notifyChange(); // one final list push now the boot stacking + focus are settled
    }

    // Does a surface carry a blade-STAMPED persisted rect (Plan 5e D4)? data-sx-x is the marker:
    // the blade stamps it ONLY for a window with saved geometry (x not null), so its presence is
    // the apply-vs-cascade switch in adopt().
    hasStampedGeometry(surface) {
        return surface.dataset.sxX !== undefined && surface.dataset.sxX !== '';
    }

    // The highest-z NON-minimised window id, or null when every window is minimised (Plan 5e D4).
    // Used for the boot-focus of a pure restore -- the focused window IS the top of the stack (D2:
    // no separate focused column; focus falls out of the restored z-order).
    topNonMinimised() {
        let topId = null;
        let topZ = -Infinity;
        for (const [id, surface] of this.surfaces) {
            if (surface.dataset.sxMin === 'true') {
                continue;
            }
            const z = Number(surface.style.zIndex || 0);
            if (z >= topZ) {
                topZ = z;
                topId = id;
            }
        }
        return topId;
    }

    // Adopt a surface into the WM's map + give it a position. Two paths (Plan 5e D4):
    //   - STAMPED with persisted geometry (the blade's data-sx-* on boot) -> APPLY it (the
    //     re-clamped restore rect, the z, then the max/min flag), NOT a cascade.
    //   - No stamped geometry (a runtime-minted window, or a never-positioned boot surface)
    //     -> place() cascade as before.
    // mintSurface() (runtime launch) calls this with a bare surface, so the cascade path is
    // its default; the boot constructor routes stamped surfaces here and cascades the rest itself.
    adopt(surface) {
        const windowId = surface.dataset.windowId;
        this.surfaces.set(windowId, surface);
        if (this.hasStampedGeometry(surface)) {
            this.applyGeometry(surface);      // the persisted rect/flags/z, re-clamped (D4)
        } else {
            this.place(surface);              // give it a default cascade position
        }
        surface.dataset.sxActive = 'false';   // inactive until focused (WM-owned, D3)
        return surface;
    }

    // Apply a surface's blade-STAMPED persisted geometry on boot (Plan 5e D4). The ORDER is
    // load-bearing (B2):
    //   1. Read the stamped RESTORE rect (x/y from data-sx-x/y, w/h from the stamped style when
    //      data-sx-sized -- the un-maximised geometry).
    //   2. CLAMP it to the live work area (clamp + clampSize -- the different-viewport re-clamp,
    //      D4/D7: a rect persisted on a bigger screen can't strand a window off a smaller one).
    //   3. move()/resize() to the clamped restore rect, then set the persisted z.
    //   4. If MAXIMISED: seed data-sx-preMax FROM the clamped restore rect (so a later restore
    //      returns there -- B1), then applyMaximiseGeometry() to fill the live work area FRESH
    //      (NOT the stamped dims -- the fill is computed against the current viewport).
    //      If MINIMISED: set data-sx-min -- do NOT paint/clamp/focus it (it's hidden).
    // This runs UNDER this.restoring, so none of these mutators fire onGeometry (D5).
    applyGeometry(surface) {
        const sized = surface.dataset.sxSized === 'true';
        const maximised = surface.dataset.sxMax === 'true';
        const minimised = surface.dataset.sxMin === 'true';
        const z = surface.dataset.sxZ;

        // The stamped restore rect. Width/height come off the stamped style for a sized window;
        // an un-sized window keeps its shrink-wrap (no explicit dims).
        const x = Number(surface.dataset.sxX || 0);
        const y = Number(surface.dataset.sxY || 0);

        if (sized) {
            // Clamp the SIZE first (pure-size), then clamp the ORIGIN -- mirrors the resize path.
            const { w, h } = this.clampSize(
                parseInt(surface.style.width, 10) || MIN_W,
                parseInt(surface.style.height, 10) || MIN_H,
            );
            const pos = this.clamp(x, y);
            this.resize(surface, pos.x, pos.y, w, h); // writes width/height + data-sx-sized + move
        } else {
            const pos = this.clamp(x, y);
            this.move(surface, pos.x, pos.y);         // position only; the window shrink-wraps
        }

        // The persisted stacking z (the rebase + boot-focus read it back off the surface).
        if (z !== undefined && z !== '') {
            surface.style.zIndex = String(Number(z));
        }

        if (maximised) {
            // Seed the pre-max stash FROM the clamped restore rect (B1) so a later user-restore
            // returns to it -- then fill the live work area FRESH (NOT the stamped dims).
            surface.dataset.sxPreMax = JSON.stringify({
                x: Number(surface.dataset.sxX || 0),
                y: Number(surface.dataset.sxY || 0),
                w: surface.style.width,
                h: surface.style.height,
                sized: surface.dataset.sxSized === 'true',
            });
            this.applyMaximiseGeometry(surface);
        }
        // A minimised window already carries data-sx-min from the blade stamp -- we leave it set
        // and do NOT focus/paint it (the boot-focus skips it via topNonMinimised).
    }

    surfaceFor(windowId) {
        return this.surfaces.get(windowId) ?? null;
    }

    windowIds() {
        return [...this.surfaces.keys()];
    }

    // The OBSERVABLE projection (5b D3): the ordered window list the panel mirrors,
    // in surfaces-map (insertion) order. active is this.focused; minimised is the
    // surface's data-sx-min (always false until Task 3 adds minimise); app is read off
    // the surface. Title/icon are NOT here -- the client joins those from the boot/launch
    // metadata by app slug; windows() is just the live WM state.
    windows() {
        return [...this.surfaces.entries()].map(([id, surface]) => ({
            id,
            app: surface.dataset.app ?? '',
            active: this.focused === id,
            minimised: surface.dataset.sxMin === 'true',
        }));
    }

    // Push the current list to the ONE subscriber (the panel, 5b D3). Called at the TAIL of
    // every mutator that changes the observable list -- open/close/focus/blur (+ minimise in
    // Task 3). NO polling, NO per-frame work: the WM knows precisely when the list changed.
    //
    // NOTIFY-ONCE CONVENTION: a PUBLIC entry point notifies once at its tail; a NESTED helper
    // call does NOT. bring() calls applyFocus() (the non-notifying focus core) so focus-inside-
    // bring never double-fires; mintSurface() ends by calling bring() and relies on bring's
    // single notify rather than adding its own. Any new mutator follows this -- notify once,
    // after all work, with the final list; never inside a loop.
    notifyChange() {
        this.onChange(this.windows());
    }

    // Push a window's settled geometry to the ONE subscriber (the display server's saveGeometry,
    // Plan 5e D3). Called at each discrete settle point -- drag-end, resize-end, maximise, restore,
    // minimise, un-minimise, and a REAL raise (the GUARDED z-save in bring(), B3). It reads the FULL
    // current snapshot off the surface so the server always stores a consistent row -- never a partial.
    notifyGeometry(windowId) {
        // The boot-restore suppress guard (Plan 5e D5/S1): while restoring, the WM is APPLYING
        // persisted geometry -- re-persisting it would re-write what we just loaded (and the
        // boot-focus bring() would churn z). Early-return so boot fires ZERO saves; the guard is
        // cleared before the ctor returns so the first post-boot user action saves normally.
        if (this.restoring) {
            return;
        }
        const surface = this.surfaceFor(windowId);
        if (!surface) {
            return;
        }
        this.onGeometry(windowId, this.snapshotGeometry(surface));
    }

    // The FULL geometry snapshot of a surface (Plan 5e D3): a plain object the persist POST stores
    // as one row -- { x, y, w, h, sized, maximised, minimised, z }. Read off the surface's WM-owned
    // state (the morph never touches it, D3).
    //
    // B1 -- the maximise landmine: when MAXIMISED, x/y/w/h must be the RESTORE rect (the un-maximised
    // geometry), NOT the maximised work-area fill, so a reloaded-maximised window RESTORES to the
    // right size. Read them from the pre-max stash (data-sx-preMax: {x,y,w,h,sized}), with a fallback
    // to the live surface rect when no stash exists (defensive -- maximise always writes the stash,
    // but a window forced maximised without one must not persist NaN). NEVER persist the maximised dims.
    snapshotGeometry(surface) {
        const maximised = surface.dataset.sxMax === 'true';
        const minimised = surface.dataset.sxMin === 'true';
        const z = Number(surface.style.zIndex || 0);

        // The live surface rect (the un-maximised default, and the maximise fallback).
        const liveRect = {
            x: Number(surface.dataset.sxX || 0),
            y: Number(surface.dataset.sxY || 0),
            w: parseInt(surface.style.width, 10) || 0,
            h: parseInt(surface.style.height, 10) || 0,
            sized: surface.dataset.sxSized === 'true',
        };

        let rect = liveRect;
        if (maximised) {
            // Read the RESTORE rect off the stash (B1), falling back to the live rect with no stash.
            let pre = null;
            try {
                pre = surface.dataset.sxPreMax ? JSON.parse(surface.dataset.sxPreMax) : null;
            } catch {
                pre = null;
            }
            if (pre) {
                rect = {
                    x: Number(pre.x) || 0,
                    y: Number(pre.y) || 0,
                    w: parseInt(pre.w, 10) || 0,
                    h: parseInt(pre.h, 10) || 0,
                    sized: pre.sized === true,
                };
            }
        }

        return {
            x: rect.x,
            y: rect.y,
            w: rect.w,
            h: rect.h,
            sized: rect.sized,
            maximised,
            minimised,
            z,
        };
    }

    // Mint a brand-new surface for a launched window (Task 8 uses this end-to-end).
    // The surface is added to the map, cascade-positioned, and raised BEFORE any tree
    // is reconciled into it -- so the first paint lands in an already-placed surface
    // (the D3 first-paint case). The display server reconciles the launch tree after.
    mintSurface(windowId, app) {
        const surface = document.createElement('div');
        surface.className = 'sx-window-surface';
        surface.dataset.windowId = windowId;
        surface.dataset.app = app;
        this.mount.appendChild(surface);
        this.adopt(surface);
        this.bring(windowId);
        return surface;
    }

    // Drop a surface from the DOM and the map (Task 9's close uses this). If the closed
    // window was the focused one, focus is cleared -- nothing else is auto-raised (the
    // mockup leaves the desktop unfocused after a close, like a root click).
    removeSurface(windowId) {
        const surface = this.surfaces.get(windowId);
        if (!surface) {
            return;
        }
        // Run each renderer's destroy() over the whole surface subtree BEFORE the DOM detach
        // (PH Task 2) -- so a closed window's listeners/portals/traps are torn down, no leak.
        this.destroySubtree(surface);
        surface.remove();
        this.surfaces.delete(windowId);
        if (this.focused === windowId) {
            this.focused = null;
        }
        this.notifyChange(); // the window left the list -- the panel drops its button
    }

    // Raise a window to the top of the z-stack and make it the focused one: its surface
    // gets the next monotonic z + the active cue, every other surface goes inactive.
    // Wired to the mount-level pointerdown (any in-window press) and used for boot-focus.
    bring(windowId) {
        const surface = this.surfaces.get(windowId);
        if (!surface) {
            return;
        }
        // Un-minimise on raise (5b D4) -- a raised window is visible, so bring() IS the
        // single un-minimise path (S1, no separate unminimise method). It clears ONLY
        // data-sx-min, NEVER data-sx-max -- the min/max axes are independent, so a
        // maximised-then-minimised window comes back maximised. A bring() on a minimised
        // window is a real geometry change (the minimised flag flips), so it persists below.
        const wasMin = surface.dataset.sxMin === 'true';
        surface.dataset.sxMin = 'false';

        // B3 -- the GUARDED z-save (Plan 5e D3): bring() fires on EVERY pointerdown into a
        // window (content + already-top included) AND the same press that starts a drag/resize
        // bring()s first -- so a naive "save on every bring" is click-paced (a POST storm). The
        // z-save fires ONLY when the window's z ACTUALLY advances: it wasn't already the top
        // (its current z !== the current maxZ). Clicking the already-top window -> z does NOT
        // advance -> ZERO saves. Un-minimise still persists (the min flag flipped) even if it
        // happened to be on top.
        const wasTop = Number(surface.style.zIndex || 0) === this.maxZ && this.maxZ > 0;
        surface.style.zIndex = String(++this.maxZ); // monotonic -- always above the rest
        this.applyFocus(windowId); // non-notifying core -- bring notifies ONCE at its tail
        this.notifyChange();

        if (!wasTop || wasMin) {
            this.notifyGeometry(windowId); // a real raise or an un-minimise -- a genuine settle
        }
    }

    // Minimise (Plan 5b, D4): CLIENT-ONLY, mirroring maximise (5a D5). Hide the surface
    // (data-sx-min + a CSS class that display:none's it -- NOT removed from the map/DOM; the
    // window is still OPEN, its bag + open-row stay), clear focus if it was the focused
    // window, and notify the panel. NEVER POSTs. A minimised surface still accepts morph
    // frames -- the morph touches the .sx-window inside, never the surface's data-sx-min
    // (5a D3). Un-minimise is NOT a method here -- the panel button calls bring() (above),
    // which clears data-sx-min, un-hides, raises + focuses, and notifies once (S1). Leaves
    // data-sx-max alone -- min/max are independent axes (no name collision with restore).
    minimise(windowId) {
        const surface = this.surfaceFor(windowId);
        if (!surface || surface.dataset.sxMin === 'true') {
            return;
        }
        surface.dataset.sxMin = 'true';
        if (this.focused === windowId) {
            this.focused = null; // nothing else auto-raised -- like a close-to-background blur
        }
        this.notifyChange();
        this.notifyGeometry(windowId); // the minimised flag settled -- persist {minimised: true}
    }

    // Make exactly one surface active: its data-sx-active flips to 'true', every other to
    // 'false'. The cue lives on the SURFACE (D3) so the morph never resets it. z is left
    // to bring() -- focus() only owns the active cue. This PUBLIC entry point notifies once;
    // bring() uses applyFocus() instead so focus-inside-bring never double-fires.
    focus(windowId) {
        this.applyFocus(windowId);
        this.notifyChange();
    }

    // The non-notifying focus core (the nested helper, per the notify-once convention).
    applyFocus(windowId) {
        for (const [id, surface] of this.surfaces) {
            surface.dataset.sxActive = id === windowId ? 'true' : 'false';
        }
        this.focused = windowId;
    }

    // Clear focus entirely (the desktop-background click): every surface goes inactive and
    // nothing is focused. No z change -- blurring doesn't restack. Notifies ONCE, after the
    // loop, with the final list (never per-surface inside the loop).
    blurAll() {
        for (const surface of this.surfaces.values()) {
            surface.dataset.sxActive = 'false';
        }
        this.focused = null;
        this.notifyChange();
    }

    // Default cascade placement: each new window steps down-right from the last.
    place(surface) {
        const i = this.cascadeCount++;
        const x = CASCADE_ORIGIN.x + i * CASCADE_STEP;
        const y = CASCADE_ORIGIN.y + i * CASCADE_STEP;
        this.move(surface, x, y);
    }

    // Geometry is a transform (NEVER left/top, D6) so it composites and never thrashes
    // layout. The WM is the SINGLE writer of this -- the morph never touches it (D3).
    move(surface, x, y) {
        surface.dataset.sxX = String(x);
        surface.dataset.sxY = String(y);
        surface.style.transform = `translate3d(${x}px, ${y}px, 0)`;
    }

    // The SIZE-writer (Plan 5d, D1) -- resize is client-only SURFACE geometry, parallel to the
    // drag. It writes the explicit width/height + the data-sx-sized flag onto the SURFACE and
    // reuses the ONE position-writer (this.move) for the origin, exactly as applyMaximiseGeometry
    // does (S1 -- never duplicate the transform write). The .sx-window inside FILLS the sized
    // surface via the standing CSS rule (surface.css, [data-sx-sized] > .sx-window { 100% }), so
    // the WM NEVER writes width/height onto the inner .sx-window (N2 parity with maximise). Geometry
    // lives on the surface the morph never touches, so a server frame mid-resize can't fight it (D3).
    resize(surface, x, y, w, h) {
        surface.style.width = `${w}px`;
        surface.style.height = `${h}px`;
        surface.dataset.sxSized = 'true'; // the surface is explicitly sized now -- the window fills it
        this.move(surface, x, y);         // reuse the single position-writer for the origin (S1)
    }

    // The size clamp (Plan 5d, D4/B1) -- a PURE SIZE function, like clamp(x,y) is pure-position. It
    // floors w at MIN_W / h at MIN_H (a window can't shrink to nothing) and ceils them to the work
    // area, returning a clamped { w, h }. It does NOT take or return x/y -- it can't re-derive the
    // origin from an already-moved rect, so the origin-shift anchor math lives in maybeStartResize
    // (Task 4), NOT here. It shares the PANEL_H CONSTANT with clamp() but is NOT clamp() (S2): clamp
    // is deliberately permissive (keeps an 80/24px margin reachable so a window can be shoved
    // off-screen); clampSize is the OPPOSITE -- it keeps the WHOLE rect inside the work area, so the
    // max fills it EXACTLY (clientWidth, clientHeight - PANEL_H) with NO drag margin leaking in.
    clampSize(w, h) {
        const maxW = this.mount.clientWidth;
        const maxH = this.mount.clientHeight - PANEL_H;
        return {
            w: maxW > 0 ? Math.min(Math.max(w, MIN_W), maxW) : Math.max(w, MIN_W),
            h: maxH > 0 ? Math.min(Math.max(h, MIN_H), maxH) : Math.max(h, MIN_H),
        };
    }

    // Drag-to-move (D6). Pointer Events + setPointerCapture + translate3d, rAF-batched.
    // Called from the mount-level pointerdown AFTER the raise -- so a press both raises
    // (Task 3) and, when it lands on the titlebar, begins a drag. NEVER POSTs (D1).
    //
    // The drag reads the surface's start position ONCE (from its WM-owned sxX/sxY data,
    // not a layout read) and computes purely from pointer deltas after -- no
    // offsetWidth/getBoundingClientRect mid-drag, so it never thrashes layout. The move
    // writes the surface transform (which the morph never touches, D3), so a server frame
    // arriving mid-drag can't fight the position.
    // The element actually under the pointer. Pointer capture retargets click/dblclick to the
    // capture element, so e.target lies about what was hit -- elementFromPoint doesn't. Falls back
    // to e.target where elementFromPoint is unavailable or returns nothing (jsdom doesn't implement
    // it), which is exactly the case where nothing retargeted the event anyway.
    hitElement(e) {
        try {
            return document.elementFromPoint?.(e.clientX, e.clientY) ?? e.target;
        } catch {
            return e.target;
        }
    }

    maybeStartDrag(e, surface = e.target.closest('[data-window-id]')) {
        if (!surface) {
            return;
        }
        // Drag ONLY from the titlebar, and NEVER from a control button (Task 2 landmine).
        if (!e.target.closest('.sx-titlebar') || e.target.closest('[data-sx-control]')) {
            return;
        }
        // A maximised window is pinned to the work area -- it doesn't drag (Task 5 guard).
        if (surface.dataset.sxMax === 'true') {
            return;
        }

        const startX = Number(surface.dataset.sxX || 0);
        const startY = Number(surface.dataset.sxY || 0);
        this.drag = {
            surface,
            pointerId: e.pointerId,
            originX: e.clientX,
            originY: e.clientY,
            startX,
            startY,
            pending: null,
            raf: null,
        };
        // Capture pins every subsequent pointer event to the surface, so the drag never
        // drops when the cursor outpaces the window (no-op + guarded in jsdom).
        surface.setPointerCapture?.(e.pointerId);
        e.preventDefault();

        const onMove = (ev) => {
            if (!this.drag || ev.pointerId !== this.drag.pointerId) {
                return;
            }
            // Snap detection (Plan 5f, D4) -- on the RAW pointermove, NOT inside the rAF below, so
            // the stored zone is never stale (it's exactly what the ghost is showing). Store the zone
            // on the drag so onUp commits the SAME zone it previewed (it doesn't re-read coords).
            const zone = this.snapZoneFor(ev.clientX, ev.clientY);
            this.drag.snapZone = zone;
            if (zone) {
                this.showSnapGhost(this.snapRectFor(zone));
            } else {
                this.hideSnapGhost();
            }
            const nx = this.drag.startX + (ev.clientX - this.drag.originX);
            const ny = this.drag.startY + (ev.clientY - this.drag.originY);
            // rAF-batch: stash the latest clamped target, write the transform at most
            // once per frame. Never read layout here.
            this.drag.pending = this.clamp(nx, ny);
            if (!this.drag.raf) {
                this.drag.raf = requestAnimationFrame(() => {
                    if (!this.drag) {
                        return;
                    }
                    this.drag.raf = null;
                    if (this.drag.pending) {
                        this.move(this.drag.surface, this.drag.pending.x, this.drag.pending.y);
                    }
                });
            }
        };

        const onUp = (ev) => {
            if (!this.drag || ev.pointerId !== this.drag.pointerId) {
                return;
            }
            // Flush any pending frame so the drop lands exactly where the pointer left it.
            if (this.drag.raf) {
                cancelAnimationFrame(this.drag.raf);
            }
            if (this.drag.pending) {
                this.move(this.drag.surface, this.drag.pending.x, this.drag.pending.y);
            }
            this.drag.surface.releasePointerCapture?.(ev.pointerId);
            const settled = this.drag.surface; // capture before clearing the drag state
            // Read the STORED zone the last onMove armed (Plan 5f, D4) -- NOT a fresh coord read.
            // The drag doesn't retain the last pointer pos; the stored zone is what the ghost was
            // showing. Truthiness: undefined on a no-move release -> no snap (the normal drop).
            const zone = this.drag.snapZone;
            window.removeEventListener('pointermove', onMove);
            window.removeEventListener('pointerup', onUp);
            window.removeEventListener('pointercancel', onCancel);
            this.drag = null;
            // EXACTLY ONE geometry POST per path (Plan 5f, D3 -- the double-notify trap):
            //   - a zone armed -> snap() commits it AND fires its own notifyGeometry (top via
            //     maximise, halves/quarters via the explicit one). Do NOT also fire the drag-end one.
            //   - no zone -> the normal drop fires the existing drag-end settle (today's behaviour).
            if (zone) {
                this.snap(settled, zone);
            } else {
                this.notifyGeometry(settled.dataset.windowId);
            }
            // Hide the ghost UNCONDITIONALLY on every drag end (Plan 5f, D5) -- snapped or not, a
            // lingering ghost is a stuck overlay.
            this.hideSnapGhost();
        };

        // pointercancel teardown (Plan 5f, D5/S1) -- an OS-cancelled pointer (touch interrupt, focus
        // loss) must NOT leave this.drag dangling or the ghost stuck on-screen. Same teardown as onUp
        // MINUS the snap: a cancelled drag never commits a zone, it just cleans up + hides the ghost.
        const onCancel = (ev) => {
            if (!this.drag || ev.pointerId !== this.drag.pointerId) {
                return;
            }
            if (this.drag.raf) {
                cancelAnimationFrame(this.drag.raf);
            }
            this.drag.surface.releasePointerCapture?.(ev.pointerId);
            window.removeEventListener('pointermove', onMove);
            window.removeEventListener('pointerup', onUp);
            window.removeEventListener('pointercancel', onCancel);
            this.drag = null;
            this.hideSnapGhost();
        };

        // Move/up on window (belt) AND capture on the surface (braces) -- together they
        // keep the drag alive when the pointer races off the titlebar. pointercancel cleans up a
        // drag the OS aborts (Plan 5f, D5) -- added to the SAME add/remove set as move/up.
        window.addEventListener('pointermove', onMove);
        window.addEventListener('pointerup', onUp);
        window.addEventListener('pointercancel', onCancel);
    }

    // Drag-to-resize (Plan 5d, D6) -- the live resize, mirroring maybeStartDrag's perf model
    // exactly: arm from a handle, read the start rect ONCE at grab, rAF-batch the surface
    // writes, pointer-capture, ephemeral on drop. NEVER POSTs (D1).
    //
    // The direction (data-sx-resize) decides which edge(s) the pointer drives:
    //   E/S edges GROW from the top-left anchor (size only, origin fixed).
    //   W/N edges SHIFT the origin -- the OPPOSITE edge is the fixed anchor (B1): capture
    //   anchorRight = startX + startW (for W) / anchorBottom = startY + startH (for N) ONCE,
    //   then after clamping derive x = anchorRight - w / y = anchorBottom - h. x and w are
    //   COUPLED through the anchor, so clamping w to MIN_W STOPS the left edge at
    //   anchorRight - MIN_W instead of letting it cross over.
    //   Corners combine the two axes.
    //
    // The w/h start come from a ONE-TIME offset measure (S4): a never-resized surface is
    // width:max-content with NO width data-attribute, so we must MEASURE it -- and BEFORE the
    // first resize() applies data-sx-sized/width:100%, or we'd measure the already-filled size.
    // The measure happens once here at grab; the hot path only applies pointer deltas (no
    // mid-resize layout reads, mirroring how the drag reads sxX once).
    //
    // NO bring() here (S3): the handle is inside the surface, so the mount-level pointerdown
    // (the constructor's pointerdown handler) already raised + focused on the same press -- a
    // second bring() would double-fire notifyChange().
    maybeStartResize(e, surface = e.target.closest('[data-window-id]')) {
        if (!surface) {
            return;
        }
        // Resize ONLY from a [data-sx-resize] handle.
        const handle = e.target.closest('[data-sx-resize]');
        if (!handle) {
            return;
        }
        // A maximised window is pinned to the work area -- it doesn't resize (D5 guard,
        // mirrors the drag guard). Its handles are hidden in CSS too.
        if (surface.dataset.sxMax === 'true') {
            return;
        }

        const dir = handle.dataset.sxResize;          // n|e|s|w|ne|nw|se|sw
        const movesEast = dir.includes('e');
        const movesWest = dir.includes('w');
        const movesSouth = dir.includes('s');
        const movesNorth = dir.includes('n');

        const startX = Number(surface.dataset.sxX || 0);
        const startY = Number(surface.dataset.sxY || 0);
        // The ONE-TIME measure (S4) -- BEFORE the first resize() applies data-sx-sized.
        const startW = surface.offsetWidth;
        const startH = surface.offsetHeight;
        // The opposite-edge anchors for the origin-shifting edges (B1), captured ONCE.
        const anchorRight = startX + startW;
        const anchorBottom = startY + startH;

        this.resize_ = {
            surface,
            pointerId: e.pointerId,
            originX: e.clientX,
            originY: e.clientY,
            dir, movesEast, movesWest, movesSouth, movesNorth,
            startX, startY, startW, startH, anchorRight, anchorBottom,
            pending: null,
            raf: null,
        };
        surface.setPointerCapture?.(e.pointerId);
        e.preventDefault();

        const onMove = (ev) => {
            if (!this.resize_ || ev.pointerId !== this.resize_.pointerId) {
                return;
            }
            const r = this.resize_;
            const dx = ev.clientX - r.originX;
            const dy = ev.clientY - r.originY;

            // The dragged edge's new size, per axis (E grows +dx, W grows -dx; S grows +dy,
            // N grows -dy). Inactive axes keep the start size.
            let w = r.startW;
            if (r.movesEast) {
                w = r.startW + dx;
            } else if (r.movesWest) {
                w = r.startW - dx;
            }
            let h = r.startH;
            if (r.movesSouth) {
                h = r.startH + dy;
            } else if (r.movesNorth) {
                h = r.startH - dy;
            }

            // Floor/ceil the SIZE (pure-size, B1) BEFORE deriving the origin.
            ({ w, h } = this.clampSize(w, h));

            // Derive the origin from the anchor for the origin-shifting edges (B1, HERE not in
            // clampSize): x/w coupled through anchorRight, y/h through anchorBottom. E/S edges
            // keep the start origin.
            let x = r.movesWest ? r.anchorRight - w : r.startX;
            let y = r.movesNorth ? r.anchorBottom - h : r.startY;
            // Keep the origin inside the work area (the top edge can't slip under a top panel;
            // nothing off the left). Reuses the PANEL_H floor logic the drag clamp uses.
            // NOTE (deliberate, D4): when a W/N drag floors the origin at the wall, we do NOT
            // re-pin the anchored (right/bottom) edge -- so it slides outward rather than the
            // dragged edge simply stopping at the wall. Accepted trade; a future snap author
            // wanting the edge pinned would cap the drag before x/y goes negative.
            const floorY = this.panelPosition === 'top' ? PANEL_H : 0;
            x = Math.max(0, x);
            y = Math.max(floorY, y);

            r.pending = { x, y, w, h };
            if (!r.raf) {
                r.raf = requestAnimationFrame(() => {
                    if (!this.resize_) {
                        return;
                    }
                    this.resize_.raf = null;
                    if (this.resize_.pending) {
                        const p = this.resize_.pending;
                        this.resize(this.resize_.surface, p.x, p.y, p.w, p.h);
                    }
                });
            }
        };

        const onUp = (ev) => {
            if (!this.resize_ || ev.pointerId !== this.resize_.pointerId) {
                return;
            }
            if (this.resize_.raf) {
                cancelAnimationFrame(this.resize_.raf);
            }
            if (this.resize_.pending) {
                const p = this.resize_.pending;
                this.resize(this.resize_.surface, p.x, p.y, p.w, p.h);
            }
            this.resize_.surface.releasePointerCapture?.(ev.pointerId);
            const settled = this.resize_.surface; // capture before clearing the resize state
            window.removeEventListener('pointermove', onMove);
            window.removeEventListener('pointerup', onUp);
            this.resize_ = null;
            // Resize-END is a settle point (Plan 5e D3) -- persist the settled rect (x/y/w/h/sized).
            this.notifyGeometry(settled.dataset.windowId);
        };

        window.addEventListener('pointermove', onMove);
        window.addEventListener('pointerup', onUp);
    }

    // Keep the titlebar grabbable: clamp x/y so a window can't be dragged fully off-screen
    // where you couldn't grab it back. Keep at least the titlebar height on-screen
    // vertically and ~80px horizontally, accounting for the desktop bounds. The panel
    // work-area is a later concern (5b) -- clamp to the mount for now.
    clamp(x, y) {
        const marginX = 80; // keep at least this much window reachable from the right edge
        const marginY = 24; // keep ~one titlebar height reachable from the bottom edge
        const w = this.mount.clientWidth;
        const h = this.mount.clientHeight;
        // The panel edge bounds y (5b-2 D6). TOP panel: FLOOR y at PANEL_H (a window can't hide
        // under the top panel), normal bottom bound. BOTTOM panel: floor y at 0, CEIL it at
        // h - PANEL_H - marginY (a window can't hide under the bottom panel). Same height eaten,
        // opposite edge. No measurable desktop (jsdom / pre-layout): only floor at the reachable
        // origin so a window can't be dragged off it; nothing to clamp against.
        const floorY = this.panelPosition === 'top' ? PANEL_H : 0;
        const ceilY = this.panelPosition === 'bottom' ? h - PANEL_H - marginY : h - marginY;
        return {
            x: w > 0 ? Math.max(0, Math.min(x, w - marginX)) : Math.max(0, x),
            y: h > 0 ? Math.max(floorY, Math.min(y, ceilY)) : Math.max(floorY, y),
        };
    }

    // The live panel toggle (5b-2 D6) -- the apply path (prefs.js, Task 3) calls this when the
    // panel pref flips. Record the new edge, then re-inset every ALREADY-maximised surface for
    // it (a maximised window must JUMP to the new fill, not stay stranded under the moved panel),
    // and notify. New/restored windows pick the edge up on their next maximise.
    setPanelPosition(pos) {
        this.panelPosition = pos;
        for (const [, surface] of this.surfaces) {
            if (surface.dataset.sxMax === 'true') {
                this.applyMaximiseGeometry(surface); // re-run the inset for the new edge
            }
        }
        this.notifyChange();
    }

    // The work-area inset for a maximised surface, by panel edge (5b-2 D6). TOP: fill BELOW the
    // panel (origin y = PANEL_H). BOTTOM: fill from the top (origin y = 0) -- the bottom panel
    // eats the bottom strip. Height insets by PANEL_H EITHER way (the panel is the same height,
    // only the edge moves -- PANEL_H stays one constant). SURFACE only (5b N2): the .sx-window
    // follows via the CSS width:100% rule, no imperative inner-element write.
    applyMaximiseGeometry(surface) {
        const top = this.panelPosition === 'top' ? PANEL_H : 0;
        this.move(surface, 0, top);
        surface.style.width = `${this.mount.clientWidth}px`;
        surface.style.height = `${this.mount.clientHeight - PANEL_H}px`;
    }

    // Which snap zone a pointer is in (Plan 5f, D4) -- a PURE function (no DOM mutation), like
    // clamp()/clampSize(). Reads the live work-area bounds (mount dims + the panel inset, the same
    // source applyMaximiseGeometry uses) and returns one of left|right|top|tl|tr|bl|br|null from the
    // pointer's distance to the work-area edges. CORNERS ARE CHECKED FIRST (B1): a top-left-corner
    // pointer is within EDGE of BOTH the left and top edges, and must resolve to 'tl', not 'left' or
    // 'top'. The corner band is CORNER (~120px, a generous run), NOT EDGE (~24px) -- a 24px box ships
    // unhittable quarters. A vertical edge is the trigger for the corner; CORNER measures the run
    // toward the top/bottom. The work-area top/bottom are panel-aware (mirror the maximise inset).
    snapZoneFor(clientX, clientY) {
        const W = this.mount.clientWidth;
        // No measurable work area (jsdom / pre-layout) -> no zone. Mirrors the clamp/clampSize w>0
        // guard: without real bounds the edge tests are meaningless, and a zero W would make the
        // right-edge test (clientX >= W - EDGE) fire for almost any pointer (B-fix).
        if (W <= 0) {
            return null;
        }
        const top = this.panelPosition === 'top' ? PANEL_H : 0;
        const bottom = top + (this.mount.clientHeight - PANEL_H); // the work-area bottom (panel-aware)

        const nearLeft = clientX <= EDGE;
        const nearRight = clientX >= W - EDGE;
        const nearTop = clientY <= top + EDGE;

        const inTopCorner = clientY <= top + CORNER;     // within CORNER of the work-area top
        const inBottomCorner = clientY >= bottom - CORNER; // within CORNER of the work-area bottom

        // Corners FIRST -- a vertical edge AND within the CORNER run of the top/bottom.
        if (nearLeft && inTopCorner) return 'tl';
        if (nearLeft && inBottomCorner) return 'bl';
        if (nearRight && inTopCorner) return 'tr';
        if (nearRight && inBottomCorner) return 'br';

        // Then the single edges -- a vertical edge in the MIDDLE (outside both CORNER bands) -> half;
        // the top edge mid-width (outside the left/right corner bands, handled above) -> maximise.
        if (nearLeft) return 'left';
        if (nearRight) return 'right';
        if (nearTop) return 'top';

        return null;
    }

    // The target rect for a snap zone (Plan 5f, D3) -- a PURE function returning the work-area
    // fraction {x, y, w, h}, reusing the maximise work-area math (W x workH, inset by the panel
    // edge). Halves are full-height W/2 columns; quarters are W/2 x workH/2 at the right origin;
    // 'top' is the FULL work area (the maximise fill -- Task 3 decides whether to call maximise()
    // or apply this rect). No DOM mutation; the apply path is Task 3.
    snapRectFor(zone) {
        const W = this.mount.clientWidth;
        const top = this.panelPosition === 'top' ? PANEL_H : 0;
        const workH = this.mount.clientHeight - PANEL_H;
        const halfW = W / 2;
        const halfH = workH / 2;

        switch (zone) {
            case 'left':  return { x: 0,     y: top,         w: halfW, h: workH };
            case 'right': return { x: halfW, y: top,         w: halfW, h: workH };
            case 'tl':    return { x: 0,     y: top,         w: halfW, h: halfH };
            case 'tr':    return { x: halfW, y: top,         w: halfW, h: halfH };
            case 'bl':    return { x: 0,     y: top + halfH, w: halfW, h: halfH };
            case 'br':    return { x: halfW, y: top + halfH, w: halfW, h: halfH };
            case 'top':   return { x: 0,     y: top,         w: W,     h: workH };
            default:      return null;
        }
    }

    // Apply a snap for a zone (Plan 5f, D3) -- the commit path the drag-end calls when the last
    // onMove armed a zone. The TWO branches mirror how a normal interaction settles, firing EXACTLY
    // ONE geometry POST either way (the double-notify trap):
    //   - 'top' -> call maximise() directly. It fills the work area, swaps the control to restore,
    //     stashes the pre-max rect, raises + focuses, and self-fires notifyGeometry ONCE -- so the
    //     top branch must NOT add its own (that would be the second POST).
    //   - a half/quarter -> compute the rect (snapRectFor), resize() to it (writes data-sx-sized +
    //     the dims + move), bring() (raise + focus), then notifyGeometry ONCE (persist via 5e). The
    //     rect already comes from the maximise work-area math; resize() doesn't clampSize, so we pass
    //     it through clampSize defensively (the same MIN floors a normal resize applies) before the
    //     apply -- but the origin stays the zone's origin (clampSize is pure-size, S3's tiny-screen
    //     degradation is accepted; on a normal viewport the half/quarter clears the floors untouched).
    snap(surface, zone) {
        const windowId = surface.dataset.windowId;
        if (zone === 'top') {
            this.maximise(windowId); // fills + swaps the control + stashes + raises + notifyGeometry ONCE
            return;
        }
        const rect = this.snapRectFor(zone);
        if (!rect) {
            return;
        }
        const { w, h } = this.clampSize(rect.w, rect.h); // defensive floors, matching a normal resize
        this.resize(surface, rect.x, rect.y, w, h);      // data-sx-sized + the dims + move
        this.bring(windowId);                            // raise + focus (a snap is an interaction)
        this.notifyGeometry(windowId);                   // the single settle POST for a half/quarter
    }

    // Show the snap ghost-preview at a target rect (Plan 5f, D2) -- the body-mounted translucent
    // rectangle that previews where a release would snap. Mirrors the launcher/badge overlay pattern:
    // a body-mounted FIXED element, z BELOW the panel (the .sx-snap-ghost CSS in surface.css owns the
    // fill/border/pointer-events; here we only position + show). The element is created ONCE on the
    // first call and reused on every subsequent one -- a pointer crossing zones repositions the SAME
    // ghost, never spawns a duplicate. The rect is in the desktop's coordinate space; since the mount
    // fills the viewport under the panel, position:fixed at those coords lands it over the work area.
    showSnapGhost(rect) {
        if (!this.snapGhost) {
            const el = document.createElement('div');
            el.className = 'sx-snap-ghost';
            el.style.position = 'fixed';
            // z BELOW the panel (100000) + above every window surface (the climbing maxZ stays low).
            // The CSS sets this too; the inline value keeps it correct without the stylesheet (jsdom).
            el.style.zIndex = '99999';
            el.style.pointerEvents = 'none'; // never intercept the drag (free insurance, D2)
            document.body.appendChild(el);
            this.snapGhost = el;
        }
        const el = this.snapGhost;
        el.style.left = `${rect.x}px`;
        el.style.top = `${rect.y}px`;
        el.style.width = `${rect.w}px`;
        el.style.height = `${rect.h}px`;
        el.hidden = false;
    }

    // Hide the snap ghost (Plan 5f, D2) -- called on EVERY drag end (snapped, not-snapped, or
    // pointercancel, Task 3) so a previewed ghost never lingers as a stuck overlay. Safe to call
    // when no ghost was ever created (a no-move grab+release) -- a plain no-op. Hides rather than
    // removes so the one element is reused; the [hidden] attr + the CSS display:none take it off-screen.
    hideSnapGhost() {
        if (this.snapGhost) {
            this.snapGhost.hidden = true;
        }
    }

    // N2: maximise/restore size the SURFACE only. The `.sx-window` fills its surface via a
    // standing CSS rule (`.sx-window-surface[data-sx-max='true'] > .sx-window { width:100% }`
    // in surface.css, set ONCE) -- the WM NEVER toggles width on the `.sx-window`
    // imperatively. That keeps the D3 boundary crisp: the WM owns the surface (geometry),
    // the morph owns the `.sx-window` (content), and neither writes the other's element. A
    // morph into a maximised window leaves data-sx-max + the surface geometry untouched, so
    // it stays maximised at the work-area size.
    maximise(windowId) {
        const surface = this.surfaceFor(windowId);
        if (!surface || surface.dataset.sxMax === 'true') {
            return;
        }
        // Remember the pre-max rect so restore is exact (D5). Width/height are read off the
        // SURFACE; sized records whether the window was explicitly resized (Plan 5d) so restore
        // can put the fill flag back -- without it a restored-resized window would have an explicit
        // surface size but the inner .sx-window wouldn't fill it (the [data-sx-sized] rule wouldn't
        // match). A never-resized window stashes '' / '' / false and restores by shrink-wrapping.
        surface.dataset.sxPreMax = JSON.stringify({
            x: Number(surface.dataset.sxX || 0),
            y: Number(surface.dataset.sxY || 0),
            w: surface.style.width,
            h: surface.style.height,
            sized: surface.dataset.sxSized === 'true',
        });
        surface.dataset.sxMax = 'true';
        // Fill the WORK AREA, inset off the panel's edge (Plan 5b, D7 + 5b-2 D6). The inset
        // side depends on panelPosition -- factored into applyMaximiseGeometry so the live
        // toggle (setPanelPosition) re-runs the SAME geometry for a maximised window.
        this.applyMaximiseGeometry(surface);
        this.swapControl(surface, 'maximise', 'restore');
        this.bring(windowId); // maximise is an interaction -- it raises + focuses
        // Persist the settled state (Plan 5e D3). The snapshot reads the RESTORE rect off the
        // stash written above (B1), with maximised:true on top -- NOT the work-area fill, so a
        // reloaded-maximised window restores to the right size. bring() above may also have
        // fired (a real raise); this guarantees the flag-change is saved even when already top.
        this.notifyGeometry(windowId);
    }

    restore(windowId) {
        const surface = this.surfaceFor(windowId);
        if (!surface || surface.dataset.sxMax !== 'true') {
            return;
        }
        const pre = JSON.parse(surface.dataset.sxPreMax || '{}');
        surface.dataset.sxMax = 'false';
        surface.style.width = pre.w || '';   // SURFACE width only; the .sx-window follows via CSS
        surface.style.height = pre.h || '';  // restore the stashed height (resized) or '' (shrink-wrap)
        // Round-trip the sized flag too (Plan 5d) -- a resized window keeps data-sx-sized so the
        // fill rule matches and the .sx-window fills its surface; a never-resized window drops the
        // flag so the surface falls back to width:max-content and shrink-wraps exactly as before.
        if (pre.sized) {
            surface.dataset.sxSized = 'true';
        } else {
            delete surface.dataset.sxSized;
        }
        this.move(surface, pre.x || 0, pre.y || 0);
        this.swapControl(surface, 'restore', 'maximise');
        // Persist the settled state (Plan 5e D3): maximised:false, the restore rect is current now.
        this.notifyGeometry(windowId);
    }

    toggleMaximise(windowId) {
        const surface = this.surfaceFor(windowId);
        if (surface?.dataset.sxMax === 'true') {
            this.restore(windowId);
        } else {
            this.maximise(windowId);
        }
    }

    // Sync a window's maximise/restore control to its CURRENT max state (Plan 5e). The control
    // row is rendered by the morph (window.js) -- it ALWAYS builds a `maximise` button. On boot
    // a window restored as MAXIMISED (applyGeometry set data-sx-max + filled the work area) has
    // no control yet (the tree hasn't hydrated), so applyGeometry can't swap it. The display
    // server calls this AFTER the first frame reconciles into the surface, so a reloaded-maximised
    // window shows the RESTORE control (and clicking it actually restores) instead of a dead
    // maximise button. A no-op for a non-maximised window or one whose control already matches.
    syncMaximiseControl(windowId) {
        const surface = this.surfaceFor(windowId);
        if (!surface) {
            return;
        }
        if (surface.dataset.sxMax === 'true' && surface.querySelector('[data-sx-control="maximise"]')) {
            this.swapControl(surface, 'maximise', 'restore');
        }
    }

    // Flip a chrome control button in place: rename data-sx-control, restyle, relabel, and
    // rewrite its inner SVG from the SHARED source (N1) -- window.js EXPORTS CONTROL_GLYPHS
    // and we import it, so the swap reuses the SAME glyph the renderer built it from. No
    // duplicated glyph markup here; the glyph is the user's maximise<->restore cue.
    swapControl(surface, from, to) {
        const btn = surface.querySelector(`[data-sx-control="${from}"]`);
        if (!btn) {
            return;
        }
        btn.dataset.sxControl = to;
        btn.className = `sx-window-control sx-window-control-${to}`;
        btn.setAttribute('aria-label', to);
        btn.title = to;
        const svg = btn.querySelector('svg');
        if (svg) {
            svg.innerHTML = CONTROL_GLYPHS[to];
        }
    }
}
