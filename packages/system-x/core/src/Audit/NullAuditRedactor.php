<?php

namespace SystemX\Core\Audit;

class NullAuditRedactor implements AuditRedactor
{
    public function redact(mixed $value): mixed
    {
        return $value;
    }
}
