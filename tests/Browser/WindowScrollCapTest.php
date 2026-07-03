<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

// Slice V1-A: a declared-size window is height-CAPPED to its declared/->size() height and its
// overflowing content scrolls INSIDE .sx-content -- the window never grows to fit tall content.
// The renderer stamps style.height on the .sx-window as a cap; .sx-content is flex:1 1 auto;
// min-height:0; overflow:auto, so overflow scrolls in the pane while the window stays capped.
// jsdom computes no layout, so ONLY a real browser proves the cap + the scroll actually work.
class WindowScrollCapTest extends DuskTestCase
{
    public function test_a_declared_size_window_caps_its_height_and_scrolls_overflowing_content(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAsDemoUser($browser)->waitFor('[data-window-id="hello"] .sx-window');

            // hello is declared 360x280. Inject content much taller than 280 into its .sx-content,
            // then assert the window did NOT grow (cap) and the content scrolls.
            $result = $browser->script(<<<'JS'
                const win = document.querySelector('[data-window-id="hello"] .sx-window');
                const content = win.querySelector('.sx-content');
                const filler = document.createElement('div');
                filler.style.height = '2000px';
                filler.setAttribute('data-test-filler', '');
                content.appendChild(filler);
                return {
                    winHeight: win.getBoundingClientRect().height,
                    contentScrollable: content.scrollHeight > content.clientHeight,
                    overflowY: getComputedStyle(content).overflowY,
                };
            JS)[0];

            $this->assertLessThan(400, $result['winHeight']); // capped near 280, NOT grown to ~2000
            $this->assertTrue($result['contentScrollable']);
            $this->assertContains($result['overflowY'], ['auto', 'scroll']);

            $moved = $browser->script(<<<'JS'
                const content = document.querySelector('[data-window-id="hello"] .sx-content');
                content.scrollTop = 500;
                return content.scrollTop;
            JS)[0];
            $this->assertGreaterThan(0, $moved); // it actually scrolls
        });
    }

    public function test_a_dialog_in_an_overflowing_window_locks_the_content_scroll(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAsDemoUser($browser)->waitFor('[data-window-id="hello"] .sx-window');

            $overflow = $browser->script(<<<'JS'
                const content = document.querySelector('[data-window-id="hello"] .sx-content');
                const bd = document.createElement('div');
                bd.className = 'sx-dialog-backdrop';
                content.appendChild(bd);
                return getComputedStyle(content).overflow;
            JS)[0];

            $this->assertSame('hidden', $overflow); // the :has scroll-lock fired
        });
    }
}
