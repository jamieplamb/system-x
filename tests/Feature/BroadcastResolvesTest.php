<?php

namespace Tests\Feature;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;
use SystemX\Core\Events\DesktopRendered;
use Tests\TestCase;

class BroadcastResolvesTest extends TestCase
{
    public function test_desktop_rendered_event_resolves_and_targets_the_user_channel(): void
    {
        // ctor (D5): desktopId, appSlug, windowId, tree. Only desktopId drives the channel.
        $event = new DesktopRendered('7', 'files', 'win-1', []);

        $channel = $event->broadcastOn();

        $this->assertInstanceOf(PrivateChannel::class, $channel);
        // PrivateChannel prefixes the registered name with 'private-'.
        $this->assertSame('private-user.7', $channel->name);
    }

    public function test_broadcasting_auth_route_is_registered_by_the_package(): void
    {
        // Channel registration moved off the host bootstrap into the package provider.
        // If that move silently dropped the route, live updates die on a fresh consumer
        // install -- this guards that the provider still publishes /broadcasting/auth.
        //
        // Asserted by URI, not by name: Broadcast::routes() registers the endpoint WITHOUT
        // a route name in Laravel 13, so Route::has('broadcasting.auth') is always false even
        // though the endpoint dispatches fine (see ChannelAuthTest, which POSTs the URI).
        $registered = collect(Route::getRoutes()->getRoutes())
            ->contains(fn ($route): bool => $route->uri() === 'broadcasting/auth');

        $this->assertTrue($registered, 'The package provider must register the /broadcasting/auth endpoint.');
    }

    public function test_user_channel_callback_is_registered(): void
    {
        // Complements ChannelAuthTest (which POSTs /broadcasting/auth to prove the A-vs-B
        // auth predicate). Here we assert the registration surface directly: the package
        // provider wired the user.{id} channel callback onto the broadcaster at boot.
        $channels = Broadcast::getChannels();

        $this->assertArrayHasKey('user.{id}', $channels->all());
    }
}
