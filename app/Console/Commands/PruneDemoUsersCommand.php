<?php

namespace App\Console\Commands;

use App\Support\DemoUserPruner;
use Illuminate\Console\Command;

// Thin wrapper over DemoUserPruner, scheduled every 15 min when demo mode is on (routes/console.php).
class PruneDemoUsersCommand extends Command
{
    protected $signature = 'system-x:prune-demo-users';

    protected $description = 'Delete idle live-demo users and all their per-user state.';

    public function handle(DemoUserPruner $pruner): int
    {
        $count = $pruner->prune();
        $this->info("Pruned {$count} idle demo user(s).");

        return self::SUCCESS;
    }
}
