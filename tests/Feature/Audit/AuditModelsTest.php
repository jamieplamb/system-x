<?php

namespace Tests\Feature\Audit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use SystemX\Core\Audit\AuditActivity;
use SystemX\Core\Audit\AuditChange;
use Tests\TestCase;

class AuditModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_activity_row_persists_with_json_casts(): void
    {
        $row = AuditActivity::create([
            'correlation_id' => 'cid-1', 'principal_type' => 'user', 'principal_id' => '7',
            'app' => 'notes', 'window_id' => 'win-1', 'widget_id' => 'save', 'event' => 'click',
            'outcome' => 'ok', 'value' => ['typed' => 'hi'], 'payload' => [],
            'ip' => '10.0.0.5', 'user_agent' => 'phpunit',
        ]);

        $this->assertSame(['typed' => 'hi'], $row->fresh()->value);
        $this->assertSame([], $row->fresh()->payload); // the [] round-trips as [], not {} or null
    }

    public function test_change_row_stores_old_and_new_as_json_text(): void
    {
        // old/new are JSON TEXT (no Eloquent array cast -- a scalar 0/1 must round-trip),
        // encoded by the recorder. The model stays dumb; assert via json_decode.
        $row = AuditChange::create([
            'correlation_id' => 'cid-1', 'principal_type' => 'user', 'principal_id' => '7',
            'app' => 'hello', 'window_id' => 'win-1', 'property' => 'count',
            'old_value' => json_encode(0), 'new_value' => json_encode(1),
        ]);

        $this->assertSame(0, json_decode($row->fresh()->old_value, true));
        $this->assertSame(1, json_decode($row->fresh()->new_value, true));
    }

    public function test_prune_deletes_rows_older_than_cutoff(): void
    {
        $old = AuditActivity::create([
            'correlation_id' => 'old', 'principal_type' => 'user', 'principal_id' => '1',
            'app' => 'hello', 'event' => 'click', 'outcome' => 'ok',
        ]);
        $old->forceFill(['created_at' => Carbon::now()->subDays(3)])->save();

        AuditActivity::create([
            'correlation_id' => 'new', 'principal_type' => 'user', 'principal_id' => '1',
            'app' => 'hello', 'event' => 'click', 'outcome' => 'ok',
        ]);

        $this->assertSame(1, AuditActivity::pruneOlderThan(Carbon::now()->subDay()));
        $this->assertSame(1, AuditActivity::query()->count());
    }
}
