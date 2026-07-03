<?php

namespace SystemX\Core\Audit;

// The PII redaction hook (audit plan §8). Bound to NullAuditRedactor by default; a host rebinds
// it to scrub sensitive values. Applied to BOTH activity value/payload AND change old/new.
interface AuditRedactor
{
    public function redact(mixed $value): mixed;
}
