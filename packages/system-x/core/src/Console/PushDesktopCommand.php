<?php

namespace SystemX\Core\Console;

use Illuminate\Console\Command;
use SystemX\Core\Events\DesktopRendered;
use SystemX\Core\Runtime\AppKernel;

class PushDesktopCommand extends Command
{
    protected $signature = 'system-x:push {desktopId} {count=99} {--app=hello}';

    protected $description = 'Push a freshly rendered app tree to an open desktop over Reverb.';

    public function handle(AppKernel $kernel): int
    {
        $slug = (string) $this->option('app');

        // The push renders the app's tree WITHOUT touching the store (an unsolicited
        // pure render -- writing would corrupt the user's durable state, the 4a
        // PushDesktopCommand invariant). renderFromBag hydrates a FRESH app from an
        // ad-hoc bag and renders with NO save. The bag is built HERE, app-side -- the
        // kernel stays app-agnostic (S4). For HelloApp the ad-hoc bag is ['count' => N];
        // a future non-count app would build its own ad-hoc bag (or pass [] for its
        // initial tree). Default --app=hello, the only count-shaped demo app.
        $bag = $slug === 'hello' ? ['count' => (int) $this->argument('count')] : [];
        $tree = $kernel->renderFromBag($slug, $bag);

        // Bare broadcast() statement on purpose -- PendingBroadcast dispatches on __destruct.
        // Do not assign to a variable (it would defer the dispatch past any Event::fake assertions).
        broadcast(new DesktopRendered($this->argument('desktopId'), $slug, $slug, $tree));

        $this->info("Pushed to user.{$this->argument('desktopId')} window {$slug}");

        return self::SUCCESS;
    }
}
