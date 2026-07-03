<?php

namespace SystemX\Core\Runtime;

use SystemX\Core\Audit\BagDiff;
use SystemX\Core\State\DatabaseStateStore;
use SystemX\Core\State\StateBag;
use SystemX\Core\State\StateKey;
use SystemX\Core\State\StateStore;
use SystemX\Core\Wire\Serializer;

// The runtime lifecycle (D4). handle() is the full event path; renderInitial() is the
// boot/resync read; renderFromBag() is the no-store ad-hoc render. All reuse the 4a
// StateStore VERBATIM (load/save, StateKey in, StateBag out) -- the App<->bag mapping is
// PropertyHydrator's job, here we just drive load -> hydrate -> {dispatch} ->
// {dehydrate -> save} -> serialize. The store is untouched (D3). handle() is called by
// the controller INSIDE the existing locked transaction (D4) -- this class does NOT open
// its own transaction.
class AppKernel
{
    public function __construct(
        private AppRegistry $apps,
        private StateStore $store,
        private PropertyHydrator $hydrator,
        private Serializer $serializer = new Serializer,
    ) {}

    public function handle(StateKey $key, string $appSlug, WidgetEvent $event): HandleResult
    {
        $app = $this->apps->resolve($appSlug);

        // HYDRATE BEFORE DISPATCH (load-bearing): boot() does NOT hydrate itself, so the
        // dispatched handler must close over the HYDRATED state. Hydrate-then-boot or the
        // durable count silently resets to its default.
        $this->hydrator->hydrate($app, $this->store->load($key));

        // Snapshot A: full property set AFTER hydrate, BEFORE dispatch (audit plan §6) -- defaults
        // -plus-loaded, so a first interaction diffs 0 -> 1, never null -> 1.
        $before = $this->hydrator->dehydrate($app);

        $tree = $app->boot($event); // render+bind -> dispatch -> re-render

        // Snapshot B: after the handler. This bag is BOTH persisted AND the delta's "new" side.
        $after = $this->hydrator->dehydrate($app);

        $this->store->save($key, new StateBag($after, DatabaseStateStore::SCHEMA_VERSION));

        return new HandleResult(
            $this->serializer->serialize($tree),
            BagDiff::between($before, $after),
        );
    }

    /** @return array<string, mixed> */
    public function renderInitial(StateKey $key, string $appSlug): array
    {
        $app = $this->apps->resolve($appSlug);
        $this->hydrator->hydrate($app, $this->store->load($key));

        return $this->serializer->serialize($app->renderInitial());
    }

    // The generic, app-agnostic no-store render: resolve the app, hydrate from an AD-HOC
    // bag, serialize renderInitial() WITHOUT any store write. Serves the keyless desktop()
    // branch (N3) and the unsolicited push (Task 12), so there is ONE no-store render path.
    /**
     * @param  array<string, mixed>  $bag
     * @return array<string, mixed>
     */
    public function renderFromBag(string $appSlug, array $bag): array
    {
        $app = $this->apps->resolve($appSlug);
        $this->hydrator->hydrate($app, new StateBag($bag, DatabaseStateStore::SCHEMA_VERSION));

        return $this->serializer->serialize($app->renderInitial());
    }
}
