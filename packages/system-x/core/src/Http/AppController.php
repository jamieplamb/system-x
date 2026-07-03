<?php

namespace SystemX\Core\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use SystemX\Core\Apps\Installs\AppInstallService;
use SystemX\Core\Audit\AuditContext;
use SystemX\Core\Audit\AuditRecorder;
use SystemX\Core\Runtime\AppRegistry;
use SystemX\Core\State\StateKey;
use SystemX\Core\State\StateStore;
use SystemX\Core\Wm\OpenWindowService;

// The app install/uninstall endpoints (App-install plan, D3). Uninstall is a per-user
// SUBTRACTIVE mark with an ATOMIC server cleanup: it closes the app's open windows + forgets
// their state + marks the app uninstalled, all in one DB::transaction (close+forget BEFORE
// the mark, so a failed cleanup never marks-then-leaves-windows). Install just unmarks. A
// system app can NEVER be uninstalled (403 -- can't uninstall furniture). Both auth+web gated.
class AppController
{
    public function __construct(
        private AppRegistry $apps,
        private OpenWindowService $openWindows,
        private StateStore $store,
        private AppInstallService $installs,
        private AuditRecorder $audit,
    ) {}

    public function uninstall(Request $request): Response
    {
        $app = (string) $request->input('app');
        $userId = (string) $request->user()->id;
        $principal = new StateKey('user', $userId, '');

        $this->assertUninstallable($app);

        // S3 -- the cleanup spans three tables (open_windows + window_states + uninstalled_apps),
        // so it runs in a single transaction. ORDER: close every open window of the app via the
        // RAW set op (OpenWindowService::close, NOT WmController::close -- the HTTP action's isOpen
        // 403 would interfere, S2) + forget each window's bag, THEN mark uninstalled LAST. A failed
        // cleanup rolls back, so we never mark-then-leave-windows. The (principal, app) singleton
        // index means one window per app today, but the loop is future-proof.
        DB::transaction(function () use ($principal, $app, $userId, $request): void {
            foreach ($this->openWindows->forPrincipal($principal) as $window) {
                if ($window['app'] === $app) {
                    $this->openWindows->close($principal, $window['window']);
                    $this->store->forget(new StateKey('user', $userId, $window['window']));
                }
            }

            $this->installs->uninstall($principal, $app);

            $this->audit->record(AuditContext::forRequest($request, $app), 'app.uninstall', 'ok');
        });

        return response()->noContent();
    }

    public function install(Request $request): Response
    {
        $app = (string) $request->input('app');
        $principal = new StateKey('user', (string) $request->user()->id, '');

        $this->assertUninstallable($app);

        // Unmark: drop the uninstalled row so the app shows + launches again (the subtractive set
        // shrinks). No window/state cleanup -- install brings the tile back, not the old windows.
        $this->installs->install($principal, $app);

        $this->audit->record(AuditContext::forRequest($request, $app), 'app.install', 'ok');

        return response()->noContent();
    }

    // The shared validation for both endpoints: the app must be REGISTERED (else 422 -- like
    // launch's has() guard) AND NOT system (else 403 -- a system app is permanent furniture,
    // never (un)installable). Reading the system flag off metadata() keeps the App the source.
    private function assertUninstallable(string $app): void
    {
        if (! $this->apps->has($app)) {
            abort(422, 'Unknown app.');
        }

        $meta = collect($this->apps->metadata())->firstWhere('slug', $app);
        if ($meta['system']) {
            abort(403, 'Cannot uninstall a system app.');
        }
    }
}
