<?php

namespace Tests\Feature\Apps;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SystemX\Core\Preferences\PreferencesService;
use SystemX\Core\State\StateKey;
use Tests\TestCase;

class ShellPrefsBootTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_shell_stamps_the_users_prefs_server_side(): void
    {
        $user = User::factory()->create();
        $service = app(PreferencesService::class);
        $principal = new StateKey('user', (string) $user->id, '');
        $service->set($principal, 'theme', 'pewter');
        $service->set($principal, 'accent', 'amber');
        $service->set($principal, 'wallpaper', 'grid');
        $service->set($principal, 'panel_position', 'bottom');

        $html = $this->actingAs($user)->get('/')->assertOk()->getContent();

        // No-flash boot (D4): the prefs are STAMPED into the rendered HTML, so the desktop
        // paints in the user's theme on the first byte. Assert against the structured stamp
        // (the attribute on the right element), not a bare substring (S1).
        $this->assertStringContainsString('data-sx-theme="pewter"', $html);
        $this->assertStringContainsString('data-sx-accent="amber"', $html);
        $this->assertStringContainsString('data-sx-panel="bottom"', $html);
        $this->assertStringContainsString('data-sx-wallpaper="grid"', $html);
        // The boot blob carries the panel position so the WM ctor insets correctly (D4/D6).
        $this->assertStringContainsString('"panel":"bottom"', $html);
    }

    public function test_a_default_user_stamps_the_modern_look(): void
    {
        $user = User::factory()->create();
        $html = $this->actingAs($user)->get('/')->assertOk()->getContent();

        // A brand-new user (no prefs row) stamps the defaults -- modern/blue/gradient/top.
        $this->assertStringContainsString('data-sx-theme="modern"', $html);
        $this->assertStringContainsString('data-sx-accent="blue"', $html);
        $this->assertStringContainsString('data-sx-panel="top"', $html);
        $this->assertStringContainsString('data-sx-wallpaper="gradient"', $html);
        $this->assertStringContainsString('"panel":"top"', $html);
    }
}
