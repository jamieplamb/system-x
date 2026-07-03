<?php

namespace SystemX\Core\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use SystemX\Core\Apps\Installs\AppInstallService;
use SystemX\Core\Launcher\LauncherLayoutService;
use SystemX\Core\Runtime\AppRegistry;
use SystemX\Core\State\StateKey;

// The whole-document launcher-layout persist (Plan 4a, Piece 5). The client owns the arrangement
// and posts the ENTIRE layout; the server VALIDATES-AND-REJECTS (422), never silently reshapes, so
// client and server can't drift. The drop/dissolve reconciliation lives at render time + on the
// live install/uninstall path -- this endpoint only ever sees an already-reconciled doc.
class LauncherController
{
    private const MAX_ITEMS = 200;

    private const MAX_NAME = 40;

    public function __construct(
        private AppRegistry $apps,
        private AppInstallService $installs,
        private LauncherLayoutService $layout,
    ) {}

    public function saveLayout(Request $request): Response
    {
        $userId = (string) $request->user()->id;
        $principal = new StateKey('user', $userId, '');

        $layout = $request->input('layout');
        if (! is_array($layout)) {
            abort(422, 'layout must be an array.');
        }

        // The valid launcher slugs for THIS user (user apps only, minus uninstalled) -- the same set
        // Desktop::render() reconciles the stored layout against. A slug outside it is a forgery.
        $valid = $this->launcherSlugs($principal);

        $this->validateLayout($layout, $valid);

        $this->layout->save($principal, $layout);

        return response()->noContent();
    }

    /** @return array<int, string> */
    private function launcherSlugs(StateKey $principal): array
    {
        $uninstalled = $this->installs->uninstalledFor($principal);

        return collect($this->apps->metadata())
            ->filter(fn (array $a): bool => ! $a['system'] && ! in_array($a['slug'], $uninstalled, true))
            ->pluck('slug')
            ->all();
    }

    /**
     * @param  array<int, mixed>  $layout
     * @param  array<int, string>  $valid
     */
    private function validateLayout(array $layout, array $valid): void
    {
        if (count($layout) > self::MAX_ITEMS) {
            abort(422, 'layout too large.');
        }

        $validSet = array_flip($valid);
        $seenSlugs = [];
        $seenFolderIds = [];

        foreach ($layout as $item) {
            $type = is_array($item) ? ($item['type'] ?? null) : null;

            if ($type === 'app') {
                $this->assertSlug($item['slug'] ?? null, $validSet, $seenSlugs);

                continue;
            }

            if ($type === 'folder') {
                $id = $item['id'] ?? null;
                if (! is_string($id) || $id === '' || isset($seenFolderIds[$id])) {
                    abort(422, 'invalid or duplicate folder id.');
                }
                $seenFolderIds[$id] = true;

                $name = $item['name'] ?? '';
                if (! is_string($name) || mb_strlen($name) > self::MAX_NAME) {
                    abort(422, 'invalid folder name.');
                }

                // Explicit-container model: an empty folder is allowed (folders don't auto-dissolve,
                // they're removed only by an explicit Delete) -- it just has to be an array.
                $apps = $item['apps'] ?? null;
                if (! is_array($apps)) {
                    abort(422, 'a folder must have an apps array.');
                }
                foreach ($apps as $slug) {
                    $this->assertSlug($slug, $validSet, $seenSlugs);
                }

                continue;
            }

            abort(422, 'unknown layout item.');
        }
    }

    /**
     * @param  array<string, int>  $validSet
     * @param  array<string, bool>  $seenSlugs  (by-ref)
     */
    private function assertSlug(mixed $slug, array $validSet, array &$seenSlugs): void
    {
        if (! is_string($slug) || ! isset($validSet[$slug])) {
            abort(422, 'unknown app slug.');
        }
        if (isset($seenSlugs[$slug])) {
            abort(422, 'an app may appear at most once.');
        }
        $seenSlugs[$slug] = true;
    }
}
