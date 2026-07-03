<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class WallpaperTest extends DuskTestCase
{
    // Visual + computed proof for the four wallpaper styles (Plan 5b-2, Task 4). The apply
    // path (Task 3) sets #sx-desktop[data-sx-wallpaper]; this proves the CSS that RESPONDS:
    // grid/lines layer a pattern OVER the theme's --sx-desktop-bg, solid clears the image,
    // and the default (gradient / no attribute) is unchanged. The computed background-image
    // is the observable contract -- we read it off the live element after flipping the attr.

    private function backgroundImage(Browser $browser): string
    {
        return $browser->script(
            "return getComputedStyle(document.querySelector('#sx-desktop')).backgroundImage;"
        )[0];
    }

    private function backgroundSize(Browser $browser): string
    {
        return $browser->script(
            "return getComputedStyle(document.querySelector('#sx-desktop')).backgroundSize;"
        )[0];
    }

    private function setWallpaper(Browser $browser, string $wp): void
    {
        $browser->script("document.querySelector('#sx-desktop').setAttribute('data-sx-wallpaper', '{$wp}');");
    }

    public function test_the_four_wallpapers_drive_the_desktop_background(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAsDemoUser($browser)
                ->waitFor('#sx-desktop')
                ->waitFor('[data-window-id="hello"] .sx-window');

            // Default (gradient): the bare .sx-desktop rule -- the theme gradient + stipple
            // weave. The image is present (NOT none) and carries a gradient layer.
            $default = $this->backgroundImage($browser);
            $this->assertStringNotContainsString('none', $default, 'The default desktop must keep its gradient image.');
            $this->assertStringContainsString('gradient', $default, 'The default desktop must paint the theme gradient.');
            $browser->screenshot('wallpaper-gradient');

            // grid: a radial-dot pattern LAYERED OVER the theme gradient. Both a
            // radial-gradient (the dots) and a layer carrying the theme gradient appear.
            $this->setWallpaper($browser, 'grid');
            $grid = $this->backgroundImage($browser);
            $this->assertStringContainsString('radial-gradient', $grid, 'grid must add a radial-dot layer.');
            $this->assertStringNotContainsString('none', $grid, 'grid must still ride the theme gradient.');
            // The 18px dot tile is what makes it a grid (and what the default never has) --
            // assert the size so this can't pass on the theme's own radial gradient alone.
            $this->assertStringContainsString('18px 18px', $this->backgroundSize($browser), 'grid must tile the dots at 18px.');
            $browser->screenshot('wallpaper-grid');

            // lines: a 45deg repeating-linear pattern OVER the theme gradient.
            $this->setWallpaper($browser, 'lines');
            $lines = $this->backgroundImage($browser);
            $this->assertStringContainsString('repeating-linear-gradient', $lines, 'lines must add a repeating-linear layer.');
            $this->assertStringContainsString('45deg', $lines, 'the lines run at 45deg.');
            $browser->screenshot('wallpaper-lines');

            // solid: the image is cleared entirely -- a flat --sx-surface-desk, no gradient.
            $this->setWallpaper($browser, 'solid');
            $solid = $this->backgroundImage($browser);
            $this->assertSame('none', $solid, 'solid must clear the desktop image (flat surface only).');
            $browser->screenshot('wallpaper-solid');

            // Round-trip back to gradient: the override falls away, the default look returns.
            $this->setWallpaper($browser, 'gradient');
            $roundTrip = $this->backgroundImage($browser);
            $this->assertStringNotContainsString('none', $roundTrip, 'gradient round-trips back to the theme image.');
            $this->assertStringContainsString('gradient', $roundTrip);
        });
    }
}
