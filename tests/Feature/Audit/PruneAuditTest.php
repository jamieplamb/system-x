<?php

namespace Tests\Feature\Audit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use SystemX\Core\Audit\AuditActivity;
use SystemX\Core\Audit\AuditChange;
use Tests\TestCase;

class PruneAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_prunes_both_audit_tables_older_than_the_ttl(): void
    {
        $oldActivity = AuditActivity::create(['correlation_id' => 'old', 'principal_type' => 'user',
            'principal_id' => '1', 'app' => 'hello', 'event' => 'click', 'outcome' => 'ok']);
        $oldActivity->forceFill(['created_at' => Carbon::now()->subDays(3)])->save();
        AuditActivity::create(['correlation_id' => 'new', 'principal_type' => 'user',
            'principal_id' => '1', 'app' => 'hello', 'event' => 'click', 'outcome' => 'ok']);

        $oldChange = AuditChange::create(['correlation_id' => 'old', 'principal_type' => 'user',
            'principal_id' => '1', 'app' => 'hello', 'property' => 'count',
            'old_value' => json_encode(0), 'new_value' => json_encode(1)]);
        $oldChange->forceFill(['created_at' => Carbon::now()->subDays(3)])->save();
        AuditChange::create(['correlation_id' => 'new', 'principal_type' => 'user',
            'principal_id' => '1', 'app' => 'hello', 'property' => 'count',
            'old_value' => json_encode(1), 'new_value' => json_encode(2)]);

        $this->artisan('system-x:prune-audit', ['--hours' => 24])->assertSuccessful();

        $this->assertSame(1, AuditActivity::query()->count());
        $this->assertSame(1, AuditChange::query()->count());
    }
}
