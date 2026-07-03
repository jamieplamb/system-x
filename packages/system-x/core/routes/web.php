<?php

use Illuminate\Support\Facades\Route;
use SystemX\Core\Http\AppController;
use SystemX\Core\Http\AssetController;
use SystemX\Core\Http\AuditController;
use SystemX\Core\Http\DesktopController;
use SystemX\Core\Http\LauncherController;
use SystemX\Core\Http\PreferencesController;
use SystemX\Core\Http\WmController;

// Assets are public + long-cached -- no session, no auth, no group.
// The 'where' constraint allows only [A-Za-z0-9._-], blocking any slash-based traversal at
// the router level before the controller even runs.
Route::get('/system-x/assets/{file}', [AssetController::class, 'serve'])
    ->where('file', '[A-Za-z0-9._-]+');

// Vendor client bundles (4c-ii): a third-party package's widget JS/CSS, resolved by namespace
// to its registered dist dir. Public + long-cached like core assets; the {namespace} + {file}
// constraints block slashes, and serveVendor rejects '..' + 404s an unknown namespace.
Route::get('/system-x/vendor/{namespace}/{file}', [AssetController::class, 'serveVendor'])
    ->where('namespace', '[A-Za-z0-9._-]+')
    ->where('file', '[A-Za-z0-9._-]+');

// The 'web' group gives these routes a session store (StartSession), which the
// desktop id is minted into and read back from. Without it $request->session()
// has no backing store. The 'auth' gate (D3) bounces a guest -- 401 on these JSON
// endpoints -- so no anonymous request ever reaches the controller or the store.
Route::middleware(['web', 'auth'])->group(function (): void {
    Route::get('/system-x/desktop', [DesktopController::class, 'desktop']);
    Route::post('/system-x/event', [DesktopController::class, 'event']);

    // The window lifecycle (Plan 5a, D7): launch mints + opens a window, close forgets it.
    Route::post('/system-x/wm/launch', [WmController::class, 'launch']);
    Route::post('/system-x/wm/close', [WmController::class, 'close']);

    // The settled-geometry persist (Plan 5e, D3): the client POSTs a window's settled geometry
    // fire-and-forget; the isOpen guard limits it to the user's own open windows.
    Route::post('/system-x/wm/geometry', [WmController::class, 'saveGeometry']);

    // The per-user preference persist (Plan 5b-2, D2): apply client-side, persist here.
    Route::post('/system-x/prefs', [PreferencesController::class, 'store']);

    // The per-user launcher layout persist (Plan 4a): the client owns the arrangement + posts the
    // whole document; the server validates-and-rejects (422), never silently reshapes.
    Route::post('/system-x/launcher/layout', [LauncherController::class, 'saveLayout']);

    // The app install/uninstall (App-install plan, D3): uninstall atomically closes the app's
    // open windows + forgets their state + marks it uninstalled; install unmarks. A system app
    // 403s. The launch guard (in WmController::launch) refuses a forged launch of an uninstalled app.
    Route::post('/system-x/app/uninstall', [AppController::class, 'uninstall']);
    Route::post('/system-x/app/install', [AppController::class, 'install']);

    // The audit viewer read (audit plan §7): the current user's recent trail, viewer-scoped.
    Route::get('/system-x/audit', [AuditController::class, 'index']);
});
