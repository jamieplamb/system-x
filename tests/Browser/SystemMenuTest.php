<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class SystemMenuTest extends DuskTestCase
{
    // The system menu end-to-end (system-menu plan, D3/D4): the tray user button opens a
    // dropdown that greets the user by name, lists the SYSTEM apps (Appearance + About), and
    // carries Log out. This is the load-bearing proof that the menu opens in the real boot DOM,
    // the dusk="logout" hook resolves once it's open, and a system-app item launches its window.
    public function test_the_user_menu_dropdown_opens_with_the_name_system_apps_and_logout(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAsDemoUser($browser)
                ->waitFor('[data-window-id="hello"] .sx-window')
                // The tray user button is the menu anchor -- it shows the demo user's initials.
                ->assertPresent('.sx-panel-user')
                ->assertSeeIn('.sx-panel-user', 'DU')
                // Click it -> the dropdown opens.
                ->click('.sx-panel-user')
                ->waitFor('.sx-system-menu')
                // The header greets the user by their boot name.
                ->assertSeeIn('.sx-system-menu .sx-system-menu-header', 'Demo User')
                // A dynamic item per system app (Appearance + About) + the Log out item.
                ->assertPresent('.sx-system-menu [data-sx-menu="appearance"]')
                ->assertPresent('.sx-system-menu [data-sx-menu="about"]')
                ->assertPresent('.sx-system-menu [data-sx-menu="logout"]')
                ->assertSeeIn('.sx-system-menu [data-sx-menu="logout"]', 'Log out')
                // Screenshot the open dropdown (the name header + the items) for eyeballing.
                ->screenshot('system-menu-open');

            // Clicking Appearance opens the Appearance window.
            $browser->click('.sx-system-menu [data-sx-menu="appearance"]')
                ->waitFor('[data-app="appearance"] .sx-window')
                ->assertPresent('[data-app="appearance"] .sx-window');
        });
    }

    // The launcher is decluttered (system-menu plan, D1/D2): system apps (Appearance/About) are
    // filtered OUT of the launcher grid -- it shows ONLY the user apps (Hello + Notes + Controls +
    // the third-party example.todo). Proves the !system filter holds end-to-end in the real boot DOM.
    public function test_the_launcher_shows_only_user_apps(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAsDemoUser($browser)
                ->waitFor('[data-window-id="hello"] .sx-window')
                // Open the launcher.
                ->click('[data-sx-launcher]')
                ->waitFor('.sx-launcher-grid')
                // The USER apps are present (Controls is the form/display widget gallery demo).
                ->assertPresent('[data-sx-launch="hello"]')
                ->assertPresent('[data-sx-launch="notes"]')
                ->assertPresent('[data-sx-launch="controls"]')
                // The third-party example.todo app auto-appears here too -- it's a user app.
                ->assertPresent('[data-sx-launch="example.todo"]')
                // The SYSTEM apps are NOT in the grid -- they live in the user menu instead.
                ->assertMissing('[data-sx-launch="appearance"]')
                ->assertMissing('[data-sx-launch="about"]')
                // Exactly four tiles -- Hello + Notes + Controls + example.todo, nothing else.
                ->assertScript("return document.querySelectorAll('.sx-launcher-grid [data-sx-launch]').length", 4);
        });
    }
}
