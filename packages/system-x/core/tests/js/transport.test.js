import { describe, it, expect, beforeEach, vi } from 'vitest';
import * as transport from '../../resources/js/system-x/transport.js';

describe('transport uses sxConfig.baseUrl + csrf', () => {
    beforeEach(() => {
        window.sxConfig = { baseUrl: '/app', csrfToken: 'tok-1', reverb: {} };
        globalThis.fetch = vi.fn().mockResolvedValue({ json: async () => ({}) });
    });

    it('prefixes the desktop GET with baseUrl', async () => {
        await transport.fetchDesktop('w1');
        expect(fetch).toHaveBeenCalledWith(expect.stringContaining('/app/system-x/desktop?window=w1'), expect.anything());
    });

    it('sends the csrf token from sxConfig on a POST', async () => {
        await transport.sendEvent({ widget: 'x', event: 'click', window: 'w1' });
        const [, opts] = fetch.mock.calls.at(-1);
        expect(opts.headers['X-CSRF-TOKEN']).toBe('tok-1');
    });
});
