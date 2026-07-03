import { describe, it, expect, beforeEach, vi } from 'vitest';

// Stub laravel-echo and pusher-js so the import side-effect doesn't try to
// open a real WebSocket under jsdom. The Echo constructor just captures its
// config; Pusher is a no-op class (echo.js sets window.Pusher then newing
// Echo internally relies on it as a constructor in pusher-connector).
vi.mock('laravel-echo', () => ({
    default: class EchoStub {
        constructor(config) { this._config = config; }
    },
}));
vi.mock('pusher-js', () => ({
    default: class PusherStub {},
}));

describe('echo.js reads runtime sxConfig.reverb', () => {
    beforeEach(() => {
        vi.resetModules();
        window.sxConfig = undefined;
        window.Echo = undefined;
    });

    it('builds Echo from window.sxConfig.reverb', async () => {
        window.sxConfig = { reverb: { key: 'k', host: 'ws.test', port: 9000, scheme: 'https' } };
        await import('../../resources/js/echo.js');
        expect(window.Echo).toBeDefined();
    });

    it('passes the right wsHost and key to Echo', async () => {
        window.sxConfig = { reverb: { key: 'my-key', host: 'reverb.test', port: 6001, scheme: 'https' } };
        await import('../../resources/js/echo.js');
        expect(window.Echo._config.wsHost).toBe('reverb.test');
        expect(window.Echo._config.key).toBe('my-key');
    });

    it('defaults port to 8080 when reverb.port is absent', async () => {
        window.sxConfig = { reverb: { key: 'k', host: 'h.test', scheme: 'https' } };
        await import('../../resources/js/echo.js');
        expect(window.Echo._config.wsPort).toBe(8080);
    });

    it('sets forceTLS false when scheme is ws', async () => {
        window.sxConfig = { reverb: { key: 'k', host: 'h.test', port: 8080, scheme: 'ws' } };
        await import('../../resources/js/echo.js');
        expect(window.Echo._config.forceTLS).toBe(false);
    });

    it('still sets window.Echo when sxConfig.reverb is missing (warns, does not throw)', async () => {
        window.sxConfig = {};
        await import('../../resources/js/echo.js');
        expect(window.Echo).toBeDefined();
    });
});
