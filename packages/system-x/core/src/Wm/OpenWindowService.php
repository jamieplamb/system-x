<?php

namespace SystemX\Core\Wm;

use Illuminate\Support\Str;
use SystemX\Core\State\StateKey;

// The per-user open-window SET (Plan 5a, D7). Service-class direction. It owns the
// SET (which windows exist for a user); it does NOT touch the state bag -- the close
// ENDPOINT calls StateStore::forget separately (the service owns rows, the store owns
// bytes, D3). The principal is taken as a StateKey for its (principalType, principalId);
// the key's windowId is irrelevant to set-level operations.
class OpenWindowService
{
    // The static pair every user starts with (D4/D8) -- their slug IS their window id.
    private const DEFAULTS = ['hello', 'notes'];

    // The geometry columns that ride on the open-windows row (Plan 5e, D1/D2): the RESTORE
    // rect, the resized/maximised/minimised flags, and the stacking z. saveGeometry only
    // ever writes these keys; forPrincipal reads them back for the boot stamp.
    private const GEOMETRY = ['x', 'y', 'w', 'h', 'sized', 'maximised', 'minimised', 'z'];

    /**
     * @return array<int, array{window: string, app: string, x: ?int, y: ?int, w: ?int, h: ?int, sized: bool, maximised: bool, minimised: bool, z: ?int}>
     */
    public function forPrincipal(StateKey $principal): array
    {
        return OpenWindow::query()
            ->where('principal_type', $principal->principalType)
            ->where('principal_id', $principal->principalId)
            ->orderBy('id')
            ->get(['window_id', 'app', ...self::GEOMETRY])
            ->map(fn (OpenWindow $w): array => [
                'window' => $w->window_id,
                'app' => $w->app,
                // Geometry is NULL/false until the client settles a window (D1) -- the casts
                // make x/y/w/h/z null and the flags false for a never-positioned window.
                'x' => $w->x,
                'y' => $w->y,
                'w' => $w->w,
                'h' => $w->h,
                'sized' => $w->sized,
                'maximised' => $w->maximised,
                'minimised' => $w->minimised,
                'z' => $w->z,
            ])
            ->all();
    }

    /**
     * Persist a window's geometry onto its open-set row (Plan 5e, D1).
     *
     * UPDATE-ONLY (S4 -- the close-race guard): a plain where(principal, window)->update().
     * If the row was already deleted (a close raced this in-flight geometry POST), the
     * update affects ZERO rows and does NOT re-create it -- NEVER firstOrCreate/updateOrCreate/
     * upsert, which would resurrect a closed window. The INSERT path is launch() (NULL geometry).
     *
     * @param  array<string, mixed>  $geometry  Any subset of x/y/w/h/sized/maximised/minimised/z.
     */
    public function saveGeometry(StateKey $principal, string $windowId, array $geometry): void
    {
        $payload = array_intersect_key($geometry, array_flip(self::GEOMETRY));

        if ($payload === []) {
            return; // nothing recognised to write -- don't issue an empty UPDATE
        }

        OpenWindow::query()
            ->where('principal_type', $principal->principalType)
            ->where('principal_id', $principal->principalId)
            ->where('window_id', $windowId)
            ->update($payload);
    }

    public function appFor(StateKey $principal, string $windowId): ?string
    {
        // The resync's app-resolution read point (B4): which app renders into this open
        // window for this user. Returns null when the window isn't open for them -- so a
        // forged ?window=<not-open> resolves no app and the caller 404s naturally.
        return OpenWindow::query()
            ->where('principal_type', $principal->principalType)
            ->where('principal_id', $principal->principalId)
            ->where('window_id', $windowId)
            ->value('app');
    }

    public function seedDefaults(StateKey $principal): void
    {
        if ($this->forPrincipal($principal) !== []) {
            return; // already has windows -- a no-op (idempotent first-boot seed)
        }

        foreach (self::DEFAULTS as $slug) {
            OpenWindow::query()->firstOrCreate([
                'principal_type' => $principal->principalType,
                'principal_id' => $principal->principalId,
                'window_id' => $slug, // slug-as-id for the static pair (zero data migration)
            ], ['app' => $slug]);
        }
    }

    // SINGLETON-PER-APP (S4). 5a's apps (hello/notes) are singletons; the duplicate-spawn
    // launcher UX is 5b. A direct POST loop must NOT mint unbounded ULID rows + bags, so
    // if this user already has a window for $app, RETURN it (the caller re-renders/raises
    // it) instead of creating a second. firstOrCreate on (principal, app) keeps the open
    // set bounded server-side; the client focus-if-open is just the fast path on top.
    // (True multi-instance UX -- intentionally spawning a second `notes` -- is 5b; the
    // ULID mechanism already supports it, only this guard is relaxed there.)
    public function launch(StateKey $principal, string $app): OpenWindow
    {
        return OpenWindow::query()->firstOrCreate([
            'principal_type' => $principal->principalType,
            'principal_id' => $principal->principalId,
            'app' => $app,
        ], [
            'window_id' => (string) Str::ulid(),
        ]);
    }

    public function close(StateKey $principal, string $windowId): void
    {
        OpenWindow::query()
            ->where('principal_type', $principal->principalType)
            ->where('principal_id', $principal->principalId)
            ->where('window_id', $windowId)
            ->delete();
    }

    public function isOpen(StateKey $principal, string $windowId): bool
    {
        return OpenWindow::query()
            ->where('principal_type', $principal->principalType)
            ->where('principal_id', $principal->principalId)
            ->where('window_id', $windowId)
            ->exists();
    }
}
