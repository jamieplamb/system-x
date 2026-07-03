<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use SystemX\Core\Runtime\AppKernel;
use SystemX\Core\Runtime\WidgetEvent;
use SystemX\Core\State\DatabaseStateStore;
use SystemX\Core\State\StateBag;
use SystemX\Core\State\StateKey;
use SystemX\Core\State\StateStore;
use SystemX\Core\State\WindowState;
use Tests\TestCase;

class AppKernelTest extends TestCase
{
    use RefreshDatabase;

    // The hydrate-before-dispatch regression net (Task 4 review carry-forward): the
    // kernel MUST hydrate the app from the bag BEFORE boot() dispatches, or the handler
    // closes over the default count (0 -> 1) and the durable 4 is silently lost. Seeding
    // 4 and asserting 5 proves the dispatched handler saw the HYDRATED state.
    public function test_handle_hydrates_dispatches_persists_and_returns_the_rerendered_tree(): void
    {
        $key = new StateKey('user', '1', 'hello');
        $this->app->make(StateStore::class)->save(
            $key,
            new StateBag(['count' => 4], DatabaseStateStore::SCHEMA_VERSION),
        );

        $result = $this->app->make(AppKernel::class)->handle(
            $key,
            'hello',
            new WidgetEvent('clicker', 'click', null, []),
        );

        // Re-rendered tree reflects the increment off the HYDRATED 4, not the default 0...
        $this->assertSame('Clicked 5 times', $result->tree['children'][0]['props']['text']);
        // ...and the mutated count is persisted.
        $this->assertSame(5, $this->app->make(StateStore::class)->load($key)->get('count'));
    }

    public function test_render_initial_renders_the_persisted_count_without_dispatching(): void
    {
        $key = new StateKey('user', '1', 'hello');
        $this->app->make(StateStore::class)->save(
            $key,
            new StateBag(['count' => 3], DatabaseStateStore::SCHEMA_VERSION),
        );

        $tree = $this->app->make(AppKernel::class)->renderInitial($key, 'hello');

        $this->assertSame('Clicked 3 times', $tree['children'][0]['props']['text']);
        $this->assertSame(3, $this->app->make(StateStore::class)->load($key)->get('count')); // unchanged
    }

    // The keyless GET path (N3): render the registry app from an EMPTY ad-hoc bag and
    // NEVER touch the store. Matches 4a's keyless window(0) -> Clicked 0 times.
    public function test_render_from_bag_renders_from_an_ad_hoc_bag_without_touching_the_store(): void
    {
        $tree = $this->app->make(AppKernel::class)->renderFromBag('hello', []);

        $this->assertSame('Clicked 0 times', $tree['children'][0]['props']['text']);
        // No store write: the table is empty.
        $this->assertSame(0, WindowState::query()->count());
    }
}
