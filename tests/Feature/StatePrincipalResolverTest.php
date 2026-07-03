<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use SystemX\Core\State\StatePrincipalResolver;
use Tests\TestCase;

class StatePrincipalResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resolves_a_user_principal_from_the_authenticated_user(): void
    {
        $user = User::factory()->create();

        $request = Request::create('/system-x/event', 'POST', ['window' => 'hello']);
        $request->setUserResolver(fn () => $user);

        $key = (new StatePrincipalResolver)->resolve($request);

        $this->assertNotNull($key);
        // principalType is now 'user', principalId is the (string) int user id -- the
        // SAME StateKey shape, a different VALUE (D4). principal_id is a string column,
        // so the int user id fits with no migration.
        $this->assertSame('user', $key->principalType);
        $this->assertSame((string) $user->id, $key->principalId);
        // The window id is WIRE-ONLY now (D5) -- the POSTed `window`, no session fallback.
        $this->assertSame('hello', $key->windowId);
    }

    public function test_it_returns_null_when_there_is_no_authenticated_user(): void
    {
        $request = Request::create('/system-x/event', 'POST', ['window' => 'hello']);
        // No user resolver -> guest -> null key (the controller bails exactly as before).

        $this->assertNull((new StatePrincipalResolver)->resolve($request));
    }

    public function test_it_returns_null_when_no_window_is_on_the_wire(): void
    {
        $user = User::factory()->create();

        $request = Request::create('/system-x/event', 'POST'); // no `window`
        $request->setUserResolver(fn () => $user);

        // The session fallback is GONE (D5): no wire window -> null. Every real client
        // sends window={slug}; a request without one is not a legitimate path.
        $this->assertNull((new StatePrincipalResolver)->resolve($request));
    }

    public function test_a_session_window_id_no_longer_resolves_without_a_wire_window(): void
    {
        $user = User::factory()->create();

        // A stale sx_window_id in the session must NOT key the bag any more (D5): the
        // session fallback is dropped, so without a wire `window` this resolves null.
        $request = Request::create('/system-x/event', 'POST');
        $request->setUserResolver(fn () => $user);
        $request->setLaravelSession($this->app['session']->driver());
        $request->session()->put('sx_window_id', 'win-1');

        $this->assertNull((new StatePrincipalResolver)->resolve($request));
    }
}
