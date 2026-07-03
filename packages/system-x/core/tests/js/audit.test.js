import { describe, it, expect, beforeEach, vi } from 'vitest';
import { renderAuditView } from '../../resources/js/system-x/audit.js';

describe('renderAuditView', () => {
    let surface;
    beforeEach(() => {
        document.body.replaceChildren();
        surface = document.createElement('div');
        surface.innerHTML = '<div class="sx-audit" data-sx-audit></div>';
        document.body.appendChild(surface);
    });

    it('paints one row per activity entry with its changes nested', async () => {
        const fetchAudit = vi.fn().mockResolvedValue({
            data: [{ correlation_id: 'c1', app: 'notes', event: 'click', outcome: 'ok',
                     created_at: '2026-06-29T14:22:00+00:00',
                     changes: [{ property: 'message', old: '', new: 'hi' }] }],
        });

        await renderAuditView(surface, fetchAudit);

        const rows = surface.querySelectorAll('[data-sx-audit-row]');
        expect(rows.length).toBe(1);
        expect(rows[0].textContent).toContain('notes');
        expect(rows[0].textContent).toContain('message');
    });

    it('shows an empty-state when there is no activity', async () => {
        await renderAuditView(surface, vi.fn().mockResolvedValue({ data: [] }));
        expect(surface.querySelector('[data-sx-audit]').textContent).toMatch(/no activity/i);
    });

    it('renders values via textContent (no HTML injection)', async () => {
        const fetchAudit = vi.fn().mockResolvedValue({
            data: [{ correlation_id: 'c1', app: '<img src=x>', event: 'click', outcome: 'ok',
                     created_at: '2026-06-29T14:22:00+00:00', changes: [] }],
        });
        await renderAuditView(surface, fetchAudit);
        expect(surface.querySelector('img')).toBeNull();
    });
});
