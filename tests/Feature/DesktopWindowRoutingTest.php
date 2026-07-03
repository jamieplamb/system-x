<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use SystemX\Core\State\StatePrincipalResolver;
use Tests\TestCase;

class DesktopWindowRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_resolver_prefers_the_posted_window_id(): void
    {
        $user = User::factory()->create();

        $request = Request::create('/system-x/event', 'POST', ['window' => 'notes']);
        $request->setUserResolver(fn () => $user);
        $request->setLaravelSession($this->app['session']->driver());
        $request->session()->put('sx_window_id', 'hello'); // session says hello...

        $key = (new StatePrincipalResolver)->resolve($request);

        $this->assertSame('notes', $key->windowId); // ...but the POSTed window wins (D7)
    }

    public function test_the_resolver_reads_the_window_off_the_get_query_string(): void
    {
        // input('window') reads the QUERY STRING on a GET (the resync) and the BODY on a
        // POST (the event) -- one accessor, no query() special-casing. ?window=hello on
        // the GET resync resolves to the slug-keyed bag.
        $user = User::factory()->create();

        $request = Request::create('/system-x/desktop?window=hello', 'GET');
        $request->setUserResolver(fn () => $user);
        $request->setLaravelSession($this->app['session']->driver());
        $request->session()->put('sx_window_id', 'win-1'); // session disagrees...

        $key = (new StatePrincipalResolver)->resolve($request);

        $this->assertSame('hello', $key->windowId); // ...the wire query wins
    }

    public function test_it_returns_null_with_no_authenticated_user(): void
    {
        $request = Request::create('/system-x/event', 'POST', ['window' => 'notes']);
        // no user resolver -> guest -> null (principal is the user now, 4c)

        $this->assertNull((new StatePrincipalResolver)->resolve($request));
    }

    public function test_it_returns_null_with_no_wire_window(): void
    {
        // The session window-id fallback is dropped (D5): without a wire `window` the
        // resolver returns null even for an authenticated user with a session uuid.
        $user = User::factory()->create();

        $request = Request::create('/system-x/event', 'POST');
        $request->setUserResolver(fn () => $user);
        $request->setLaravelSession($this->app['session']->driver());
        $request->session()->put('sx_window_id', 'hello'); // session window id no longer counts

        $this->assertNull((new StatePrincipalResolver)->resolve($request));
    }
}
