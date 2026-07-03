// Graceful degradation for a type with no registered renderer (D5): a visible
// placeholder + a warning, never a thrown error that nukes the whole tree. The
// placeholder stamps its own data-sx-type so it matches itself across morphs (it
// does NOT go through the registry's central stamping -- it IS the fallback).
export function unknownPlaceholder(node) {
    const el = document.createElement('div');
    el.className = 'sx-unknown';
    el.dataset.sxId = node.id ?? '';
    el.dataset.sxType = node.type ?? '';
    // Stamp the reconciliation key too. The placeholder bypasses the registry's
    // central stamping (it IS the fallback), so without this a keyed list row of
    // an unregistered type would lack data-sx-key and the keyed matcher would fail
    // to find it next frame -- recreating the placeholder every render (thrash).
    // An empty-string key is not a real key (matcher treats '' as missing), so we
    // skip it here too, mirroring the registry's central stamping.
    if (node.props?.key !== undefined && String(node.props.key) !== '') {
        el.dataset.sxKey = String(node.props.key);
    }
    el.textContent = `system-x: no renderer for "${node.type}"`;
    return el;
}
