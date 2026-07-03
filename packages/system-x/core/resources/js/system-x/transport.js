// Runtime config (packaging spec §4): the desktop view emits window.sxConfig. Every endpoint is
// baseUrl-prefixed so a sub-directory install resolves; the csrf token comes from sxConfig (the
// <meta> tag stays as a belt-and-braces fallback for the GET-only resync).
function base() {
    return window.sxConfig?.baseUrl ?? '';
}

function csrfToken() {
    return window.sxConfig?.csrfToken
        ?? document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
        ?? '';
}

// Window-scoped resync (D8): each static window pulls its OWN bag via ?window={slug},
// which the resolver reads through input('window') (the query string on a GET). The
// session window-id fallback is GONE (Plan 4c, D5) -- a request with no `window`
// resolves no key (null), so every real caller sends one; the unscoped GET branch
// below is a defensive default, not a keying path.
export async function fetchDesktop(windowId) {
    const url = windowId ? `${base()}/system-x/desktop?window=${encodeURIComponent(windowId)}` : `${base()}/system-x/desktop`;
    const res = await fetch(url, { headers: { Accept: 'application/json' } });
    return res.json();
}

// Upstream is fire-and-forget now: the resulting tree arrives over the WS channel,
// not in this response. We still POST so the server can do its authoritative work.
export async function sendEvent(payload) {
    const res = await fetch(`${base()}/system-x/event`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify(payload),
    });
    if (res.status === 204 || !res.ok) return null; // success ack (no body) or a hard failure
    return res.json().catch(() => null);            // 200 {error} -> the parsed body
}

// Launch a window (Plan 5a, D7). POSTs the app slug; the server mints (or returns the
// existing singleton) window and answers with {app, window, tree} -- the initial tree the
// client paints into a freshly minted surface. CSRF-protected like the event POST.
export async function launchWindow(app) {
    const res = await fetch(`${base()}/system-x/wm/launch`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify({ app }),
    });

    return res.json(); // { app, window, tree }
}

// Persist a preference (Plan 5b-2, D2) -- fire-and-forget, like closeWindow. The apply
// already happened client-side (prefs.js set the root attribute, the desktop reskinned);
// this just durably records it. No response is read, no broadcast comes back. CSRF-protected
// like the other POSTs.
export async function savePref(key, value) {
    await fetch(`${base()}/system-x/prefs`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify({ key, value }),
    });
}

// Close a window (Plan 5a, D7). Fire-and-forget like the event POST: the surface is already
// gone client-side the instant you click close (optimistic). The server validates the window
// is in this user's open-set, drops the open-row, and FORGETS the durable bag (explicit close
// reaps the state; a reload/disconnect retains it). CSRF-protected like the other POSTs.
export async function closeWindow(window) {
    await fetch(`${base()}/system-x/wm/close`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify({ window }),
    });
}

// Uninstall an app (App-install plan, D3) -- fire-and-forget, like closeWindow. The client
// already removed the tile + the app's surfaces (optimistic, live); the server atomically closes
// the app's open windows, forgets their state, and marks it uninstalled. No response is read.
// CSRF-protected like the other POSTs.
export async function uninstallApp(app) {
    await fetch(`${base()}/system-x/app/uninstall`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify({ app }),
    });
}

// Install an app (App-install plan, D3) -- fire-and-forget, like uninstallApp. The client already
// re-added the tile (optimistic); the server drops the uninstalled row so the app shows + launches
// again. No response is read. CSRF-protected like the other POSTs.
export async function installApp(app) {
    await fetch(`${base()}/system-x/app/install`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify({ app }),
    });
}

// Persist the launcher layout (Slice 4a) -- fire-and-forget, like installApp. The client already
// applied the layout change client-side (the grid repainted); this durably records the reconciled
// {type:'app'|'folder'} item list onto the user's launcher-layout row. No response is read.
// CSRF-protected like the other POSTs.
export async function saveLayout(layout) {
    await fetch(`${base()}/system-x/launcher/layout`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify({ layout }),
    });
}

// Fetch the viewer-scoped audit trail (audit plan §7). GET, no CSRF needed -- mirrors
// fetchDesktop's shape: Accept header, return res.json(). The endpoint is guarded by the
// normal session cookie; no window scoping needed (audit is global-per-user).
export async function fetchAudit() {
    const res = await fetch(`${base()}/system-x/audit`, { headers: { Accept: 'application/json' } });
    return res.json();
}

// Persist a window's settled geometry (Plan 5e, D3) -- fire-and-forget, like closeWindow. The WM
// already applied the move/resize/max/min client-side; this just durably records the snapshot
// (x/y/w/h/sized/maximised/minimised/z) onto the user's open-window row. The server validates the
// window is in this user's open-set (the isOpen guard), coerces the fields, and updates the row.
// No response is read, no broadcast comes back. CSRF-protected like the other POSTs.
export async function saveGeometry(windowId, geometry) {
    await fetch(`${base()}/system-x/wm/geometry`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify({ window: windowId, ...geometry }),
    });
}
