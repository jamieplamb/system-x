<?php

namespace Tests\Feature\Audit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use SystemX\Core\Audit\AuditActivity;
use SystemX\Core\Audit\AuditChange;
use SystemX\Core\Audit\AuditContext;
use SystemX\Core\Audit\AuditRecorder;
use SystemX\Core\Audit\AuditRedactor;
use SystemX\Core\Audit\NullAuditRedactor;
use Tests\TestCase;

class AuditRecorderTest extends TestCase
{
    use RefreshDatabase;

    private function ctx(): AuditContext
    {
        return new AuditContext('cid-1', 'user', '7', 'hello', 'win-1', '10.0.0.5', 'phpunit');
    }

    public function test_records_one_activity_row(): void
    {
        (new AuditRecorder(new NullAuditRedactor))
            ->record($this->ctx(), 'click', 'ok', null, ['v' => 1], ['p' => 2], 'save');

        $row = AuditActivity::query()->sole();
        $this->assertSame('cid-1', $row->correlation_id);
        $this->assertSame('ok', $row->outcome);
        $this->assertSame('save', $row->widget_id);
        $this->assertSame(['v' => 1], $row->value);
    }

    public function test_records_change_rows_from_delta_sharing_the_correlation_id(): void
    {
        (new AuditRecorder(new NullAuditRedactor))
            ->record($this->ctx(), 'click', 'ok', ['count' => [0, 1]]);

        $change = AuditChange::query()->sole();
        $this->assertSame('cid-1', $change->correlation_id);
        $this->assertSame('count', $change->property);
        $this->assertSame(0, json_decode($change->old_value, true));
        $this->assertSame(1, json_decode($change->new_value, true));
        $this->assertSame(1, AuditActivity::query()->count()); // activity ALWAYS written too
    }

    public function test_no_delta_writes_no_change_rows(): void
    {
        (new AuditRecorder(new NullAuditRedactor))->record($this->ctx(), 'window.launch', 'ok');

        $this->assertSame(1, AuditActivity::query()->count());
        $this->assertSame(0, AuditChange::query()->count());
    }

    public function test_redactor_scrubs_activity_value_and_change_values(): void
    {
        $redactor = new class implements AuditRedactor
        {
            public function redact(mixed $value): mixed
            {
                return '[redacted]';
            }
        };

        (new AuditRecorder($redactor))->record(
            $this->ctx(), 'click', 'ok', ['secret' => ['old', 'new']], ['pw' => 'hunter2'], [], 'field',
        );

        $this->assertSame('[redacted]', AuditActivity::query()->sole()->value);
        $change = AuditChange::query()->sole();
        $this->assertSame('[redacted]', json_decode($change->old_value, true));
        $this->assertSame('[redacted]', json_decode($change->new_value, true));
    }
}
