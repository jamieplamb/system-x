<?php

namespace SystemX\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use SystemX\Core\State\WindowState;

// Abandoned-window GC (D5). Chunked GLOBAL DELETE over the single-column
// window_state_gc index on updated_at so a big sweep never long-locks. OFF the
// read-your-write path -- safe to schedule daily. TTL (24h default) is comfortably
// longer than any dev/Dusk session idle. NOTE the inherited gotcha for 4b/4c: it
// prunes on updated_at = last MUTATION, so an idle-but-open window could be reaped;
// tolerable in 4a (TTL >> session, no live-but-silent-window scenario yet).
class PruneWindowStateCommand extends Command
{
    protected $signature = 'system-x:prune-state {--hours=24 : Delete window state not touched in this many hours}';

    protected $description = 'Prune abandoned per-window state older than the TTL.';

    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $cutoff = Carbon::now()->subHours($hours);

        $deleted = WindowState::pruneOlderThan($cutoff);

        $this->info("Pruned {$deleted} abandoned window state row(s) older than {$hours}h.");

        return self::SUCCESS;
    }
}
