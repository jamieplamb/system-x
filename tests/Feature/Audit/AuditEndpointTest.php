<?php

namespace Tests\Feature\Audit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SystemX\Core\Audit\AuditActivity;
use SystemX\Core\Audit\AuditChange;
use Tests\TestCase;

class AuditEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_only_the_authenticated_users_recent_activity(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();

        AuditActivity::create(['correlation_id' => 'mine', 'principal_type' => 'user',
            'principal_id' => (string) $me->id, 'app' => 'hello', 'event' => 'click', 'outcome' => 'ok']);
        AuditActivity::create(['correlation_id' => 'theirs', 'principal_type' => 'user',
            'principal_id' => (string) $other->id, 'app' => 'hello', 'event' => 'click', 'outcome' => 'ok']);

        $response = $this->actingAs($me)->getJson('/system-x/audit');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.correlation_id', 'mine');
    }

    public function test_nests_change_rows_under_their_activity(): void
    {
        $me = User::factory()->create();
        AuditActivity::create(['correlation_id' => 'c1', 'principal_type' => 'user',
            'principal_id' => (string) $me->id, 'app' => 'hello', 'event' => 'click', 'outcome' => 'ok']);
        AuditChange::create(['correlation_id' => 'c1', 'principal_type' => 'user',
            'principal_id' => (string) $me->id, 'app' => 'hello', 'property' => 'count',
            'old_value' => json_encode(0), 'new_value' => json_encode(1)]);

        $response = $this->actingAs($me)->getJson('/system-x/audit');
        $response->assertJsonPath('data.0.changes.0.property', 'count');
        // The resource json_decodes the JSON-text columns, so old/new come back as real
        // scalars (0/1), NOT the strings "0"/"1" -- the whole reason for the decode.
        $response->assertJsonPath('data.0.changes.0.old', 0);
        $response->assertJsonPath('data.0.changes.0.new', 1);
    }
}
