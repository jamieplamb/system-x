<?php

namespace SystemX\Core\State;

// The read/write SEAM. StateKey IN, StateBag OUT -- NOTHING about apps, handlers,
// dispatch, or declared properties leaks onto this interface. 4a drives it
// imperatively for one int (load -> get('count') -> ++ -> with('count') -> save);
// 4b's App base class calls the IDENTICAL load/save but maps the bag to declared
// properties instead -- the store, table, and VOs are untouched. GC is NOT on the
// contract (it lives on the model + a console command), keeping the interface to
// the three methods 4b reuses. Only DatabaseStateStore implements this in 4a;
// RedisStateStore/BrowserEchoStore are future drivers behind the same interface.
interface StateStore
{
    public function load(StateKey $key): StateBag;

    public function save(StateKey $key, StateBag $bag): void;

    public function forget(StateKey $key): void;
}
