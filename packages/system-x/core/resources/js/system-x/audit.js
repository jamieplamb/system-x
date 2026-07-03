// The Audit viewer client (audit plan §7). The Audit app's render() is a Raw shell with a
// [data-sx-audit] mount; when that surface appears the display server calls renderAuditView,
// which fetches the viewer-scoped trail and paints it. ALL text via textContent.

function entryRow(entry) {
    const row = document.createElement('div');
    row.className = 'sx-audit-row';
    row.setAttribute('data-sx-audit-row', '');

    const head = document.createElement('div');
    head.className = 'sx-audit-row-head';
    const time = new Date(entry.created_at);
    const hhmm = Number.isNaN(time.valueOf()) ? '' : time.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    head.textContent = `${hhmm} · ${entry.app} · ${entry.event} · ${entry.outcome}`;
    row.appendChild(head);

    for (const change of entry.changes ?? []) {
        const line = document.createElement('div');
        line.className = 'sx-audit-change';
        line.textContent = `${change.property}: ${JSON.stringify(change.old)} → ${JSON.stringify(change.new)}`;
        row.appendChild(line);
    }
    return row;
}

export async function renderAuditView(surface, fetchAudit) {
    const mount = surface.querySelector('[data-sx-audit]');
    if (!mount) {
        return;
    }

    let payload;
    try {
        payload = await fetchAudit();
    } catch {
        mount.replaceChildren();
        mount.textContent = 'Could not load the audit trail.';
        return;
    }

    const entries = payload?.data ?? [];
    mount.replaceChildren();

    if (entries.length === 0) {
        mount.textContent = 'No activity yet.';
        return;
    }

    for (const entry of entries) {
        mount.appendChild(entryRow(entry));
    }
}
