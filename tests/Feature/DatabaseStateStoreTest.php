<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use SystemX\Core\State\DatabaseStateStore;
use SystemX\Core\State\StateBag;
use SystemX\Core\State\StateKey;
use SystemX\Core\State\WindowState;
use Tests\TestCase;

class DatabaseStateStoreTest extends TestCase
{
    use RefreshDatabase;

    private function store(): DatabaseStateStore
    {
        return $this->app->make(DatabaseStateStore::class);
    }

    public function test_load_returns_an_empty_default_bag_for_a_missing_row(): void
    {
        $bag = $this->store()->load(new StateKey('desktop', 'desk-1', 'win-1'));

        $this->assertSame([], $bag->toArray());
        $this->assertSame(DatabaseStateStore::SCHEMA_VERSION, $bag->version);
    }

    public function test_save_then_load_round_trips_the_bag(): void
    {
        $key = new StateKey('desktop', 'desk-1', 'win-1');

        $this->store()->save($key, new StateBag(['count' => 5], DatabaseStateStore::SCHEMA_VERSION));

        $this->assertSame(5, $this->store()->load($key)->get('count'));
        // One row, keyed on the composite unique -- save() is an upsert, not an insert.
        $this->store()->save($key, new StateBag(['count' => 6], DatabaseStateStore::SCHEMA_VERSION));
        $this->assertSame(1, WindowState::query()->count());
        $this->assertSame(6, $this->store()->load($key)->get('count'));
    }

    public function test_load_discards_a_bag_whose_schema_version_does_not_match(): void
    {
        Log::spy();
        $key = new StateKey('desktop', 'desk-1', 'win-1');

        // Persist a row stamped with an OLD schema version directly.
        WindowState::query()->create([
            'principal_type' => 'desktop',
            'principal_id' => 'desk-1',
            'window_id' => 'win-1',
            'bag' => ['count' => 99],
            'schema_version' => DatabaseStateStore::SCHEMA_VERSION + 1, // mismatched
        ]);

        $bag = $this->store()->load($key);

        // Discarded: a fresh empty bag at the current version, NOT the stale count.
        $this->assertSame([], $bag->toArray());
        $this->assertSame(DatabaseStateStore::SCHEMA_VERSION, $bag->version);
        Log::shouldHaveReceived('info')->once();
    }

    public function test_forget_deletes_the_row(): void
    {
        $key = new StateKey('desktop', 'desk-1', 'win-1');
        $this->store()->save($key, new StateBag(['count' => 1], DatabaseStateStore::SCHEMA_VERSION));

        $this->store()->forget($key);

        $this->assertSame(0, WindowState::query()->count());
        $this->assertSame([], $this->store()->load($key)->toArray()); // back to default
    }
}
