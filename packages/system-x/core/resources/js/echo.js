import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

// Runtime config (packaging spec §4): the Blade shell emits window.sxConfig.reverb server-side
// from the CONSUMER's Reverb config, so this pre-built bundle carries no baked host. A missing
// config means the page didn't emit sxConfig -- warn loudly (Echo would otherwise dial the origin).
const reverb = window.sxConfig?.reverb ?? {};

if (!reverb.host) {
    console.warn('[system-x] window.sxConfig.reverb is missing -- the desktop view must emit it. Echo will dial the page origin.');
}

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: reverb.key,
    wsHost: reverb.host,
    wsPort: Number(reverb.port) || 8080,
    wssPort: Number(reverb.port) || 8080,
    forceTLS: (reverb.scheme ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});
