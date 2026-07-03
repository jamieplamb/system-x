<?php

namespace SystemX\Core\State;

use Illuminate\Http\Request;

// The principal seam -- now keyed on the REAL authenticated user (Plan 4c). The 4a/4b
// versions keyed on the anonymous sx_desktop_id session uuid as a placeholder; 4c
// fulfils the re-key those plans were built for: principalType='user', principalId =
// the (string-cast) int user id, windowId = the wire `window`. Returns null for a
// guest, so the controller bails exactly like the old empty-id guard (and the `auth`
// route gate rejects guests before they ever reach here in normal flow). The store,
// schema, StateKey/StateBag, HelloApp/NotesApp, and the AppKernel are ALL untouched --
// principal_id is a STRING column, so the int user id fits with NO migration. The
// window id is WIRE-ONLY now: input('window') reads the QUERY STRING on a GET (the
// ?window= resync) and the BODY on a POST (the event); the 4b session fallback is
// dropped because every caller now sends a wire window (4b D8's static two-window boot).
class StatePrincipalResolver
{
    public function resolve(Request $request): ?StateKey
    {
        $user = $request->user();
        $windowId = $request->input('window');

        if ($user === null || ! $windowId) {
            return null;
        }

        return new StateKey('user', (string) $user->id, (string) $windowId);
    }
}
