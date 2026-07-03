<?php

namespace SystemX\Core\Preferences;

use Illuminate\Support\Carbon;
use SystemX\Core\State\StateKey;

// The per-user PREFERENCES service (Plan 5b-2, D1). Service-class direction, mirroring
// OpenWindowService -- but at the USER grain (the 2-tuple), NOT per-window. It owns the
// durable look: theme/accent/wallpaper/panel_position. The prefs are a JSON bag so a new
// key needs no migration; the endpoint validates against ALLOWED, so the bag stays clean.
class PreferencesService
{
    // The shipped default look (D1) -- a brand-new user with no row gets exactly today's
    // desktop (modern/blue/gradient/top), so the no-flash stamp matches the CSS :root.
    public const DEFAULTS = [
        'theme' => 'modern',
        'accent' => 'blue',
        'wallpaper' => 'gradient',
        'panel_position' => 'top',
    ];

    // The allow-list the endpoint validates a {key, value} against (D2). Keep it in lockstep
    // with the token sets (themes.css / accents.css) + the wallpaper styles + the panel edges.
    public const ALLOWED = [
        'theme' => ['modern', 'dark', 'pewter', 'nextstep', 'onyx'],
        'accent' => ['blue', 'teal', 'violet', 'green', 'amber', 'graphite'],
        'wallpaper' => ['gradient', 'grid', 'lines', 'solid'],
        'panel_position' => ['top', 'bottom'],
    ];

    // Wire-key -> store-key aliases (D2/D6). The client's pref hook keys the panel by the short
    // 'panel' (the data-sx-panel attribute, the panel:top/bottom buttons), but the durable bag +
    // the no-flash boot stamp read 'panel_position'. Normalise the inbound key here so the wire
    // stays terse while the store key matches the boot blade -- without this the panel pref POSTs
    // under 'panel', misses the allow-list, and never persists (the reload reverts to top).
    public const ALIASES = [
        'panel' => 'panel_position',
    ];

    // Resolve a wire key to its canonical store key (passes through unaliased keys unchanged).
    public static function canonicalKey(string $key): string
    {
        return self::ALIASES[$key] ?? $key;
    }

    /** @return array<string, string> */
    public function forPrincipal(StateKey $principal): array
    {
        $row = Preference::query()
            ->where('principal_type', $principal->principalType)
            ->where('principal_id', $principal->principalId)
            ->first();

        // Merge the stored bag OVER the defaults: an absent row OR an absent key returns
        // the default, so the caller never null-checks + the stamp never half-paints.
        return array_merge(self::DEFAULTS, $row?->prefs ?? []);
    }

    public function set(StateKey $principal, string $key, string $value): void
    {
        // Read-modify-write the JSON bag for this principal's row (D1). firstOrNew on
        // the 2-tuple so the first set creates the row, later sets patch it -- one key at a
        // time, the others untouched.
        $row = Preference::query()->firstOrNew([
            'principal_type' => $principal->principalType,
            'principal_id' => $principal->principalId,
        ]);
        $bag = $row->prefs ?? [];
        $bag[$key] = $value;
        $row->prefs = $bag;
        $row->save();
    }

    // ---- Desktop BOOTSTRAP state (NOT a cosmetic pref) ----------------------------------
    // These two ride the SAME per-user prefs row (keyed by the 2-tuple, like forPrincipal/set)
    // but they are NOT part of the look: desktop_seeded_at is a real nullable timestamp column
    // tracking whether this user has EVER had the default windows seeded. The route gates
    // seedDefaults behind it so the welcome pair is seeded ONCE EVER -- after that, closing
    // every window leaves the desktop empty across refreshes (you can't have an empty desktop
    // was the bug; now you can).

    // True iff the user's row exists AND has been stamped seeded. A fresh user (no row) or an
    // existing pre-fix row (NULL marker) is false -> the route seeds + marks them once.
    public function hasSeededDesktop(StateKey $principal): bool
    {
        $row = Preference::query()
            ->where('principal_type', $principal->principalType)
            ->where('principal_id', $principal->principalId)
            ->first();

        return $row !== null && $row->desktop_seeded_at !== null;
    }

    // Stamp the user seeded (firstOrNew on the 2-tuple, like set()). Idempotent: if the marker
    // is already set, leave the original timestamp untouched.
    public function markDesktopSeeded(StateKey $principal): void
    {
        $row = Preference::query()->firstOrNew([
            'principal_type' => $principal->principalType,
            'principal_id' => $principal->principalId,
        ]);

        if ($row->desktop_seeded_at !== null) {
            return; // already marked -- keep the first timestamp
        }

        // A brand-new row has no bag yet; prefs is non-nullable JSON, so default it to {} (the
        // look stays at DEFAULTS via forPrincipal's merge) before stamping the marker.
        $row->prefs = $row->prefs ?? [];
        $row->desktop_seeded_at = Carbon::now();
        $row->save();
    }
}
