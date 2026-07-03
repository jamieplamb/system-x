<?php

namespace SystemX\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use SystemX\Core\Audit\AuditActivity;
use SystemX\Core\Audit\AuditChange;

// Audit retention GC (audit plan §8). Sweeps BOTH audit tables by created_at over their
// standalone created_at index. Off the request path -- safe to schedule daily. Default 720h
// (30 days): audit retention is naturally longer than the 24h state TTL.
class PruneAuditCommand extends Command
{
    protected $signature = 'system-x:prune-audit {--hours=720 : Delete audit rows older than this many hours}';

    protected $description = 'Prune audit activity + change rows older than the retention TTL.';

    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $cutoff = Carbon::now()->subHours($hours);

        $activity = AuditActivity::pruneOlderThan($cutoff);
        $changes = AuditChange::pruneOlderThan($cutoff);

        $this->info("Pruned {$activity} activity + {$changes} change audit row(s) older than {$hours}h.");

        return self::SUCCESS;
    }
}
