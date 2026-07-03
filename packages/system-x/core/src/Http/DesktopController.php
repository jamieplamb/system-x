<?php

namespace SystemX\Core\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use SystemX\Core\Audit\AuditContext;
use SystemX\Core\Audit\AuditRecorder;
use SystemX\Core\Events\DesktopRendered;
use SystemX\Core\Runtime\AppKernel;
use SystemX\Core\Runtime\HandleResult;
use SystemX\Core\Runtime\WidgetEvent;
use SystemX\Core\State\DatabaseStateStore;
use SystemX\Core\State\StateKey;
use SystemX\Core\State\StatePrincipalResolver;
use SystemX\Core\State\WindowState;
use SystemX\Core\Wm\OpenWindowService;

class DesktopController
{
    // The kernel runs the WHOLE lifecycle (load -> hydrate -> dispatch -> save ->
    // re-render); the controller is now just the transaction/lock + post-commit broadcast
    // shell around it (D4). The hand-rolled $count++ is gone for good.
    public function __construct(
        private AppKernel $kernel,
        private StatePrincipalResolver $principals,
        private OpenWindowService $openWindows,
        private AuditRecorder $audit,
    ) {}

    public function desktop(Request $request): JsonResponse
    {
        // The {app, window} identity split (D4): `window` is the bag key (a ULID or a
        // static slug); `app` is the App to RUN. They are no longer conflated.
        $window = (string) $request->input('window'); // the bag key; '' if absent
        $key = $this->principals->resolve($request);  // keys on $window; null if no window

        // KEYLESS defensive default PRESERVED (D5): a GET with NO window resolves a null
        // key and falls to renderFromBag('hello', []) -- the existing 200 empty-hello
        // default. Do NOT 404 this path; the B4 resolution only fires when a window IS
        // present. Harmless: no store read, no cross-user leak.
        if ($key === null) {
            return response()->json($this->kernel->renderFromBag('hello', []));
        }

        // The App to run is the one the open-set RECORDED for this window -- NEVER the wire
        // `app` (B1). The read-only twin of the event() forgery: ?window=<notes-ulid>&app=hello
        // must render the NOTES tree (recorded app), not hello's, or the resync would paint
        // the wrong app's tree into the notes surface. The open-set IS the source of truth
        // for "which app renders into this window" (B4) -- a launched ULID has no slug to
        // fall back to anyway.
        $principal = new StateKey('user', (string) $request->user()->id, '');
        $app = $this->openWindows->appFor($principal, $window);

        // A PRESENT-but-non-open / unknown window resolves to null -> 404 (B4: a window not
        // open for this user; the guard-hole close + N4: a forged window can't be probed).
        if ($app === null) {
            abort(404);
        }

        return response()->json($this->kernel->renderInitial($key, $app));
    }

    // Return type is the Symfony base Response -- the common ancestor of BOTH the 204 path
    // (Illuminate\Http\Response from noContent()) AND the isolation error path (a JsonResponse
    // from response()->json()). Illuminate's Response and JsonResponse are SIBLINGS under the
    // Symfony base, not parent/child, so narrowing this to Illuminate\Http\Response would reject
    // the error JsonResponse at runtime.
    public function event(Request $request): Response
    {
        $key = $this->principals->resolve($request);

        // Guard: a client that POSTs before booting has no session/key. Ack and bail
        // without broadcasting (preserves the Plan 2 no-session-no-broadcast contract).
        if ($key === null) {
            return response()->noContent();
        }

        // The {app, window} identity split (D4): the bag stays keyed on $key->windowId
        // (the wire `window`), but the App to RUN is the one the open-set RECORDED for this
        // window -- NEVER the wire `app` (B1). A forged POST window=<notes-ulid>&app=hello
        // would otherwise run HelloApp against the Notes bag and clobber {message, notify}
        // with {count:0}. appFor is BOTH the membership check AND the app authority: it
        // returns the recorded app, or null when the window isn't in THIS user's open-set.
        $principal = new StateKey('user', (string) $request->user()->id, '');
        $slug = $this->openWindows->appFor($principal, $key->windowId);

        // null == not open for this user (or a forged/closed window id) == drop. This is the
        // auth point ("may this user touch this window") AND the guard-hole close. Bail with
        // a 204 BEFORE the txn/lock -- no lock, no write, no broadcast for a request we're
        // dropping (mirrors the no-key bail above). The wire `app` is ignored entirely.
        if ($slug === null) {
            return response()->noContent();
        }

        $event = new WidgetEvent(
            (string) $request->input('widget'),
            (string) $request->input('event'),
            $request->input('value'),
            (array) $request->input('payload', []),
        );

        $ctx = AuditContext::forRequest($request, $slug, $key->windowId);

        // Locked read-modify-write in ONE transaction (D4). lockForUpdate serialises the
        // only realistic race (impatient double-click) so the authoritative count can't
        // lose an increment -- the row is pre-created below so the lock always has a real
        // row to take. The kernel's load/hydrate/dispatch/dehydrate/save runs INSIDE this
        // locked closure, exactly where the old hand-rolled count++ was. The returned tree
        // is the post-commit broadcast payload.
        try {
            $result = DB::transaction(function () use ($key, $slug, $event, $ctx): HandleResult {
                // Guarantee the row EXISTS before we lock it. lockForUpdate()->first() on a
                // MISSING row locks NOTHING, so two concurrent FIRST events on a brand-new key
                // would both fall through to default-0 and lose an increment (or the losing
                // INSERT would 500 on the unique constraint). firstOrCreate keyed on ONLY the
                // composite-unique columns matches the unique index; the create-attrs mirror
                // save()'s row exactly (empty default bag at the CURRENT schema version) so the
                // pre-created row is shape-identical and the next load() does not trip the
                // schema-version discard.
                WindowState::query()->firstOrCreate(
                    [
                        'principal_type' => $key->principalType,
                        'principal_id' => $key->principalId,
                        'window_id' => $key->windowId,
                    ],
                    [
                        'bag' => [],
                        'schema_version' => DatabaseStateStore::SCHEMA_VERSION,
                    ],
                );

                // Take the row lock; the VALUE is intentionally discarded -- the kernel's
                // load() re-reads it. The redundant read is the accepted D4 cost (no
                // loadForUpdate on the StateStore contract). Do NOT delete this query: it IS
                // the lock.
                WindowState::query()
                    ->where('principal_type', $key->principalType)
                    ->where('principal_id', $key->principalId)
                    ->where('window_id', $key->windowId)
                    ->lockForUpdate()
                    ->first();

                // The whole app lifecycle, inside the lock. The bag is keyed on
                // $key->windowId; the APP to run is the RECORDED app (appFor), never the wire
                // app -- so a forged `app` can't run the wrong App against this window's bag.
                $handled = $this->kernel->handle($key, $slug, $event);

                // SUCCESS: change + activity written INSIDE the txn, atomic with the state save.
                $this->audit->record($ctx, $event->event, 'ok', $handled->delta, $event->value, $event->payload, $event->widgetId);

                return $handled;
            });
        } catch (\Throwable $e) {
            // FAILURE: the txn already rolled back the state mutation + change rows. Record a
            // standalone error-activity row (survives the rollback) with the full detail server-side.
            $this->audit->record($ctx, $event->event, 'error', null, $event->value, $event->payload, $event->widgetId);

            // Per-app isolation: do NOT re-throw (that 500s the WHOLE desktop for one app's handler bug).
            // No broadcast (the client keeps its last-good tree). A GENERIC message for a client toast --
            // the real exception detail stays in the audit row above and NEVER leaks to the client.
            return response()->json([
                'error' => ['app' => $slug, 'message' => 'This app hit a problem.'],
            ]);
        }

        // AFTER commit: a BARE broadcast() at top level so PendingBroadcast fires on
        // __destruct and Event::fake() catches it. Broadcasting after the transaction
        // closes the read-your-write hole -- the WS frame and any resync read can only see
        // committed state. Do NOT assign it to a variable; do NOT move it inside the
        // closure. desktopId first, RECORDED app slug + window id, tree last (D5) -- the
        // frame carries the recorded app (B1), so it can't claim the wrong app for a window.
        broadcast(new DesktopRendered($key->principalId, $slug, $key->windowId, $result->tree));

        return response()->noContent();
    }
}
