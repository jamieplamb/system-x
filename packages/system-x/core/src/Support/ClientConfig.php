<?php

namespace SystemX\Core\Support;

use Illuminate\Http\Request;

// The runtime client config (packaging spec §4). A single object the Blade shell emits as
// window.sxConfig so the pre-built bundle carries NOTHING consumer-specific: baseUrl (route
// prefix -- '' at the app root), the CSRF token, the logout URL (auth is the consumer's
// concern), and the consumer's Reverb connection (read from THEIR broadcasting config, never
// baked at build time). echo.js + transport.js + display-server.js read this at runtime.
class ClientConfig
{
    /** @return array<string, mixed> */
    public function forRequest(Request $request): array
    {
        // baseUrl: the path the app is served under ('' for root). transport.js prefixes every
        // /system-x/* endpoint with it, so a sub-directory install still resolves.
        $baseUrl = rtrim(parse_url(config('app.url'), PHP_URL_PATH) ?? '', '/');

        return [
            'baseUrl' => $baseUrl,
            'csrfToken' => $request->session()->token(),
            // logoutUrl: auth (incl. logout) is the consumer's concern, so the tray logout form
            // action must NOT be a baked '/logout'. Default to Laravel's '/logout'; a consumer
            // overrides via the published system-x config. display-server.js reads this (Task 4).
            'logoutUrl' => config('system-x.logout_url', '/logout'),
            'reverb' => [
                // The CLIENT-FACING keys: broadcasting.connections.reverb.{key, options.*} map to
                // REVERB_APP_KEY / REVERB_HOST / REVERB_PORT(443) / REVERB_SCHEME -- exactly what
                // the old echo.js read as VITE_REVERB_*. NOT reverb.servers.reverb.host (the
                // 0.0.0.0 bind addr). Leave host null if unset so echo.js's !reverb.host warn fires.
                'key' => config('broadcasting.connections.reverb.key'),
                'host' => config('broadcasting.connections.reverb.options.host'),
                'port' => (int) config('broadcasting.connections.reverb.options.port', 443),
                'scheme' => config('broadcasting.connections.reverb.options.scheme', 'https'),
            ],
        ];
    }
}
