<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Event;
use SystemX\Core\Events\DesktopRendered;
use Tests\TestCase;

class PushDesktopCommandTest extends TestCase
{
    public function test_it_broadcasts_a_tree_to_a_desktop_with_no_browser_request(): void
    {
        Event::fake([DesktopRendered::class]);

        $this->artisan('system-x:push', ['desktopId' => 'desk-9', 'count' => 42])
            ->assertSuccessful();

        Event::assertDispatched(DesktopRendered::class, function (DesktopRendered $e): bool {
            return $e->desktopId === 'desk-9'
                && $e->tree['children'][0]['props']['text'] === 'Clicked 42 times';
        });
    }

    public function test_it_defaults_to_the_hello_app_and_carries_its_slug_as_the_window_id(): void
    {
        Event::fake([DesktopRendered::class]);

        $this->artisan('system-x:push', ['desktopId' => 'desk-1', 'count' => 7])
            ->assertSuccessful();

        Event::assertDispatched(DesktopRendered::class, function (DesktopRendered $e): bool {
            return $e->desktopId === 'desk-1'
                && $e->appSlug === 'hello'
                && $e->windowId === 'hello'
                && $e->tree['children'][0]['props']['text'] === 'Clicked 7 times';
        });
    }

    public function test_the_app_option_routes_the_push_through_the_chosen_app(): void
    {
        Event::fake([DesktopRendered::class]);

        $this->artisan('system-x:push', ['desktopId' => 'desk-2', '--app' => 'notes'])
            ->assertSuccessful();

        Event::assertDispatched(DesktopRendered::class, function (DesktopRendered $e): bool {
            return $e->desktopId === 'desk-2'
                && $e->appSlug === 'notes'
                && $e->windowId === 'notes';
        });
    }
}
