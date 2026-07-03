<?php

namespace Tests\Feature\Preferences;

use Illuminate\Foundation\Testing\RefreshDatabase;
use SystemX\Core\Preferences\Preference;
use SystemX\Core\Preferences\PreferencesService;
use SystemX\Core\State\StateKey;
use Tests\TestCase;

class PreferencesServiceTest extends TestCase
{
    use RefreshDatabase;

    private function principal(string $id = '1'): StateKey
    {
        // Per-USER, 2-tuple grain (D1) -- the windowId is irrelevant to a pref, so it's ''.
        return new StateKey('user', $id, '');
    }

    public function test_for_principal_returns_the_defaults_for_a_user_with_no_row(): void
    {
        $prefs = app(PreferencesService::class)->forPrincipal($this->principal());

        // A brand-new user gets exactly today's shipped look (D1 defaults) -- no row needed.
        $this->assertSame('modern', $prefs['theme']);
        $this->assertSame('blue', $prefs['accent']);
        $this->assertSame('gradient', $prefs['wallpaper']);
        $this->assertSame('top', $prefs['panel_position']);
    }

    public function test_set_persists_one_key_and_leaves_the_others_at_their_default(): void
    {
        $service = app(PreferencesService::class);
        $service->set($this->principal(), 'theme', 'pewter');

        $prefs = $service->forPrincipal($this->principal());
        $this->assertSame('pewter', $prefs['theme']);    // the set key
        $this->assertSame('blue', $prefs['accent']);     // an unset key still defaults
    }

    public function test_set_is_read_modify_write_over_the_json_bag(): void
    {
        $service = app(PreferencesService::class);
        $service->set($this->principal(), 'theme', 'pewter');
        $service->set($this->principal(), 'accent', 'amber');

        $prefs = $service->forPrincipal($this->principal());
        $this->assertSame('pewter', $prefs['theme']);    // the first set survives the second
        $this->assertSame('amber', $prefs['accent']);
    }

    public function test_one_users_prefs_never_bleed_into_another(): void
    {
        $service = app(PreferencesService::class);
        $service->set($this->principal('1'), 'theme', 'pewter');

        $this->assertSame('modern', $service->forPrincipal($this->principal('2'))['theme']);
    }

    public function test_has_seeded_desktop_is_false_for_a_fresh_principal(): void
    {
        // The desktop-BOOTSTRAP marker (distinct from the cosmetic look): a brand-new user
        // with no row has never been seeded, so the route's once-gate will seed them.
        $this->assertFalse(app(PreferencesService::class)->hasSeededDesktop($this->principal()));
    }

    public function test_mark_desktop_seeded_flips_has_seeded_to_true(): void
    {
        $service = app(PreferencesService::class);
        $service->markDesktopSeeded($this->principal());

        $this->assertTrue($service->hasSeededDesktop($this->principal()));
    }

    public function test_mark_desktop_seeded_is_idempotent(): void
    {
        $service = app(PreferencesService::class);
        $service->markDesktopSeeded($this->principal());
        // Calling twice must not error + must keep the user marked seeded.
        $service->markDesktopSeeded($this->principal());

        $this->assertTrue($service->hasSeededDesktop($this->principal()));
    }

    public function test_the_seeded_marker_rides_the_same_row_as_the_cosmetic_prefs(): void
    {
        // The bootstrap marker is a real column on the per-user prefs row -- setting a pref
        // then marking (and vice versa) both persist on the ONE (principal_type, principal_id)
        // row, neither clobbering the other.
        $service = app(PreferencesService::class);
        $service->set($this->principal(), 'theme', 'pewter');
        $service->markDesktopSeeded($this->principal());

        $this->assertTrue($service->hasSeededDesktop($this->principal()));
        $this->assertSame('pewter', $service->forPrincipal($this->principal())['theme']);
        $this->assertSame(1, Preference::query()->count());
    }
}
