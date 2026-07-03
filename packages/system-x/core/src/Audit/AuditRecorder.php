<?php

namespace SystemX\Core\Audit;

// The single audit writer (audit plan §5). ONE entrypoint -- record() writes the activity row
// and, when a delta is given, the change rows in the same call, so a change row with no parent
// activity row is unrepresentable. Every value (activity value/payload AND change old/new) goes
// through the redactor here. Change old/new are json_encoded at this boundary (the column is
// JSON text, no array cast -- a scalar 0/1 must round-trip).
class AuditRecorder
{
    public function __construct(private AuditRedactor $redactor) {}

    /**
     * @param  array<string, array{0: mixed, 1: mixed}>|null  $delta
     * @param  array<string, mixed>  $payload
     */
    public function record(
        AuditContext $ctx,
        string $event,
        string $outcome,
        ?array $delta = null,
        mixed $value = null,
        array $payload = [],
        ?string $widgetId = null,
    ): void {
        AuditActivity::create([
            'correlation_id' => $ctx->correlationId,
            'principal_type' => $ctx->principalType,
            'principal_id' => $ctx->principalId,
            'app' => $ctx->app,
            'window_id' => $ctx->windowId,
            'widget_id' => $widgetId,
            'event' => $event,
            'outcome' => $outcome,
            'value' => $value === null ? null : $this->redactor->redact($value),
            'payload' => $payload === [] ? null : $this->redactor->redact($payload),
            'ip' => $ctx->ip,
            'user_agent' => $ctx->userAgent,
        ]);

        foreach ($delta ?? [] as $property => [$old, $new]) {
            AuditChange::create([
                'correlation_id' => $ctx->correlationId,
                'principal_type' => $ctx->principalType,
                'principal_id' => $ctx->principalId,
                'app' => $ctx->app,
                'window_id' => $ctx->windowId,
                'property' => $property,
                'old_value' => json_encode($this->redactor->redact($old)),
                'new_value' => json_encode($this->redactor->redact($new)),
            ]);
        }
    }
}
