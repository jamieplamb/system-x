<?php

namespace SystemX\Core\Tests\Events;

use Illuminate\Broadcasting\PrivateChannel;
use PHPUnit\Framework\TestCase;
use SystemX\Core\Events\DesktopRendered;

class DesktopRenderedTest extends TestCase
{
    public function test_it_broadcasts_on_the_per_desktop_private_channel(): void
    {
        // Channel + auth are UNCHANGED (D5) -- the window is an address in the payload,
        // not part of the channel name.
        $event = new DesktopRendered('abc-123', 'hello', 'hello', ['type' => 'window']);

        $channel = $event->broadcastOn();

        $this->assertInstanceOf(PrivateChannel::class, $channel);
        $this->assertSame('private-user.abc-123', $channel->name);
    }

    public function test_it_addresses_the_window_in_the_payload(): void
    {
        $event = new DesktopRendered('abc-123', 'notes', 'notes', ['type' => 'window']);

        $this->assertSame('desktop.rendered', $event->broadcastAs());
        // The payload now carries app + window alongside the tree (D5).
        $this->assertSame(
            ['app' => 'notes', 'window' => 'notes', 'tree' => ['type' => 'window']],
            $event->broadcastWith(),
        );
    }

    public function test_desktop_id_and_tree_stay_first_and_last_for_property_access(): void
    {
        // The compat hinge: existing $e->desktopId / $e->tree readers must survive.
        $event = new DesktopRendered('abc-123', 'hello', 'hello', ['type' => 'window']);

        $this->assertSame('abc-123', $event->desktopId);
        $this->assertSame(['type' => 'window'], $event->tree);
    }
}
