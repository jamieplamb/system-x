<?php

namespace Tests\Feature;

use SystemX\Core\Widgets\Window;
use Tests\TestCase;

class WindowDefaultSizeTest extends TestCase
{
    public function test_a_window_defaults_to_400x300_when_no_size_is_declared(): void
    {
        $w = Window::make('Untitled');

        $this->assertSame(400, $w->props['width']);
        $this->assertSame(300, $w->props['height']);
    }

    public function test_size_overrides_the_default(): void
    {
        $w = Window::make('Sized')->size(640, 480);

        $this->assertSame(640, $w->props['width']);
        $this->assertSame(480, $w->props['height']);
    }
}
