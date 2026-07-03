<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use SystemX\Core\State\DatabaseStateStore;
use SystemX\Core\State\WindowState;
use Tests\TestCase;

class PruneWindowStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_deletes_rows_older_than_the_ttl_and_keeps_fresh_ones(): void
    {
        // A stale row (updated 30h ago) and a fresh row (updated now).
        $stale = WindowState::query()->create([
            'principal_type' => 'desktop',
            'principal_id' => 'old-desk',
            'window_id' => 'win-1',
            'bag' => ['count' => 1],
            'schema_version' => DatabaseStateStore::SCHEMA_VERSION,
        ]);
        $stale->forceFill(['updated_at' => Carbon::now()->subHours(30)])->saveQuietly();

        WindowState::query()->create([
            'principal_type' => 'desktop',
            'principal_id' => 'live-desk',
            'window_id' => 'win-1',
            'bag' => ['count' => 2],
            'schema_version' => DatabaseStateStore::SCHEMA_VERSION,
        ]);

        $this->artisan('system-x:prune-state', ['--hours' => 24])
            ->expectsOutputToContain('Pruned 1 abandoned window state row(s) older than 24h.')
            ->assertSuccessful();

        $this->assertNull(WindowState::query()->where('principal_id', 'old-desk')->first());
        $this->assertNotNull(WindowState::query()->where('principal_id', 'live-desk')->first());
    }

    public function test_prune_older_than_deletes_only_stale_rows_and_reports_the_count(): void
    {
        // Three stale rows (older than the cutoff) and two fresh rows.
        foreach (range(1, 3) as $i) {
            $row = WindowState::query()->create([
                'principal_type' => 'desktop',
                'principal_id' => "stale-{$i}",
                'window_id' => 'win-1',
                'bag' => ['count' => $i],
                'schema_version' => DatabaseStateStore::SCHEMA_VERSION,
            ]);
            $row->forceFill(['updated_at' => Carbon::now()->subHours(48)])->saveQuietly();
        }

        foreach (range(1, 2) as $i) {
            WindowState::query()->create([
                'principal_type' => 'desktop',
                'principal_id' => "fresh-{$i}",
                'window_id' => 'win-1',
                'bag' => ['count' => $i],
                'schema_version' => DatabaseStateStore::SCHEMA_VERSION,
            ]);
        }

        $deleted = WindowState::pruneOlderThan(Carbon::now()->subHours(24));

        // (a) stale rows gone, (b) fresh rows survive, (c) the count is exact.
        $this->assertSame(3, $deleted);
        $this->assertSame(0, WindowState::query()->where('principal_id', 'like', 'stale-%')->count());
        $this->assertSame(2, WindowState::query()->where('principal_id', 'like', 'fresh-%')->count());
    }

    public function test_prune_older_than_sweeps_across_multiple_chunks(): void
    {
        // Seed more stale rows than the chunk size so the chunked loop has to
        // iterate. A chunk of 2 over 5 stale rows forces three passes (2, 2, 1),
        // proving the loop terminates and the count accumulates -- not just one batch.
        foreach (range(1, 5) as $i) {
            $row = WindowState::query()->create([
                'principal_type' => 'desktop',
                'principal_id' => "stale-{$i}",
                'window_id' => 'win-1',
                'bag' => ['count' => $i],
                'schema_version' => DatabaseStateStore::SCHEMA_VERSION,
            ]);
            $row->forceFill(['updated_at' => Carbon::now()->subHours(48)])->saveQuietly();
        }

        // One fresh row that must survive the multi-batch sweep.
        WindowState::query()->create([
            'principal_type' => 'desktop',
            'principal_id' => 'fresh-1',
            'window_id' => 'win-1',
            'bag' => ['count' => 99],
            'schema_version' => DatabaseStateStore::SCHEMA_VERSION,
        ]);

        $deleted = WindowState::pruneOlderThan(Carbon::now()->subHours(24), 2);

        $this->assertSame(5, $deleted);
        $this->assertSame(0, WindowState::query()->where('principal_id', 'like', 'stale-%')->count());
        $this->assertSame(1, WindowState::query()->where('principal_id', 'fresh-1')->count());
    }
}
