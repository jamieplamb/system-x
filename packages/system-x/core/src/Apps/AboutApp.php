<?php

namespace SystemX\Core\Apps;

use SystemX\Core\Runtime\App;
use SystemX\Core\Widgets\Label;
use SystemX\Core\Widgets\Stack;
use SystemX\Core\Widgets\Window;
use SystemX\Core\Wire\Node;

// The About app (Plan 5b-2, D7). A trivial static dogfood -- the system-x wordmark + a
// version line. NO state, NO handlers; it completes the launcher grid + gives the context
// menu's "About" a real target. The simplest possible real App.
class AboutApp extends App
{
    public function slug(): string
    {
        return 'about';
    }

    public function title(): string
    {
        return 'About system-x';
    }

    public function icon(): string
    {
        return 'info';
    }

    // System furniture (plan system-menu, D1): About is the framework's own app, so it lives
    // in the user-icon menu, not the launcher grid.
    public function system(): bool
    {
        return true;
    }

    public function render(): Node
    {
        return Window::make('About system-x')->size(360, 160)->content([
            Stack::make()->content([
                Label::make('system-x')->id('about-wordmark'),
                Label::make('An X11 desktop, served from PHP.')->id('about-tagline'),
                Label::make('v0.1.0')->id('about-version'),
            ]),
        ]);
    }
}
