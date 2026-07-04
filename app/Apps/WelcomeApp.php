<?php

namespace App\Apps;

use SystemX\Core\Runtime\App;
use SystemX\Core\Widgets\Label;
use SystemX\Core\Widgets\Raw;
use SystemX\Core\Widgets\Stack;
use SystemX\Core\Widgets\Window;
use SystemX\Core\Wire\Node;

// The live-demo welcome window (showcase plan). Auto-opened once per ephemeral visitor by
// DemoController right after login; also relaunchable from the launcher while demo mode is on.
// A plain static window: no durable properties, no handlers.
class WelcomeApp extends App
{
    public function slug(): string
    {
        return 'welcome';
    }

    public function title(): string
    {
        return 'Welcome';
    }

    public function icon(): string
    {
        return 'window';
    }

    public function render(): Node
    {
        return Window::make('Welcome')->size(420, 300)->content([
            Label::make('This desktop is yours.')->id('welcome-lede'),
            Label::make("Open the launcher to try the apps, drag and resize the windows, snap them to the edges, and change the look in Appearance. Everything you do sticks: reload the page and it all comes back. Once you've been idle for a while, the whole desktop is quietly binned.")->id('welcome-body'),
            Stack::make()->content([
                // The links open in a new tab so they never navigate the visitor off their desktop.
                Raw::make()->html('<small class="sx-raw-note">Built with system-x. Read the <a href="https://jamieplamb.github.io/system-x-docs/" target="_blank" rel="noopener">docs</a> or browse the <a href="https://github.com/jamieplamb/system-x" target="_blank" rel="noopener">source on GitHub</a>.</small>')->id('welcome-links'),
            ]),
        ]);
    }
}
