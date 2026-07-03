<?php

namespace SystemX\Core\Support;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use SystemX\Core\Apps\Installs\AppInstallService;
use SystemX\Core\Launcher\LauncherLayoutService;
use SystemX\Core\Preferences\PreferencesService;
use SystemX\Core\Runtime\AppRegistry;
use SystemX\Core\State\StateKey;
use SystemX\Core\Wm\OpenWindowService;

// The package-owned desktop boot-data assembly (packaging plan, Task 9). This LIFTS the host
// `/` route body verbatim so the package renders its OWN system-x::desktop view -- a fresh
// consumer gets a working desktop with no special bootstrap. It is auth-agnostic: it just reads
// $request->user(). The remember-cookie queue + the auth gate stay in the host route wrapper
// (the consumer owns the auth lane).
class Desktop
{
    public function __construct(
        private OpenWindowService $openWindows,
        private AppRegistry $apps,
        private PreferencesService $preferences,
        private AppInstallService $installs,
        private LauncherLayoutService $layout,
    ) {}

    public function render(Request $request): View
    {
        // The channel id is now the authenticated user id (D6) -- no more anonymous
        // sx_desktop_id uuid. The route is auth-gated, so $request->user() is always
        // present here. The shell still owns NO window id and reads NO per-window count;
        // the two static windows self-hydrate via GET /system-x/desktop?window={slug} (D8).

        // First-boot seed of the per-user open-window SET, ONCE EVER (seed-once-ever fix).
        // Gated by the per-user desktop_seeded_at marker, NOT by "is the set empty": a brand-new
        // user gets the welcome pair (hello+notes) on their first boot, and we stamp them seeded.
        // After that, closing every window leaves the desktop EMPTY across refreshes (real-OS
        // behaviour) -- we never re-seed. The shell then READS this set and hands the blade the
        // user's ACTUAL open rows (the seeded pair on first boot, plus any launched windows).
        $principal = new StateKey('user', (string) $request->user()->id, '');
        if (! $this->preferences->hasSeededDesktop($principal)) {
            $this->openWindows->seedDefaults($principal);
            $this->preferences->markDesktopSeeded($principal);
        }

        // App metadata (Plan 5b, D2): join each open window's title + icon from its App so the
        // panel can label it, and hand the launcher the registered apps' metadata grid. N1:
        // metadata() is a resolve loop, and the route needs it twice (the per-window join + the
        // launcher list) -- compute it ONCE and reuse it.
        $meta = $this->apps->metadata();
        $metaBySlug = collect($meta)->keyBy('slug');

        // The per-user launcher set (App-install plan, D2): the boot 'apps' blob is the launcher's
        // source, filtered to system || !uninstalled. A system app always passes ($a['system']
        // short-circuits -- furniture is never uninstallable). This filter is the launcher set ONLY:
        // the registry metadata() above is untouched (the global source of truth), and the per-window
        // label join below keeps reading the FULL $meta/$metaBySlug -- an open window of an uninstalled
        // app still needs its title/icon (the landmine).
        $uninstalled = $this->installs->uninstalledFor($principal);
        $launcherApps = collect($meta)
            ->filter(fn (array $a): bool => $a['system'] || ! in_array($a['slug'], $uninstalled, true))
            ->values()
            ->all();

        // The per-user folder arrangement (Plan 4a), reconciled against the live launcher set so it
        // can never strand a launch or hide a new app. A fresh user (no row) reconciles to a flat
        // root layout = today's grid.
        //
        // Reconcile against USER apps only (!system): the launcher grid is a user-app arrangement --
        // system furniture (Appearance/About) lives in the user-icon menu, never the grid. Feeding
        // the system slugs here would append them to a fresh user's layout (and keep any that a stale
        // row placed), and since the client renders straight from the layout that would leak a system
        // tile past the client's !system filter. So the layout only ever sees user slugs.
        $launcherSlugs = collect($launcherApps)
            ->reject(fn (array $a): bool => $a['system'])
            ->pluck('slug')
            ->all();
        $launcherLayout = LauncherLayoutService::reconcile(
            $this->layout->layoutFor($principal),
            $launcherSlugs,
        );
        $windows = collect($this->openWindows->forPrincipal($principal))
            ->map(fn (array $w): array => [
                ...$w, // window + app (5a) + geometry x/y/w/h/sized/maximised/minimised/z (5e)
                'title' => $metaBySlug[$w['app']]['title'] ?? ucfirst($w['app']),
                'icon' => $metaBySlug[$w['app']]['icon'] ?? 'window',
            ])
            ->all();

        // The per-user look (Plan 5b-2, D4): the durable theme/accent/wallpaper/panel_position,
        // defaults merged in for a brand-new user. The blade STAMPS these server-side so the
        // desktop paints in the user's theme on the first byte -- no modern-to-pewter flash.
        $prefs = $this->preferences->forPrincipal($principal);

        // The runtime client config (packaging spec §4): baseUrl + csrf + logoutUrl + the
        // consumer's Reverb connection, emitted as window.sxConfig. The pre-built bundle reads
        // it at runtime so nothing consumer-specific is baked at build time.
        $sxConfig = (new ClientConfig)->forRequest($request);

        return view('system-x::desktop', [
            'desktopId' => (string) $request->user()->id,
            'windows' => $windows,           // [{window, app, title, icon, geometry...}, ...] (D2/D7)
            // The launcher's app grid (D2/D5) -- the per-user FILTERED set (system || !uninstalled),
            // not the raw registry. The launcher reads each app's title + icon to render its grid tiles
            // (and client-filters !system). The per-window join above still uses the full $meta.
            'apps' => $launcherApps,
            'layout' => $launcherLayout,       // the reconciled per-user folder arrangement (Plan 4a)
            // The user's name for the system-menu header + the tray initials (plan system-menu,
            // D5). It's not JS-readable any other way (it lives in the encrypted httpOnly
            // remember-cookie), so the blade stamps it into the hardened boot blob.
            'userName' => $request->user()->name,
            'prefs' => $prefs,               // the server-side no-flash stamp (D4)
            'sxConfig' => $sxConfig,         // window.sxConfig (packaging spec §4)
        ]);
    }
}
