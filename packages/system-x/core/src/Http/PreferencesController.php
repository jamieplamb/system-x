<?php

namespace SystemX\Core\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use SystemX\Core\Preferences\PreferencesService;
use SystemX\Core\State\StateKey;

// The preferences endpoint (Plan 5b-2, D2). A pref is applied CLIENT-side instantly; this
// is the fire-and-forget PERSIST (like closeWindow). It validates the {key, value} against
// the allowed sets (so a forged value can't poison the bag), writes via the service, 204.
// NO broadcast -- a pref is desktop-wide, it can't ride the per-window DesktopRendered frame.
class PreferencesController
{
    public function __construct(private PreferencesService $prefs) {}

    public function store(Request $request): Response
    {
        // Normalise the wire key to its canonical store key first (D6): the client keys the
        // panel by the terse 'panel', the durable bag + the boot stamp by 'panel_position'.
        $key = PreferencesService::canonicalKey((string) $request->input('key'));
        $value = (string) $request->input('value');

        // The allow-list IS the validation (D2): an unknown key or a value outside its set
        // is a 422 that touches no row. The value gets stamped onto <html> at the next boot,
        // so an unvalidated value is an injection vector -- whitelist BOTH key and value.
        $allowed = PreferencesService::ALLOWED[$key] ?? null;
        if ($allowed === null || ! in_array($value, $allowed, true)) {
            abort(422, 'Unknown preference.');
        }

        // Per-user (D2): the principal is ALWAYS $request->user() -- a user writes only THEIR
        // prefs. Never the wire. The route is auth-gated, so user() is present here.
        $principal = new StateKey('user', (string) $request->user()->id, '');
        $this->prefs->set($principal, $key, $value);

        return response()->noContent();
    }
}
