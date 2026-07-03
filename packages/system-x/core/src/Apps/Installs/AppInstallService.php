<?php

namespace SystemX\Core\Apps\Installs;

use SystemX\Core\State\StateKey;

// The SUBTRACTIVE per-user uninstalled-app set (App-install plan, D1). A row =
// "this user uninstalled this app"; the launcher shows registered-minus-uninstalled (the
// route's boot filter, Task 2). A fresh user has ZERO rows -- nothing uninstalled, so
// everything shows (no first-boot seeding; new registered apps auto-appear). Service-class
// direction, mirroring OpenWindowService: keyed on the principal's (principalType,
// principalId) 2-tuple; the StateKey's windowId is IRRELEVANT to this app-level set.
//
// System apps (Appearance/About/Manage-apps) are never uninstalled -- the endpoints enforce
// that (Task 3); this service is principal/app agnostic (it'll mark whatever it's given).
class AppInstallService
{
    /**
     * The uninstalled app slugs for the principal.
     *
     * @return array<int, string>
     */
    public function uninstalledFor(StateKey $principal): array
    {
        return UninstalledApp::query()
            ->where('principal_type', $principal->principalType)
            ->where('principal_id', $principal->principalId)
            ->orderBy('id')
            ->pluck('app')
            ->all();
    }

    // Mark the app uninstalled for the principal. firstOrCreate on (principal, app) -- so it's
    // idempotent (twice = one row; the UNIQUE index reads the race through).
    public function uninstall(StateKey $principal, string $app): void
    {
        UninstalledApp::query()->firstOrCreate([
            'principal_type' => $principal->principalType,
            'principal_id' => $principal->principalId,
            'app' => $app,
        ]);
    }

    // Re-install: drop the uninstalled row so the app shows again (the subtractive set shrinks).
    public function install(StateKey $principal, string $app): void
    {
        UninstalledApp::query()
            ->where('principal_type', $principal->principalType)
            ->where('principal_id', $principal->principalId)
            ->where('app', $app)
            ->delete();
    }

    public function isUninstalled(StateKey $principal, string $app): bool
    {
        return UninstalledApp::query()
            ->where('principal_type', $principal->principalType)
            ->where('principal_id', $principal->principalId)
            ->where('app', $app)
            ->exists();
    }
}
