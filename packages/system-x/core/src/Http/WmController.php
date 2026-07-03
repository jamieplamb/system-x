<?php

namespace SystemX\Core\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use SystemX\Core\Apps\Installs\AppInstallService;
use SystemX\Core\Audit\AuditContext;
use SystemX\Core\Audit\AuditRecorder;
use SystemX\Core\Runtime\AppKernel;
use SystemX\Core\Runtime\AppRegistry;
use SystemX\Core\State\StateKey;
use SystemX\Core\State\StateStore;
use SystemX\Core\Wm\OpenWindowService;

// The window lifecycle endpoints (Plan 5a, D7). launch mints a window instance; close
// forgets it. Geometry/focus/z are CLIENT-only and never reach here (D1) -- these two
// endpoints are the ONLY server-side WM concept. Both are auth+web gated (4c).
class WmController
{
    public function __construct(
        private OpenWindowService $openWindows,
        private AppKernel $kernel,
        private AppRegistry $apps,
        private StateStore $store,
        private AppInstallService $installs,
        private AuditRecorder $audit,
    ) {}

    public function launch(Request $request): JsonResponse
    {
        $app = (string) $request->input('app');
        if (! $this->apps->has($app)) {
            abort(422, 'Unknown app.');
        }

        // The principal for the open-SET (windowId is irrelevant to set ops). Build it
        // DIRECTLY from the authed user -- the route is auth-gated so user() is always
        // present, and abusing resolve() with a fake window is awkward (see landmine).
        $principal = new StateKey('user', (string) $request->user()->id, '');

        // The launch GUARD (App-install plan, D3, S2): an uninstalled NON-system app is not
        // launchable -- a forged POST 403s. Slots AFTER has() and BEFORE the firstOrCreate, else
        // the forged launch would mint the open-row before the guard fires. System apps SKIP it
        // (Appearance/About/Manage-apps always launch from the menu); the client tile is already
        // gone, this is the server enforcement.
        $meta = collect($this->apps->metadata())->firstWhere('slug', $app);
        if (! $meta['system'] && $this->installs->isUninstalled($principal, $app)) {
            abort(403, 'App not installed.');
        }

        // Singleton-per-app (S4): launch() firstOrCreates on (user, app), so a repeat
        // POST returns the EXISTING window rather than minting a duplicate. The response
        // is shaped identically either way -- the client mints-or-focuses by window id.
        $row = $this->openWindows->launch($principal, $app);

        $this->audit->record(AuditContext::forRequest($request, $app, $row->window_id), 'window.launch', 'ok');

        // Born stateless: render the initial tree from an EMPTY bag, NO store write. The
        // window's first real bag is created on its first event (like the static pair).
        // (For a re-returned existing window the tree is its initial shape; the client's
        // own resync/state still keys on the window id, so no state is clobbered.)
        $tree = $this->kernel->renderFromBag($app, []);

        // The launch response carries the app's metadata (D2) so the client can label the new
        // window's panel button without a second round-trip. $meta was already joined above for
        // the guard -- reuse it rather than re-resolving the registry.
        return response()->json([
            'app' => $app,
            'window' => $row->window_id,
            'title' => $meta['title'],
            'icon' => $meta['icon'],
            'tree' => $tree,
        ]);
    }

    public function close(Request $request): Response
    {
        $window = (string) $request->input('window');
        $principal = new StateKey('user', (string) $request->user()->id, '');

        // The auth point (D7): you may only close YOUR OWN open window. A forged window id,
        // an already-closed one, or another user's window is NOT in this user's open-set, so
        // it's a 403 that touches no state -- it can't drop a row or forget a bag it doesn't own.
        if (! $this->openWindows->isOpen($principal, $window)) {
            abort(403);
        }

        // Resolve the app BEFORE closing -- appFor returns null after the row is gone.
        $app = $this->openWindows->appFor($principal, $window);

        // Drop the open-row (the service owns the SET) AND forget the bag (the store owns the
        // bytes, D3). Explicit close FORGETS the durable state (D7) -- a reload/disconnect does
        // NOT call this, it just re-reads the retained bag, which is the restore path.
        $this->openWindows->close($principal, $window);
        $this->store->forget(new StateKey('user', (string) $request->user()->id, $window));

        if ($app !== null) {
            $this->audit->record(AuditContext::forRequest($request, $app, $window), 'window.close', 'ok');
        }

        return response()->noContent();
    }

    public function saveGeometry(Request $request): Response
    {
        $window = (string) $request->input('window');
        $principal = new StateKey('user', (string) $request->user()->id, '');

        // The auth point (D3, mirrors close): you may only persist geometry for YOUR OWN open
        // window. A forged window id, an already-closed one, or another user's window is NOT in
        // this user's open-set, so it's a 403 that writes nothing -- it can't write onto a row it
        // doesn't own, and combined with the UPDATE-only saveGeometry (S4) can't resurrect a closed one.
        if (! $this->openWindows->isOpen($principal, $window)) {
            abort(403);
        }

        // Coerce the fields server-side -- never trust the wire types. The RESTORE rect + stacking
        // z are ints; the resized/maximised/minimised flags are bools. saveGeometry intersects the
        // known geometry keys, so sending the full coerced snapshot is fine.
        $geometry = [
            'x' => (int) $request->input('x'),
            'y' => (int) $request->input('y'),
            'w' => (int) $request->input('w'),
            'h' => (int) $request->input('h'),
            'z' => (int) $request->input('z'),
            'sized' => (bool) $request->input('sized'),
            'maximised' => (bool) $request->input('maximised'),
            'minimised' => (bool) $request->input('minimised'),
        ];

        $this->openWindows->saveGeometry($principal, $window, $geometry);

        return response()->noContent();
    }
}
