<?php

namespace SystemX\Core\Launcher;

use SystemX\Core\State\StateKey;

// The per-user LAUNCHER LAYOUT service (Plan 4a), mirroring PreferencesService at the USER grain
// (the 2-tuple). It owns the ordered arrangement document only -- WHICH apps exist stays the
// launcher app-set (Desktop::render reconciles the two). Whole-document read + write; the endpoint
// validates before calling save().
class LauncherLayoutService
{
    /** @return array<int, array<string, mixed>> the ordered layout items ([] for a fresh user) */
    public function layoutFor(StateKey $principal): array
    {
        $row = LauncherLayout::query()
            ->where('principal_type', $principal->principalType)
            ->where('principal_id', $principal->principalId)
            ->first();

        return $row?->layout ?? [];
    }

    /** @param array<int, array<string, mixed>> $layout the WHOLE validated document */
    public function save(StateKey $principal, array $layout): void
    {
        $row = LauncherLayout::query()->firstOrNew([
            'principal_type' => $principal->principalType,
            'principal_id' => $principal->principalId,
        ]);
        $row->layout = $layout;
        $row->save();
    }

    /**
     * Reconcile a stored layout against the live launcher app-set: drop unknown slugs (root + in
     * folders), keeping a folder even if the drop empties it (explicit-container model -- folders
     * never auto-dissolve), and append any live app not placed anywhere as a root app item (order
     * preserved). Pure -- Desktop::render() ships the result; the client mirrors the drop/append
     * rules on live install/uninstall.
     *
     * @param  array<int, array<string, mixed>>  $layout
     * @param  array<int, string>  $liveSlugs
     * @return array<int, array<string, mixed>>
     */
    public static function reconcile(array $layout, array $liveSlugs): array
    {
        $live = array_flip($liveSlugs);
        $placed = [];
        $out = [];

        foreach ($layout as $item) {
            if (($item['type'] ?? null) === 'app') {
                $slug = $item['slug'] ?? null;
                if ($slug !== null && isset($live[$slug]) && ! isset($placed[$slug])) {
                    $out[] = ['type' => 'app', 'slug' => $slug];
                    $placed[$slug] = true;
                }

                continue;
            }

            if (($item['type'] ?? null) === 'folder') {
                $apps = [];
                foreach ($item['apps'] ?? [] as $slug) {
                    if (isset($live[$slug]) && ! isset($placed[$slug])) {
                        $apps[] = $slug;
                        $placed[$slug] = true;
                    }
                }
                // Explicit-container model (4a follow-on): keep the folder even when empty -- folders
                // do NOT auto-dissolve; they are removed only by an explicit Delete.
                $out[] = [
                    'type' => 'folder',
                    'id' => $item['id'] ?? '',
                    'name' => $item['name'] ?? '',
                    'apps' => $apps,
                ];
            }
        }

        // Append any live app not placed anywhere, in live order, at root.
        foreach ($liveSlugs as $slug) {
            if (! isset($placed[$slug])) {
                $out[] = ['type' => 'app', 'slug' => $slug];
                $placed[$slug] = true;
            }
        }

        return $out;
    }
}
