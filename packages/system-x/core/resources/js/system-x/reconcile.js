// Client-side keyed morph (Plan 3, D1/D2/D3). The live DOM IS the previous tree:
// every element carries data-sx-id and data-sx-type. We morph it toward the
// freshly-described new tree, matching slot-by-slot: TYPE first, then props.key
// (keyed collections), else position. Matched nodes are patched in place via the
// renderer's update() so focus/caret/selection/scroll on a reused element survive.
// Replaced/new nodes go through create(). Scoped to the surface only -- never
// touches siblings of it.
//
// ctx threading: reconcileChildren is attached to ctx EXACTLY ONCE here at the
// reconcile() entry, then passed unchanged through patch() -> renderer.update() ->
// ctx.reconcileChildren(...). Every level sees the same ctx object.

// Morph a single root node into `surface` (which holds at most one root child).
export function reconcile(surface, newTree, ctx) {
    const fullCtx = { ...ctx, reconcileChildren };
    const existing = surface.firstElementChild;

    if (!existing) {
        surface.appendChild(fullCtx.registry.render(newTree, fullCtx));
        return;
    }

    if (sameSlot(existing, newTree)) {
        patch(existing, newTree, fullCtx);
    } else {
        destroyTree(existing, fullCtx);
        surface.replaceChild(fullCtx.registry.render(newTree, fullCtx), existing);
    }
}

// Morph a container's children. Decides keyed vs positional per container.
// ctx is ALWAYS the fullCtx threaded from reconcile() -- it already carries
// reconcileChildren. We assert that here so a broken thread fails loudly.
//
// KEY EXPECTATION COMES FROM THE CONTAINER, not from inferring it from the
// children. A `list` container EXPECTS keys: even an all-unkeyed List is a bug we
// must shout about, not silently degrade to positional. We read that expectation
// off the container's own data-sx-type (stamped centrally by registry.render(),
// Task 2), so the signal cannot be lost. Stack/Window (structurally-fixed
// children) carry no key expectation and stay positional.
export function reconcileChildren(containerEl, newChildren, ctx) {
    if (typeof ctx.reconcileChildren !== 'function') {
        throw new Error('system-x: reconcileChildren called with a ctx that lost its reconcileChildren thread -- a renderer.update() did not pass ctx through');
    }

    const expectsKeys = containerEl.dataset.sxType === 'list';
    // "Has a key" means a NON-EMPTY key. normaliseKey() owns that definition so the
    // summary count here and the per-child check in reconcileKeyed() cannot drift --
    // both treat key === undefined and key === '' identically (a missing key).
    const keyedCount = newChildren.filter((c) => normaliseKey(c) !== null).length;
    const anyKeyed = keyedCount > 0;
    const allKeyed = newChildren.length === 0 || keyedCount === newChildren.length;

    // A `list` ALWAYS takes the keyed path -- even an all-unkeyed list -- so the
    // loud error below fires and the missing-key children are handled per-child.
    // Other containers take the keyed path only if a child actually carries a key.
    const goKeyed = expectsKeys || anyKeyed;

    if (goKeyed && !allKeyed) {
        // D3/D6: once we are on the keyed path, EVERY child must carry props.key.
        // This covers both an all-unkeyed `list` (expectsKeys true, anyKeyed false)
        // and a mixed sibling set (anyKeyed true, allKeyed false). Either way it is
        // a developer bug -- shout loudly so it surfaces in dev rather than silently
        // degrading to positional (rows rebuilt on reorder). reconcileKeyed below
        // then per-child falls back to positional placement for the unkeyed ones.
        console.error(`system-x: container <${containerEl.dataset.sxType} id=${containerEl.dataset.sxId}> is on the keyed path but ${newChildren.length - keyedCount} of ${newChildren.length} children are missing props.key -- a keyed container (List) requires a stable props.key on EVERY child. Falling back to positional for the unkeyed children; their state will not be preserved across reorder.`);
    }

    if (goKeyed) {
        reconcileKeyed(containerEl, newChildren, ctx);
    } else {
        reconcilePositional(containerEl, newChildren, ctx);
    }
}

// --- positional (structurally-fixed children: window content, stack) -----------
function reconcilePositional(containerEl, newChildren, ctx) {
    const olds = [...containerEl.children];

    newChildren.forEach((child, i) => {
        const old = olds[i];
        if (old && sameSlot(old, child)) {
            patch(old, child, ctx);
        } else {
            const fresh = ctx.registry.render(child, ctx);
            if (old) {
                destroyTree(old, ctx);
                containerEl.replaceChild(fresh, old);
            } else {
                containerEl.appendChild(fresh);
            }
        }
    });

    // Drop any trailing olds the new tree no longer has.
    for (let i = newChildren.length; i < olds.length; i++) {
        destroyTree(olds[i], ctx);
        olds[i].remove();
    }
}

// --- keyed (List items) --------------------------------------------------------
function reconcileKeyed(containerEl, newChildren, ctx) {
    // Snapshot the current children up front. `oldChildren` is the authoritative
    // set we must account for: at the end, ANY old child we did not reuse is removed.
    // The key map is only the fast lookup for matching -- it deliberately excludes
    // unkeyed/empty-keyed olds (they can't be matched), but those still have to be
    // cleaned up, so we track reuse against the full snapshot, not the map.
    const oldChildren = [...containerEl.children];
    const existingByKey = new Map();
    for (const el of oldChildren) {
        const k = el.dataset.sxKey;
        if (k !== undefined && k !== '') {
            existingByKey.set(k, el);
        }
    }

    const reused = new Set(); // old elements we kept (matched) -- everything else goes
    let cursor = null; // the element after which the next item should sit

    for (const child of newChildren) {
        // normaliseKey() returns a non-empty string or null. An EMPTY-STRING key is
        // treated as a missing key, NOT a real one: accepting '' would dodge this
        // error, never match an existing row (the map below is built off non-empty
        // keys only), and so silently RECREATE the row every frame -- exactly the
        // silent state-loss this module exists to prevent. So '' shouts here too.
        const key = normaliseKey(child);
        if (key === null) {
            // Loud per-child dev error (D3) -- a child on the keyed path without a
            // usable key is a real bug. reconcileChildren already emitted a summary
            // error; this pinpoints the offending child. It cannot be matched, so it
            // falls back to POSITIONAL placement (built fresh, inserted in document
            // order) and is recreated each render until it gets a stable props.key.
            console.error(`system-x: List child <${child.type} id=${child.id}> has an empty or missing props.key -- it cannot be matched, so it falls back to positional placement and will be recreated each render`);
        }

        const stale = key !== null ? existingByKey.get(key) : undefined;
        let el;

        if (stale && sameSlot(stale, child)) {
            // Same key, same type -> patch the existing element in place. patch()
            // returns the element that ENDED UP in the DOM: normally `stale` itself
            // (in-place update), but if the matched type lost its renderer mid-session
            // patch() replaces it with the placeholder (D5) and returns the NEW node.
            // We must position THAT node below, not the now-detached `stale`, or the
            // detached orphan gets re-inserted alongside the replacement (a leak).
            el = patch(stale, child, ctx);
            reused.add(stale); // keep this old element across the final cleanup
        } else {
            // New key, same key but TYPE changed, OR an unkeyed/empty-keyed child
            // (positional fallback, recreated each frame). Build a fresh element.
            // data-sx-key, when present and non-empty, is stamped CENTRALLY by
            // registry.render() (Task 2) -- including for a row that changed type,
            // whose fresh element is built by the NEW type's create(). We do NOT
            // remove `stale` here: it is left in oldChildren and the final cleanup
            // sweep removes it (it was never added to `reused`), which also covers
            // unkeyed olds the per-key cleanup used to miss.
            el = ctx.registry.render(child, ctx);
        }

        // Place el right after cursor, MOVING (not recreating) if it already exists.
        const next = cursor ? cursor.nextSibling : containerEl.firstChild;
        if (el !== next) {
            containerEl.insertBefore(el, next);
        }
        cursor = el;
    }

    // Remove every old child we did not reuse. Sweeping the full snapshot (not just
    // the keyed map) means dropped keyed rows AND last frame's unkeyed/empty-keyed
    // positional-fallback elements are both cleaned up -- no accumulation. Freshly
    // built elements are not in oldChildren, so they are never touched here.
    for (const old of oldChildren) {
        if (!reused.has(old)) {
            destroyTree(old, ctx);
            old.remove();
        }
    }
}

// Walk `el` + every descendant carrying data-sx-type and call the renderer's OPTIONAL
// destroy(el, ctx) -- the deterministic teardown hook (listeners, portaled popups, focus
// traps). The CALLER removes the element afterwards; destroyTree only runs the hooks.
// data-sx-type is stamped centrally by registry.render(), so every element the reconciler
// sees is enumerable here. Renderers without destroy() are untouched (backward-compatible).
export function destroyTree(el, ctx) {
    if (!el || el.nodeType !== 1) return;
    const nodes = [el, ...el.querySelectorAll('[data-sx-type]')];
    for (const node of nodes) {
        const type = node.dataset?.sxType;
        if (!type) continue;
        const renderer = ctx.registry?.get?.(type);
        if (typeof renderer?.destroy === 'function') renderer.destroy(node, ctx);
    }
}

// --- shared helpers ------------------------------------------------------------
// A slot matches when the TYPE matches. data-sx-type is stamped CENTRALLY by
// registry.render() (Task 2), so it is guaranteed present on every element the
// reconciler ever sees -- no renderer can forget it and silently break the morph.
function sameSlot(el, node) {
    return el.dataset.sxType === node.type;
}

// The single source of truth for "does this child have a usable key?". Returns the
// key as a non-empty string, or null when it is undefined OR empty -- both of which
// are missing keys for our purposes. reconcileChildren (summary count) and
// reconcileKeyed (per-child error + matching) BOTH go through this, so the loud
// diagnostic and the actual matching can never disagree on what counts as a key.
function normaliseKey(child) {
    const key = child.props?.key;
    if (key === undefined || key === null) {
        return null;
    }
    const str = String(key);
    return str === '' ? null : str;
}

// Patch a matched element in place and return the element now occupying the slot.
// Normally that is `el` itself (renderer.update mutates it). But an unknown type
// at a matched slot is REPLACED, so we return the fresh placeholder -- callers
// (notably reconcileKeyed) must position the returned node, not the old detached
// one, or the replaced element leaks back into the DOM.
function patch(el, node, ctx) {
    const renderer = ctx.registry.get(node.type);
    if (!renderer) {
        // Unknown type at a matched slot: replace with the placeholder (D5). This
        // destroys any focus/scroll on that element, but a type that lost its
        // renderer mid-session is a degraded state we only warn about, not preserve.
        const placeholder = ctx.registry.render(node, ctx);
        destroyTree(el, ctx);
        el.replaceWith(placeholder);
        return placeholder;
    }
    renderer.update(el, node, ctx);
    return el;
}
