<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use SystemX\ProDataGrid\Demo\Camera;
use SystemX\ProDataGrid\Demo\database\seeders\DemoGridSeeder;
use SystemX\ProDataGrid\Demo\Site;
use Tests\DuskTestCase;

// The DG-1 end-to-end: the pro-datagrid package, consumed by the host via the path repo, renders a
// real sortable/paginated grid on the served desktop and round-trips sort + page through ctx.emit
// back to a server re-query. Uses the `sxpro.demo` demo app (title 'Cameras'), registered in
// local/testing/ci, backed by the sxpro_cameras/sxpro_sites tables (30 cameras / 2 sites) that this
// test seeds itself (idempotent) so it never depends on CI running db:seed -- CI only runs migrate.
//
// What it proves that the vitest/PHPUnit can't:
//   - the vendor `sxpro` bundle (renderer + CSS) actually loaded and painted a <table class=sx-datagrid>
//   - a ->badge() cell renders a styled .sx-badge, not raw text
//   - a ->align('right') column stamps data-sx-align on the BODY cell (the Part-A fix), not just <th>
//   - clicking a sortable header re-queries server-side and reorders the rows (a true round-trip)
//   - the pager re-queries a different page
//   - the sort column + page are DURABLE: a full page reload restores them
class ProDataGridTest extends DuskTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Self-seed the demo grid data (idempotent -- the seeder no-ops if cameras already exist),
        // so the grid has rows regardless of whether the environment ran db:seed. The tables come
        // from the provider's env-guarded migration, which CI's `migrate --force` applies.
        $this->seed(DemoGridSeeder::class);
    }

    public function test_the_datagrid_renders_sorts_pages_and_persists_across_reload(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAsDemoUser($browser)->waitFor('[data-window-id="hello"] .sx-window');

            // Open the Cameras grid via the launcher. On a fresh login the launcher button can take a
            // beat to wire up, so give the click a short settle then wait on the Cameras tile (the
            // launcher overlay hydrates its tiles asynchronously). Then click it and wait for the
            // overlay to close.
            $browser->waitFor('[data-sx-launcher]', 10)
                ->click('[data-sx-launcher]')
                ->pause(500)
                ->waitFor('[data-sx-launch="sxpro.demo"]', 10)
                ->click('[data-sx-launch="sxpro.demo"]')
                ->waitUntilMissing('[data-sx-launch]', 10);

            // The grid window is live -- wait on the surface, then read its server-assigned window id
            // (the launcher lets the server allocate it, so we can't hardcode it like hello/notes).
            $browser->waitFor('.sx-window-surface[data-app="sxpro.demo"] .sx-window', 10);
            $windowId = $browser->script(
                "return document.querySelector('.sx-window-surface[data-app=\"sxpro.demo\"]').dataset.windowId;"
            )[0];
            $win = "[data-window-id=\"{$windowId}\"]";

            // The vendor bundle loaded and painted the grid: a real <table class="sx-datagrid"> with
            // body rows (not the unknown-widget placeholder). If the sxpro dist hadn't loaded, no table.
            $browser->waitFor("{$win} table.sx-datagrid", 10)
                ->waitFor("{$win} table.sx-datagrid tbody tr", 10);
            $rowCount = $browser->script(
                "return document.querySelectorAll('{$win} table.sx-datagrid tbody tr').length;"
            )[0];
            $this->assertGreaterThan(1, $rowCount, 'the grid rendered no rows');

            // A ->badge() status cell renders a styled .sx-badge pill (core Badge renderer), NOT raw
            // "active"/"fault" text -- the grid's node cells route through the real widget registry.
            $browser->assertPresent("{$win} table.sx-datagrid tbody tr td .sx-badge");

            // The Part-A fix: reads_today is ->align('right'), so its BODY cell carries
            // data-sx-align="right", not just the header. A broken promise in DG-1 before this task.
            // Target the cell by data-sx-col (not a positional child) -- DG-5 added a leading select
            // syscol column, so the reads_today cell is no longer at a fixed child index.
            $browser->assertPresent("{$win} table.sx-datagrid tbody tr td[data-sx-align=\"right\"]");
            $bodyAlign = $browser->script(
                "return document.querySelector('{$win} table.sx-datagrid tbody tr td[data-sx-col=\"reads_today\"]').getAttribute('data-sx-align');"
            )[0];
            $this->assertSame('right', $bodyAlign, 'the reads_today body cell is not right-aligned');

            // --- Sort round-trip. Default order is natural (Camera 01 first). Click the `name` header
            // once -> asc (still Camera 01, no visible change), so click AGAIN -> desc (Camera 30 first).
            // That reorder can ONLY come from the server re-querying and re-broadcasting the rows. ---
            // Address the name cell by data-sx-col, not td:first-child -- DG-5's leading select syscol
            // column now occupies the first cell, so the name cell is not tr's first child.
            $firstNameSelector = "{$win} table.sx-datagrid tbody tr:first-child td[data-sx-col=\"name\"]";
            $this->assertSame('Camera 01', trim($browser->text($firstNameSelector)));

            // Dispatch the sort click on the <th> element itself, not a coordinate click: the name
            // column now carries a filter funnel (it's ->textFilter()->searchable()), and a centered
            // Dusk click on the short "Name" header can land on that nested funnel button and open the
            // filter popover instead of sorting. The th's own click listener emits 'sort'; the funnel's
            // stopPropagation only guards ITS own clicks, so dispatching on the th always sorts.
            $nameHeader = "{$win} table.sx-datagrid thead th[data-sx-col=\"name\"]";
            $clickHeader = "document.querySelector('{$nameHeader}').dispatchEvent(new MouseEvent('click', { bubbles: true }));";

            $browser->script($clickHeader);                    // asc
            $browser->pause(400)
                ->waitUsing(10, 100, fn () => $browser->script(
                    "return document.querySelector('{$nameHeader}').dataset.sxSort === 'asc';"
                )[0]);

            $browser->script($clickHeader);                    // desc
            $browser->pause(400)
                ->waitForTextIn($firstNameSelector, 'Camera 30');
            $this->assertSame('Camera 30', trim($browser->text($firstNameSelector)));
            $sortDir = $browser->script(
                "return document.querySelector('{$nameHeader}').dataset.sxSort;"
            )[0];
            $this->assertSame('desc', $sortDir, 'the name header did not settle on desc');

            // --- Pager: next -> page 2. With name desc, page 1 ends at Camera 06, page 2 starts at
            // Camera 05. A different row set, again only from a server re-query. ---
            $browser->click("{$win} table.sx-datagrid [data-sx-pager=\"next\"]")
                ->pause(400)
                ->waitForTextIn($firstNameSelector, 'Camera 05');
            $this->assertSame('Camera 05', trim($browser->text($firstNameSelector)));

            // --- Durable state: a FULL page reload must restore both the sort (name desc) AND the page
            // (2). The grid's view state lives in the durable user bag, so the rehydrated render comes
            // back sorted + paged. Assert the sort indicator and the page-2 first row both survive. ---
            $browser->refresh()
                ->waitFor("{$win} table.sx-datagrid tbody tr")
                ->waitForTextIn($firstNameSelector, 'Camera 05');
            $this->assertSame('Camera 05', trim($browser->text($firstNameSelector)), 'the page did not persist across reload');
            $persistedSort = $browser->script(
                "return document.querySelector('{$nameHeader}').dataset.sxSort;"
            )[0];
            $this->assertSame('desc', $persistedSort, 'the sort did not persist across reload');
        });
    }

    // The DG-2 end-to-end: per-column filtering (the funnel popover), the global search box, and a
    // relation-aware sort, all round-tripping server-side through ctx.emit, then all three surviving a
    // full page reload. What it proves that vitest/PHPUnit can't:
    //   - clicking a funnel opens the real .sx-datagrid-popover with a live <select>, and picking
    //     'fault' re-queries the server so the visible body shrinks to only fault rows (a true join of
    //     filter -> query -> broadcast -> repaint), and the funnel tints .is-active
    //   - the toolbar search box narrows the set server-side to a name match
    //   - clicking the site.name (relation) sortable header reorders by the joined column without error
    //     -- the leftJoin round-tripped
    //   - filter + search + sort are ALL durable: a full reload restores the active funnel, the search
    //     term still in the box, and the filtered/sorted rows
    public function test_the_datagrid_filters_searches_relation_sorts_and_persists_across_reload(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAsDemoUser($browser)->waitFor('[data-window-id="hello"] .sx-window');

            // Open the Cameras grid via the launcher (same flow the DG-1 method uses).
            $browser->waitFor('[data-sx-launcher]', 10)
                ->click('[data-sx-launcher]')
                ->pause(500)
                ->waitFor('[data-sx-launch="sxpro.demo"]', 10)
                ->click('[data-sx-launch="sxpro.demo"]')
                ->waitUntilMissing('[data-sx-launch]', 10);

            $browser->waitFor('.sx-window-surface[data-app="sxpro.demo"] .sx-window', 10);
            $windowId = $browser->script(
                "return document.querySelector('.sx-window-surface[data-app=\"sxpro.demo\"]').dataset.windowId;"
            )[0];
            $win = "[data-window-id=\"{$windowId}\"]";

            $browser->waitFor("{$win} table.sx-datagrid tbody tr", 10);

            // The unfiltered page shows the full first page (25 rows). We assert the filter shrinks
            // below this, so read it first.
            $unfilteredRows = $browser->script(
                "return document.querySelectorAll('{$win} table.sx-datagrid tbody tr').length;"
            )[0];
            $this->assertGreaterThan(6, $unfilteredRows, 'expected a full first page before filtering');

            // --- 1. Filter shrinks + persists. Click the status funnel, wait for the popover, pick
            // 'fault' in its <select>. The seeder gives 6 fault cameras on page 1, so the body must
            // drop to 6 rows and every visible status badge must read 'fault'. That reduction can only
            // come from the server re-querying with the filter applied. ---
            $statusFunnel = "{$win} [data-sx-filter=\"status\"]";
            $browser->click($statusFunnel)
                ->waitFor('.sx-datagrid-popover', 10)
                ->waitFor('.sx-datagrid-popover select', 10);

            // Drive the select directly (set value + fire input/change) rather than Dusk ->select(),
            // which can miss the renderer's change listener. Emitting 'filter' re-queries server-side
            // and re-broadcasts; the funnel tinting .is-active is the settle signal we wait on.
            $browser->script(
                "var s = document.querySelector('.sx-datagrid-popover select');"
                ."s.value = 'fault';"
                ."s.dispatchEvent(new Event('input', { bubbles: true }));"
                ."s.dispatchEvent(new Event('change', { bubbles: true }));"
            );
            $browser->waitFor("{$win} [data-sx-filter=\"status\"].is-active", 10);

            // The body shrank below the unfiltered page AND every visible status badge reads fault --
            // the only way that's true is a server re-query with the filter applied.
            $filteredRows = (int) $browser->script(
                "return document.querySelectorAll('{$win} table.sx-datagrid tbody tr').length;"
            )[0];
            $this->assertGreaterThan(0, $filteredRows, 'the status filter emptied the grid');
            $this->assertLessThan($unfilteredRows, $filteredRows, 'the status filter did not shrink the grid');
            $this->assertTrue(
                $this->everyRowStatusIs($browser, $win, 'fault'),
                'the filtered grid still shows non-fault rows'
            );

            // --- 2. Global search. Clear the filter first (so the search stands alone), then type a
            // specific camera name into the toolbar search box. 'Camera 07' matches exactly one row. ---
            $browser->click("{$win} [data-sx-clear-filters]")
                ->waitUntilMissing("{$win} [data-sx-filter=\"status\"].is-active", 10)
                ->pause(400);

            $browser->type("{$win} .sx-datagrid-search", 'Camera 07')
                ->pause(700) // debounce settle (~300ms) + round-trip
                ->waitForTextIn("{$win} table.sx-datagrid tbody tr:first-child td[data-sx-col=\"name\"]", 'Camera 07');

            // Read the name cell by data-sx-col, not children[0] -- the leading select syscol column is
            // children[0] since DG-5.
            $searchNames = $browser->script(
                "return Array.from(document.querySelectorAll('{$win} table.sx-datagrid tbody tr'))"
                .".map(function (tr) { return (tr.querySelector('td[data-sx-col=\"name\"]').textContent || '').trim(); });"
            )[0];
            $this->assertContains('Camera 07', $searchNames, 'the global search did not narrow to the match');
            $this->assertLessThanOrEqual(3, count($searchNames), 'the search did not narrow the set');

            // Clear the search so the relation-sort step starts from the full set.
            $browser->script(
                "var i = document.querySelector('{$win} .sx-datagrid-search');"
                ."i.value = '';"
                ."i.dispatchEvent(new Event('input', { bubbles: true }));"
            );
            $browser->pause(700);

            // --- 3. Relation sort (the join proof). Click the site.name sortable header. The rows must
            // reorder by the joined site name without error -- proving the leftJoin round-tripped. Wait
            // for the header to read asc, then assert the visible site column is actually ordered. ---
            $siteHeader = "{$win} table.sx-datagrid thead th[data-sx-col=\"site.name\"]";
            $browser->assertPresent($siteHeader);
            // Dispatch the click on the <th> itself (not via a coordinate click, which can land on the
            // funnel button nested in the header and open the filter popover instead of sorting). The
            // th's own click listener emits 'sort'; the funnel's stopPropagation only guards ITS clicks.
            $browser->script(
                "document.querySelector('{$siteHeader}')"
                .".dispatchEvent(new MouseEvent('click', { bubbles: true }));"
            );
            $browser->pause(500)
                ->waitUntil("document.querySelector('{$siteHeader}').dataset.sxSort === 'asc'", 10);

            $sortDir = $browser->script(
                "return document.querySelector('{$siteHeader}').dataset.sxSort;"
            )[0];
            $this->assertSame('asc', $sortDir, 'the relation sort header did not settle on asc');
            $this->assertTrue(
                $this->siteColumnIsAscending($browser, $win),
                'the relation sort did not order the visible rows by site'
            );

            // --- 4. Reload restores everything. Apply a known filter (status=fault) + a search term, on
            // top of the relation sort, then refresh. After reload the funnel must still be .is-active,
            // the search box must still hold the term, and the rows must still be filtered + sorted --
            // read back from the DOM. The whole DG-2 view state lives in the durable user bag. ---
            $browser->click($statusFunnel)
                ->waitFor('.sx-datagrid-popover select', 10);
            $browser->script(
                "var s = document.querySelector('.sx-datagrid-popover select');"
                ."s.value = 'fault';"
                ."s.dispatchEvent(new Event('input', { bubbles: true }));"
                ."s.dispatchEvent(new Event('change', { bubbles: true }));"
            );
            $browser->waitFor("{$win} [data-sx-filter=\"status\"].is-active", 10);

            // Add a search term on top of the filter. 'Camera' matches all fault rows, keeping >=1.
            $browser->type("{$win} .sx-datagrid-search", 'Camera')
                ->pause(700);

            $browser->refresh()
                ->waitFor("{$win} table.sx-datagrid tbody tr", 15);

            // The funnel is still active after reload (durable filter).
            $browser->waitFor("{$win} [data-sx-filter=\"status\"].is-active", 10);
            $browser->assertPresent("{$win} [data-sx-filter=\"status\"].is-active");

            // The search box still holds the term (durable search).
            $persistedTerm = $browser->script(
                "return document.querySelector('{$win} .sx-datagrid-search').value;"
            )[0];
            $this->assertSame('Camera', $persistedTerm, 'the search term did not persist across reload');

            // The rows are still filtered to fault (durable filter applied to the re-query).
            $this->assertTrue(
                $this->everyRowStatusIs($browser, $win, 'fault'),
                'the fault filter did not persist across reload'
            );

            // The relation sort survived too.
            $persistedSort = $browser->script(
                "return document.querySelector('{$win} table.sx-datagrid thead th[data-sx-col=\"site.name\"]').dataset.sxSort;"
            )[0];
            $this->assertSame('asc', $persistedSort, 'the relation sort did not persist across reload');
        });
    }

    // The DG-3 end-to-end -- the headline of the whole slice: an OUT-OF-BAND model write (a server-side
    // Camera::create, no user action in the browser) reaches the open grid LIVE. The observer fires ->
    // debounced flush -> the flush re-queries + re-renders + broadcasts a `live` frame over the shared
    // Reverb -> the browser morphs the new row in and flashes it. What this proves that nothing else can:
    //   - a write with ZERO browser interaction repaints the open grid (the live loop is closed end to end)
    //   - the genuinely-new row carries .sx-datagrid-row-live (the broadcast frame flashed the insert)
    //   - a server-side UPDATE to a visible row morphs its cell live too
    //   - a reload shows the row is REAL data, not a client-side illusion
    //
    // The queue trick: the committed Dusk env is QUEUE_CONNECTION=database with NO worker, so a queued
    // FlushLiveGrids would sit in the jobs table forever. Force `sync` in THIS process so the observer's
    // dispatch runs the flush inline here -- same MySQL + same Reverb as the served app -- and the live
    // frame fires synchronously. On sync, ->delay() is dropped, so it's immediate.
    public function test_an_out_of_band_write_updates_the_open_grid_live_and_flashes_the_new_row(): void
    {
        // Run the flush inline in the test process (the Dusk env has no queue worker). This is the whole
        // trick: the observer's dispatch() then executes FlushLiveGrids here, reading the browser's open
        // window + bag from the shared DB and broadcasting to its channel over the shared Reverb.
        config(['queue.default' => 'sync']);

        // The runtime demo rows live in the SHARED dev/Dusk DB and the seeder never cleans up, so a
        // leftover "Camera LIVE-*" from a prior run would leak into the DG-1/DG-2 methods (it sorts
        // ABOVE "Camera 30" on name desc and would steal row 1). Purge any strays up front, and delete
        // the one we create in a finally, so this method leaves the demo set exactly as it found it.
        Camera::query()->where('name', 'like', 'Camera LIVE-%')->delete();

        $this->browse(function (Browser $browser): void {
            $this->loginAsDemoUser($browser)->waitFor('[data-window-id="hello"] .sx-window');

            // Open the Cameras grid via the launcher (same flow the DG-1/DG-2 methods use).
            $browser->waitFor('[data-sx-launcher]', 10)
                ->click('[data-sx-launcher]')
                ->pause(500)
                ->waitFor('[data-sx-launch="sxpro.demo"]', 10)
                ->click('[data-sx-launch="sxpro.demo"]')
                ->waitUntilMissing('[data-sx-launch]', 10);

            $browser->waitFor('.sx-window-surface[data-app="sxpro.demo"] .sx-window', 10);
            $windowId = $browser->script(
                "return document.querySelector('.sx-window-surface[data-app=\"sxpro.demo\"]').dataset.windowId;"
            )[0];
            $win = "[data-window-id=\"{$windowId}\"]";

            $browser->waitFor("{$win} table.sx-datagrid tbody tr", 10);

            // Deterministic visibility: the demo defaults to sortColumn=null, so a fresh insert (highest
            // PK) lands on the LAST page, invisible. Drive last_seen DESC first (newest at row 1); then an
            // insert with last_seen=now() lands at the top of page 1 where we can see it morph in.
            $lastSeenHeader = "{$win} table.sx-datagrid thead th[data-sx-col=\"last_seen\"]";
            $browser->assertPresent($lastSeenHeader);
            $clickLastSeen = "document.querySelector('{$lastSeenHeader}')"
                .".dispatchEvent(new MouseEvent('click', { bubbles: true }));";

            $browser->script($clickLastSeen);                  // asc
            $browser->pause(400)
                ->waitUsing(10, 100, fn () => $browser->script(
                    "return document.querySelector('{$lastSeenHeader}').dataset.sxSort === 'asc';"
                )[0]);
            $browser->script($clickLastSeen);                  // desc -> newest first
            $browser->pause(400)
                ->waitUsing(10, 100, fn () => $browser->script(
                    "return document.querySelector('{$lastSeenHeader}').dataset.sxSort === 'desc';"
                )[0]);

            // --- The out-of-band write. Create a Camera server-side with last_seen=now() (so it sorts to
            // row 1) and a distinctive unique name. This is the ONLY trigger -- no click, no type, nothing
            // in the browser. The Camera observer fires -> inline flush -> live broadcast to the window. ---
            $siteId = Site::query()->value('id');
            $liveName = 'Camera LIVE-'.now()->format('Hisv');
            $camera = Camera::create([
                'site_id' => $siteId,
                'name' => $liveName,
                'status' => 'active',
                'reads_today' => 1,
                'last_seen' => now(),
                'is_active' => true,
            ]);

            try {
                // The headline assertion: the new row appears with NO user action. The only path to it is
                // broadcast -> morph. Wait on the distinctive name showing up in the grid body. Address the
                // name cell by data-sx-col -- DG-5's leading select syscol column is now the first cell.
                $nameCell = "{$win} table.sx-datagrid tbody tr:first-child td[data-sx-col=\"name\"]";
                $browser->waitForTextIn($nameCell, $liveName, 10);
                $this->assertSame(
                    $liveName,
                    trim($browser->text($nameCell)),
                    'the out-of-band Camera did not appear live at row 1'
                );

                // It FLASHED: the reconciler stamps .sx-datagrid-row-live on a genuinely-new row (a key
                // with no stale match) ONLY on a broadcast (live) frame. Poll for the class on the new
                // row's <tr> (keyed by the model id) right after it arrives -- the one-shot animation may
                // linger the class briefly, so a short poll is enough and dodges any animation-timing race.
                $newRow = "{$win} table.sx-datagrid tbody tr[data-sx-key=\"{$camera->id}\"]";
                $browser->waitUsing(10, 100, fn () => $browser->script(
                    "var r = document.querySelector('{$newRow}');"
                    ."return !!r && r.classList.contains('sx-datagrid-row-live');"
                )[0]);
                $browser->assertPresent("{$newRow}.sx-datagrid-row-live");

                // --- Live UPDATE too. Flip a visible camera's status server-side; the open grid must
                // morph that cell without any browser action. Update the live row we just inserted (it's
                // at row 1, so easy to read back) from active -> fault and assert the badge changes live.
                $camera->update(['status' => 'fault']);
                $statusCell = "{$newRow} td[data-sx-col=\"status\"]";
                $browser->waitUsing(10, 100, fn () => str_contains(
                    strtolower($browser->text($statusCell)),
                    'fault'
                ));
                $this->assertStringContainsStringIgnoringCase(
                    'fault',
                    $browser->text($statusCell),
                    'the live status update did not morph the cell'
                );

                // --- Real data, not a client illusion. A full reload re-queries from the DB; the row is
                // still there (and still fault), proving the live frame reflected a genuine persisted
                // write. ---
                $browser->refresh()
                    ->waitFor("{$win} table.sx-datagrid tbody tr", 15)
                    ->waitForTextIn($nameCell, $liveName, 10);
                $this->assertSame(
                    $liveName,
                    trim($browser->text($nameCell)),
                    'the live row vanished on reload -- it was not persisted'
                );
            } finally {
                // Leave the shared demo set exactly as we found it -- the DG-1/DG-2 methods assume the
                // seeded 30 Cameras and nothing else. A leaked "Camera LIVE-*" sorts above "Camera 30".
                $camera->delete();
            }
        });
    }

    // The DG-4 end-to-end: column ergonomics. Every interaction the slice added -- resize, reorder (both
    // directions), show/hide, pin (sticky-left), reset -- driven in a real browser, EACH surviving a full
    // page reload (the arrangement is durable per user+window, server-authoritative). Plus the DG-3 live
    // loop laid on top of a rearranged/pinned layout: an out-of-band insert must morph into the CURRENT
    // arrangement, not the default one. What this proves that vitest/PHPUnit can't:
    //   - a resize drag on .sx-datagrid-resize-handle visibly widens the <col> and the width persists
    //   - a reorder drag past a neighbour to the RIGHT and (separately) to the LEFT both re-sequence the
    //     headers server-side (the proposeOrder from<to AND from>to branches, proven for real not by hand)
    //   - the Columns menu hides a column (gone from the grid) + re-shows it, with the fixed `name`
    //     column's visibility checkbox DISABLED (a fixed column can't be hidden)
    //   - pinning the fixed `name` column makes its <th> sticky-left: its bounding-box left stays ~fixed
    //     while the .sx-datagrid-scroll container is scrolled horizontally
    //   - Reset columns clears order/width/visibility/pins back to defaults
    //   - a live insert lands in the rearranged/pinned layout (cells under the right columns, pin held)
    public function test_the_datagrid_resizes_reorders_hides_pins_resets_and_persists_across_reload(): void
    {
        // The DG-3 live step below needs the observer flush to run inline (the Dusk env has no queue
        // worker), same trick the DG-3 method uses. Harmless for the pure-UI steps above it.
        config(['queue.default' => 'sync']);

        // The live insert we create sorts by name, so a leftover from a prior crashed run would skew the
        // arranged-layout assertions. Purge strays up front; we also delete ours in a finally.
        Camera::query()->where('name', 'like', 'Camera DG4-%')->delete();

        $this->browse(function (Browser $browser): void {
            $this->loginAsDemoUser($browser)->waitFor('[data-window-id="hello"] .sx-window');

            // Open the Cameras grid via the launcher (same flow the DG-1/2/3 methods use).
            $browser->waitFor('[data-sx-launcher]', 10)
                ->click('[data-sx-launcher]')
                ->pause(500)
                ->waitFor('[data-sx-launch="sxpro.demo"]', 10)
                ->click('[data-sx-launch="sxpro.demo"]')
                ->waitUntilMissing('[data-sx-launch]', 10);

            $browser->waitFor('.sx-window-surface[data-app="sxpro.demo"] .sx-window', 10);
            $windowId = $browser->script(
                "return document.querySelector('.sx-window-surface[data-app=\"sxpro.demo\"]').dataset.windowId;"
            )[0];
            $win = "[data-window-id=\"{$windowId}\"]";

            $browser->waitFor("{$win} table.sx-datagrid tbody tr", 10);

            // Reorder + pin-scroll below drop onto real header cells via document.elementFromPoint, so the
            // target <th> must be on-screen and UNOCCLUDED. The default desktop stacks hello/notes over the
            // launched grid. Close them (client-side close control) and maximise the Cameras window so the
            // whole header row is visible and nothing overlaps a drop point.
            $browser->click('[data-window-id="hello"] [data-sx-control="close"]')
                ->waitUntilMissing('[data-window-id="hello"]', 10);
            $browser->click('[data-window-id="notes"] [data-sx-control="close"]')
                ->waitUntilMissing('[data-window-id="notes"]', 10);
            $browser->click("{$win} [data-sx-control=\"maximise\"]");
            // Wait for the maximise to actually LAND (the surface flips data-sx-max) before anything
            // measures a column width -- under full-suite load the maximise transition can lag, and a
            // width read mid-transition is an unstable baseline that races the subsequent drag.
            $browser->waitUsing(10, 100, fn () => $browser->script(
                "return document.querySelector('.sx-window-surface[data-window-id=\"{$windowId}\"]')?.dataset.sxMax === 'true';"
            )[0]);
            $browser->pause(400);

            // Start from a KNOWN arrangement every run: reset first, so a leftover order/width/pin from a
            // prior aborted run can't skew the assertions below (the arrangement is durable and the Dusk
            // DB is shared/persistent, so it survives across runs). We finally-reset too.
            $this->resetColumns($browser, $win);

            // ---------------------------------------------------------------------------------------
            // 1. RESIZE. Drag the `status` column's resize handle far wider, then prove the NEW width both
            //    took effect and survived a reload (durable columnWidths). We assert against the resized
            //    value itself (read once the drag settles), not a pre-drag baseline: under table-layout:
            //    fixed a maximised window distributes slack, so an unresized column's rendered width is
            //    unstable across a re-render -- but an EXPLICITLY resized column holds its px, which is
            //    exactly what a durable width has to.
            // ---------------------------------------------------------------------------------------
            // Measure the rendered width off the header <th> (a <col> element's getBoundingClientRect is
            // unreliable across browsers -- it can report 0), which reflects the <col> width under the
            // fixed table layout.
            $statusColWidth = fn (): int => (int) $browser->script(
                "return Math.round(document.querySelector('{$win} table.sx-datagrid thead th[data-sx-col=\"status\"]').getBoundingClientRect().width);"
            )[0];
            $widthBefore = $statusColWidth();
            $this->assertGreaterThan(0, $widthBefore, 'the status column had no measurable width');

            // Drag the handle a large, unambiguous amount so the resized width is clearly beyond the
            // largest slack-distributed default any column could take at this window size.
            $this->dragHandle($browser, $win, 'status', 400);
            $browser->waitUsing(10, 100, fn () => $statusColWidth() >= 500);
            $widthAfter = $statusColWidth();
            $this->assertGreaterThanOrEqual(500, $widthAfter, 'the resize drag did not widen the status column');

            $browser->refresh()->waitFor("{$win} table.sx-datagrid tbody tr", 15);
            $browser->waitUsing(10, 100, fn () => abs($statusColWidth() - $widthAfter) <= 8);
            $widthReloaded = $statusColWidth();
            $this->assertEqualsWithDelta($widthAfter, $widthReloaded, 8, 'the resized width did not persist across reload');

            // ---------------------------------------------------------------------------------------
            // 2. REORDER, both directions. Default header order is name,status,reads_today,last_seen,
            //    site,is_active. First drag `status` RIGHT onto `last_seen` (from < to). Then drag it
            //    back LEFT onto `reads_today` (from > to) -- the branch Task 7 only hand-verified. Each
            //    re-sequences the headers server-side; the final order survives a reload.
            // ---------------------------------------------------------------------------------------
            $order = fn (): array => $browser->script(
                "return Array.from(document.querySelectorAll('{$win} table.sx-datagrid thead th[data-sx-col]')).map(function (th) { return th.dataset.sxCol; });"
            )[0];
            $this->assertSame(
                ['name', 'status', 'reads_today', 'last_seen', 'site.name', 'is_active'],
                $order(),
                'the header order was not the fresh default before reordering'
            );

            // Drag RIGHT: status past last_seen. status should land AFTER last_seen (from < to branch).
            $this->dragReorder($browser, $win, 'status', 'last_seen');
            $browser->waitUsing(10, 100, fn () => array_search('status', $order(), true) > array_search('last_seen', $order(), true));
            $afterRight = $order();
            $this->assertGreaterThan(
                array_search('last_seen', $afterRight, true),
                array_search('status', $afterRight, true),
                'dragging status right past last_seen did not move it after last_seen'
            );

            // Drag LEFT: status back onto reads_today. status should land BEFORE reads_today (from > to
            // branch -- the one Task 7 could only reason about; now proven in a real browser).
            $this->dragReorder($browser, $win, 'status', 'reads_today');
            $browser->waitUsing(10, 100, fn () => array_search('status', $order(), true) < array_search('reads_today', $order(), true));
            $afterLeft = $order();
            $this->assertLessThan(
                array_search('reads_today', $afterLeft, true),
                array_search('status', $afterLeft, true),
                'dragging status left onto reads_today did not move it before reads_today'
            );

            $browser->refresh()->waitFor("{$win} table.sx-datagrid tbody tr", 15);
            $this->assertSame($afterLeft, $order(), 'the reordered header sequence did not persist across reload');

            // ---------------------------------------------------------------------------------------
            // 3. SHOW/HIDE via the Columns menu. Uncheck `reads_today` -> its <th> vanishes; reload ->
            //    still gone; re-check -> it returns. And the fixed `name` column's checkbox is DISABLED.
            // ---------------------------------------------------------------------------------------
            $this->openColumnsMenu($browser, $win);

            // The fixed name column can never be hidden -> its visibility checkbox is disabled.
            $nameDisabled = $browser->script(
                "return document.querySelector('.sx-datagrid-colmenu input[data-sx-colvis=\"name\"]').disabled;"
            )[0];
            $this->assertTrue($nameDisabled, 'the fixed name column checkbox was not disabled');

            $this->toggleVisibility($browser, 'reads_today'); // uncheck -> hide
            $browser->waitUntilMissing("{$win} table.sx-datagrid thead th[data-sx-col=\"reads_today\"]", 10);

            // REGRESSION (menu survives its own morph): hiding a column emits columnVisibility, which
            // re-renders the grid. The Columns menu must NOT be torn out by that morph -- it mounts on
            // document.body, OUTSIDE the window content the core reconciler prunes trailing children
            // from. Before the fix the menu vanished mid-click ("it remains ticked but disappears").
            // Prove it stays: the menu is still open, and a SECOND hide works without reopening it.
            $browser->assertVisible('.sx-datagrid-colmenu');
            $this->toggleVisibility($browser, 'site.name'); // second hide in the SAME open menu
            $browser->waitUntilMissing("{$win} table.sx-datagrid thead th[data-sx-col=\"site.name\"]", 10);
            $browser->assertVisible('.sx-datagrid-colmenu'); // survived the second morph too
            $this->toggleVisibility($browser, 'site.name'); // re-show, restoring state for the rest
            $browser->waitFor("{$win} table.sx-datagrid thead th[data-sx-col=\"site.name\"]", 10);

            $this->closePopover($browser);
            $this->assertNotContains('reads_today', $order(), 'the hidden reads_today column is still in the header');

            // REGRESSION (stale-node on reopen): reopen the menu WITHOUT a reload. Its checkboxes must
            // reflect the CURRENT arrangement -- reads_today is hidden, so its box is UNCHECKED. Before
            // the fix the Columns button read the create-time node, so a hidden column reopened ticked
            // (the reported bug: untick -> close -> reopen -> ticked again).
            $this->openColumnsMenu($browser, $win);
            $readsChecked = $browser->script(
                "return document.querySelector('.sx-datagrid-colmenu input[data-sx-colvis=\"reads_today\"]').checked;"
            )[0];
            $this->assertFalse($readsChecked, 'reopening the menu showed reads_today ticked even though it is hidden');
            $this->closePopover($browser);

            $browser->refresh()->waitFor("{$win} table.sx-datagrid tbody tr", 15);
            $this->assertNotContains('reads_today', $order(), 'reads_today did not stay hidden across reload');

            // Re-show it.
            $this->openColumnsMenu($browser, $win);
            $this->toggleVisibility($browser, 'reads_today'); // re-check -> show
            $browser->waitFor("{$win} table.sx-datagrid thead th[data-sx-col=\"reads_today\"]", 10);
            $this->closePopover($browser);
            $this->assertContains('reads_today', $order(), 'reads_today did not return when re-checked');

            // ---------------------------------------------------------------------------------------
            // 4. PIN + horizontal scroll. Pin the FIXED `name` column (pin IS allowed on fixed). It must
            //    go sticky-left: its <th> bounding-box left stays ~constant while we scroll the
            //    .sx-datagrid-scroll container horizontally. Then reload -> still pinned + still sticky.
            // ---------------------------------------------------------------------------------------
            // The window is maximised so the six default columns fit with no overflow -- and with nothing
            // to scroll the sticky-left proof is vacuous. Force horizontal overflow first by dragging a
            // few resize handles very wide, so .sx-datagrid-scroll genuinely scrolls. (These widths get
            // wiped by the final Reset step.)
            foreach (['status', 'last_seen', 'site.name'] as $wideKey) {
                $this->dragHandle($browser, $win, $wideKey, 600);
                $browser->pause(150);
            }
            $browser->pause(300);

            $this->openColumnsMenu($browser, $win);
            $this->togglePin($browser, 'name');
            $browser->waitFor("{$win} table.sx-datagrid thead th[data-sx-col=\"name\"].sx-datagrid-pinned", 10);
            // The pin toggle must reflect the new state optimistically -- once pinned it reads "Unpin",
            // not a stale "Pin" (the menu is a static snapshot the morph cannot refresh).
            $pinLabel = $browser->script(
                "return document.querySelector('.sx-datagrid-colmenu [data-sx-colpin=\"name\"]').textContent;"
            )[0];
            $this->assertSame('Unpin', $pinLabel, 'the pin toggle still read "Pin" after pinning the column');
            $this->closePopover($browser);

            $this->assertTrue(
                $this->pinnedHeaderStaysPutOnScroll($browser, $win, 'name'),
                'the pinned name header did not stay sticky-left across a horizontal scroll'
            );

            // Reset the scroll before reload so the post-reload measure starts from a known left.
            $browser->script("document.querySelector('{$win} .sx-datagrid-scroll').scrollLeft = 0;");
            $browser->refresh()->waitFor("{$win} table.sx-datagrid tbody tr", 15);
            $browser->waitFor("{$win} table.sx-datagrid thead th[data-sx-col=\"name\"].sx-datagrid-pinned", 10);
            $this->assertTrue(
                $this->pinnedHeaderStaysPutOnScroll($browser, $win, 'name'),
                'the pin did not persist / stay sticky across reload'
            );

            // ---------------------------------------------------------------------------------------
            // 5. DG-3 LIVE INTERACTION on the rearranged/pinned layout. With name pinned and the columns
            //    reordered, an out-of-band Camera::create must morph the new row into the CURRENT layout:
            //    its cells land under the rearranged columns and the name cell stays pinned. Drive
            //    last_seen desc first so the new row (last_seen=now()) lands at row 1 where we can read it.
            // ---------------------------------------------------------------------------------------
            $lastSeenHeader = "{$win} table.sx-datagrid thead th[data-sx-col=\"last_seen\"]";
            $clickLastSeen = "document.querySelector('{$lastSeenHeader}').dispatchEvent(new MouseEvent('click', { bubbles: true }));";
            $browser->script($clickLastSeen); // asc
            $browser->pause(400)->waitUsing(10, 100, fn () => $browser->script(
                "return document.querySelector('{$lastSeenHeader}').dataset.sxSort === 'asc';"
            )[0]);
            $browser->script($clickLastSeen); // desc -> newest first
            $browser->pause(400)->waitUsing(10, 100, fn () => $browser->script(
                "return document.querySelector('{$lastSeenHeader}').dataset.sxSort === 'desc';"
            )[0]);

            $siteId = Site::query()->value('id');
            $liveName = 'Camera DG4-'.now()->format('Hisv');
            $camera = Camera::create([
                'site_id' => $siteId,
                'name' => $liveName,
                'status' => 'active',
                'reads_today' => 1,
                'last_seen' => now(),
                'is_active' => true,
            ]);

            try {
                // The new row appears live (broadcast -> morph), and its NAME cell (the pinned column)
                // holds the name -- i.e. cells map to the CURRENT arranged column order, and the pinned
                // name column carried its data through the live frame. Address the name cell by data-sx-col
                // (DG-5's leading select syscol column is now the row's first cell, not name).
                $newRow = "{$win} table.sx-datagrid tbody tr[data-sx-key=\"{$camera->id}\"]";
                $nameCell = "{$newRow} td[data-sx-col=\"name\"]";
                $browser->waitFor($newRow, 10);
                $browser->waitUsing(10, 100, fn () => trim($browser->text($nameCell)) === $liveName);
                $this->assertSame(
                    $liveName,
                    trim($browser->text($nameCell)),
                    'the live row did not land its name under the (pinned) name column'
                );

                // The new row's name cell is pinned too (the pin re-applied across the live-morphed row).
                $namePinned = $browser->script(
                    "var td = document.querySelector('{$newRow}').querySelector('td[data-sx-col=\"name\"]');"
                    ."return td.classList.contains('sx-datagrid-pinned');"
                )[0];
                $this->assertTrue($namePinned, 'the live row name cell did not inherit the pin');
            } finally {
                $camera->delete();
            }

            // ---------------------------------------------------------------------------------------
            // 6. RESET. Clear the whole arrangement back to defaults: order restored, the resized width
            //    gone, and the pin released. (We already proved persistence per step; reset is the
            //    escape hatch.) Also leaves the shared DB arrangement clean for the next method.
            // ---------------------------------------------------------------------------------------
            $this->resetColumns($browser, $win);
            $this->assertSame(
                ['name', 'status', 'reads_today', 'last_seen', 'site.name', 'is_active'],
                $order(),
                'Reset columns did not restore the default header order'
            );
            $browser->waitUntilMissing("{$win} table.sx-datagrid thead th[data-sx-col=\"name\"].sx-datagrid-pinned", 10);
            // The resized/widened status width is gone -- status is back well under the wide value it held
            // (it was dragged to >=500 then widened further to force overflow), so a clear drop below 400
            // proves the width override was cleared (its slack-distributed default is far narrower).
            $browser->waitUsing(10, 100, fn () => $statusColWidth() < 400);
            $resetWidth = $statusColWidth();
            $this->assertLessThan(400, $resetWidth, 'Reset columns did not clear the resized width');
        });
    }

    // The DG-5 end-to-end -- the slice's payoff: two-tier row selection, the toolbar->bulk-bar transform,
    // bulk + per-row actions, the body-mounted confirm dialog, and the persistence/reset rules, all driven
    // in a real browser. What this proves that vitest/PHPUnit can't:
    //   - ticking a page checkbox transforms the normal toolbar into the bulk bar with a live "N selected"
    //     count that climbs as more rows are ticked (client selection.mode swap, server-authoritative count)
    //   - the header checkbox selects the whole PAGE and, because the seed has MORE matching rows than one
    //     page (30 > perPage 25), reveals the "Select all N" banner; clicking it flips to all-matching mode
    //     and the count jumps to the total; Clear drops back to the normal toolbar (search box back)
    //   - a bulk Delete opens the body-mounted confirm dialog with the :count token interpolated against
    //     the effective selection; Cancel is a true no-op; OK deletes exactly the selected models server-
    //     side (row count drops) and clears the selection (normal toolbar back)
    //   - a row's "..." menu is body-mounted (NOT inside .sx-content, so the reconciler can't prune it),
    //     runs a single-row Delete through the confirm, and survives its own morph (reopen reflects the new
    //     state) -- the DG-4 body-mount/stale-node regressions, now proven for selection UI too
    //   - selection SURVIVES sort + page + a full reload (durable bag), and CLEARS the moment a filter or
    //     search re-scopes the set (an all-matching selection is meaningless once the query changes)
    public function test_the_datagrid_selects_bulk_acts_row_acts_confirms_and_persists_across_reload(): void
    {
        // The DG-5 handlers mutate the durable selection bag but never touch the queue, so no sync trick is
        // needed here -- unlike the DG-3/4 live steps. Bulk/row deletes run in the served app's request.

        // This method genuinely DELETES cameras (a bulk delete of 2 + a row delete of 1). The seeder only
        // reseeds an EMPTY table, so those rows would stay gone and break the DG-1/DG-2 methods, which
        // assume the exact seeded 30 (they assert "Camera 30"/"Camera 01" positions and full-page counts).
        // Snapshot every camera up front and, in a finally, re-insert any that went missing with their
        // original attributes -- leaving the shared demo set exactly as found, whichever rows got deleted.
        $snapshot = Camera::query()->get()->map(fn (Camera $c): array => $c->getAttributes())->all();

        try {
            $this->browse(function (Browser $browser): void {
                $this->loginAsDemoUser($browser)->waitFor('[data-window-id="hello"] .sx-window');

                // Open the Cameras grid via the launcher (same flow the DG-1..4 methods use).
                $browser->waitFor('[data-sx-launcher]', 10)
                    ->click('[data-sx-launcher]')
                    ->pause(500)
                    ->waitFor('[data-sx-launch="sxpro.demo"]', 10)
                    ->click('[data-sx-launch="sxpro.demo"]')
                    ->waitUntilMissing('[data-sx-launch]', 10);

                $browser->waitFor('.sx-window-surface[data-app="sxpro.demo"] .sx-window', 10);
                $windowId = $browser->script(
                    "return document.querySelector('.sx-window-surface[data-app=\"sxpro.demo\"]').dataset.windowId;"
                )[0];
                $win = "[data-window-id=\"{$windowId}\"]";

                $browser->waitFor("{$win} table.sx-datagrid tbody tr", 10);

                // The prior methods leave column arrangement dirty in the shared bag (DG-4 resets in a finally,
                // but a crashed run could leak). Selection tests don't care about column order, but a hidden
                // select syscol would break the checkbox reads -- so assert the select column is present up front.
                $browser->assertPresent("{$win} table.sx-datagrid thead th[data-sx-syscol=\"select\"] input");

                // ---------------------------------------------------------------------------------------
                // 1. SELECTION + BULK BAR. On a fresh grid the normal toolbar (with the search box) is shown.
                //    Tick one row checkbox -> the toolbar becomes the bulk bar showing "1 selected". Tick a
                //    second -> "2 selected". The count is server-authoritative (effectiveCount off the bag),
                //    so it can only be right if selectRow round-tripped and re-rendered.
                // ---------------------------------------------------------------------------------------
                $browser->assertPresent("{$win} .sx-datagrid-toolbar .sx-datagrid-search");
                $this->tickRow($browser, $win, 0);
                $browser->waitFor("{$win} .sx-datagrid-bulk .sx-datagrid-bulk-count", 10)
                    ->waitForTextIn("{$win} .sx-datagrid-bulk-count", '1 selected');
                $this->assertStringContainsString(
                    '1 selected',
                    $browser->text("{$win} .sx-datagrid-bulk-count"),
                    'ticking one row did not show "1 selected" in the bulk bar'
                );
                // The bulk bar replaced the normal toolbar -- the search box is gone while a selection is live.
                $browser->assertMissing("{$win} .sx-datagrid-toolbar .sx-datagrid-search");

                $this->tickRow($browser, $win, 1);
                $browser->waitForTextIn("{$win} .sx-datagrid-bulk-count", '2 selected');
                $this->assertStringContainsString('2 selected', $browser->text("{$win} .sx-datagrid-bulk-count"));

                // ---------------------------------------------------------------------------------------
                // 2. SELECT-ALL-MATCHING banner. Click the HEADER checkbox -> selects the whole page (25 rows).
                //    Because the seed has 30 matching cameras (> perPage 25), the "Select all 30" banner shows
                //    (pageAllSelected && totalMatching > pageCount). Click it -> all-matching mode, count jumps
                //    to the total ("All 30 selected"). Then Clear -> back to the normal toolbar (search box).
                // ---------------------------------------------------------------------------------------
                $browser->script(
                    "var h = document.querySelector('{$win} table.sx-datagrid thead th[data-sx-syscol=\"select\"] input');"
                    .'h.checked = true;'
                    ."h.dispatchEvent(new Event('change', { bubbles: true }));"
                );
                $browser->waitFor("{$win} .sx-datagrid-bulk-selectall", 10);
                $this->assertStringContainsString(
                    '30',
                    $browser->text("{$win} .sx-datagrid-bulk-selectall"),
                    'the "Select all N" banner did not show the total-matching count'
                );

                $browser->click("{$win} .sx-datagrid-bulk-selectall")
                    ->waitForTextIn("{$win} .sx-datagrid-bulk-count", 'All 30 selected');
                $this->assertStringContainsString(
                    'All 30 selected',
                    $browser->text("{$win} .sx-datagrid-bulk-count"),
                    'Select-all-matching did not flip the count to the whole matching set'
                );

                $browser->click("{$win} .sx-datagrid-bulk-clear")
                    ->waitFor("{$win} .sx-datagrid-toolbar .sx-datagrid-search", 10);
                $browser->assertMissing("{$win} .sx-datagrid-bulk-count");

                // ---------------------------------------------------------------------------------------
                // 3. BULK ACTION + CONFIRM. Select two rows, click the bulk Delete -> the body-mounted confirm
                //    dialog with the :count token interpolated ("Delete 2 cameras? ..."). Cancel first: nothing
                //    deleted, dialog gone. Do it again + OK: those two cameras are deleted server-side (the DB
                //    row count drops by 2) and the selection clears (normal toolbar back).
                // ---------------------------------------------------------------------------------------
                $rowCount = fn (): int => (int) $browser->script(
                    "return document.querySelectorAll('{$win} table.sx-datagrid tbody tr').length;"
                )[0];
                $totalBefore = Camera::query()->count();

                $this->tickRow($browser, $win, 0);
                $browser->waitForTextIn("{$win} .sx-datagrid-bulk-count", '1 selected');
                $this->tickRow($browser, $win, 1);
                $browser->waitForTextIn("{$win} .sx-datagrid-bulk-count", '2 selected');

                // Cancel branch: dialog appears with the interpolated count, cancelling deletes nothing.
                $browser->click("{$win} .sx-datagrid-bulk-action[data-sx-bulk=\"delete\"]")
                    ->waitFor('.sx-datagrid-confirm', 10);
                $this->assertStringContainsString(
                    '2',
                    $browser->text('.sx-datagrid-confirm .sx-datagrid-confirm-message'),
                    'the bulk confirm dialog did not interpolate the :count token'
                );
                $browser->click('.sx-datagrid-confirm .sx-datagrid-confirm-cancel')
                    ->waitUntilMissing('.sx-datagrid-confirm', 10);
                $this->assertSame($totalBefore, Camera::query()->count(), 'cancelling the bulk confirm still deleted rows');
                // Selection is untouched by a cancel -- still 2 selected.
                $browser->assertPresent("{$win} .sx-datagrid-bulk-count");

                // Capture the two selected names so we can assert they (and only they) are gone after OK.
                $selectedNames = $browser->script(
                    "return Array.from(document.querySelectorAll('{$win} table.sx-datagrid tbody tr'))"
                    .'.filter(function (tr) { var c = tr.querySelector(\'td[data-sx-syscol="select"] input\'); return c && c.checked; })'
                    .".map(function (tr) { return (tr.querySelector('td[data-sx-col=\"name\"]').textContent || '').trim(); });"
                )[0];
                $this->assertCount(2, $selectedNames, 'expected exactly two ticked rows before the bulk delete');

                // OK branch: the two selected cameras are deleted server-side and the selection clears.
                $browser->click("{$win} .sx-datagrid-bulk-action[data-sx-bulk=\"delete\"]")
                    ->waitFor('.sx-datagrid-confirm', 10)
                    ->click('.sx-datagrid-confirm .sx-datagrid-confirm-ok')
                    ->waitUntilMissing('.sx-datagrid-confirm', 10);

                // The DB dropped by exactly two, and the two named cameras are the ones gone.
                $browser->waitUsing(10, 100, fn (): bool => Camera::query()->count() === $totalBefore - 2);
                $this->assertSame($totalBefore - 2, Camera::query()->count(), 'the bulk delete did not remove exactly the selection');
                foreach ($selectedNames as $gone) {
                    $this->assertSame(0, Camera::query()->where('name', $gone)->count(), "bulk-deleted camera {$gone} still exists");
                }
                // Selection cleared -> the normal toolbar (search box) is back.
                $browser->waitFor("{$win} .sx-datagrid-toolbar .sx-datagrid-search", 10);
                $browser->assertMissing("{$win} .sx-datagrid-bulk-count");

                // ---------------------------------------------------------------------------------------
                // 4. ROW "..." ACTION + CONFIRM (body-mounted, survives its own morph). Open a row's "..." menu
                //    -> a popover mounted on document.body (NOT inside .sx-content, so a grid morph can't prune
                //    it -- the DG-4 body-mount regression, proven here for the row menu). Click its Delete ->
                //    confirm -> OK: that one camera is deleted. Then REOPEN a "..." menu with no reload: it
                //    reflects the CURRENT rows (the reopened menu is rebuilt from the live node, not a stale one).
                // ---------------------------------------------------------------------------------------
                $totalBeforeRow = Camera::query()->count();
                // Read the name of the first row so we can assert it's the one deleted.
                $firstRowName = trim($browser->text("{$win} table.sx-datagrid tbody tr:first-child td[data-sx-col=\"name\"]"));

                $browser->click("{$win} table.sx-datagrid tbody tr:first-child .sx-datagrid-rowactions-btn")
                    ->waitFor('.sx-datagrid-rowactions-menu', 10);

                // The menu is body-mounted, NOT inside the window's .sx-content (which the core reconciler
                // prunes trailing children from on a morph). This is the DG-4 lesson applied to the row menu.
                $mountedOnBody = $browser->script(
                    "var m = document.querySelector('.sx-datagrid-rowactions-menu');"
                    ."return !!m && m.closest('.sx-content') === null;"
                )[0];
                $this->assertTrue($mountedOnBody, 'the row "..." menu is mounted inside .sx-content (the reconciler will prune it)');

                $browser->click('.sx-datagrid-rowactions-menu .sx-datagrid-rowactions-item[data-sx-rowaction="delete"]')
                    ->waitFor('.sx-datagrid-confirm', 10)
                    ->click('.sx-datagrid-confirm .sx-datagrid-confirm-ok')
                    ->waitUntilMissing('.sx-datagrid-confirm', 10);

                $browser->waitUsing(10, 100, fn (): bool => Camera::query()->count() === $totalBeforeRow - 1);
                $this->assertSame($totalBeforeRow - 1, Camera::query()->count(), 'the row Delete did not remove exactly one camera');
                $this->assertSame(0, Camera::query()->where('name', $firstRowName)->count(), "row-deleted camera {$firstRowName} still exists");

                // Survives its own morph: reopen a "..." menu with NO reload. It opens fresh (rebuilt from the
                // live node), proving the row-actions button reads the CURRENT rows after the delete-morph, not
                // a stale create-time node -- the DG-4 stale-node regression, proven for the row menu.
                $browser->click("{$win} table.sx-datagrid tbody tr:first-child .sx-datagrid-rowactions-btn")
                    ->waitFor('.sx-datagrid-rowactions-menu', 10);
                $browser->assertPresent('.sx-datagrid-rowactions-menu .sx-datagrid-rowactions-item[data-sx-rowaction="delete"]');
                // Dismiss the menu (outside click) so it doesn't sit over the next step's measurements.
                $browser->script("document.body.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));");
                $browser->waitUntilMissing('.sx-datagrid-rowactions-menu', 5);

                // ---------------------------------------------------------------------------------------
                // 5. PERSISTENCE + RESET. Tick a row, then sort a column AND change page -> selection SURVIVES
                //    (same rows, just reordered/paged: the count persists). Reload -> selection SURVIVES
                //    (durable bag). Then apply a search -> selection CLEARS (a re-scope invalidates it).
                // ---------------------------------------------------------------------------------------
                $this->tickRow($browser, $win, 0);
                $browser->waitForTextIn("{$win} .sx-datagrid-bulk-count", '1 selected');

                // Sort by name (dispatch on the th itself, not a coordinate click that could hit the funnel).
                $nameHeader = "{$win} table.sx-datagrid thead th[data-sx-col=\"name\"]";
                $browser->script("document.querySelector('{$nameHeader}').dispatchEvent(new MouseEvent('click', { bubbles: true }));");
                $browser->pause(400)->waitUsing(10, 100, fn () => $browser->script(
                    "return document.querySelector('{$nameHeader}').dataset.sxSort === 'asc';"
                )[0]);
                // Selection survived the sort (same rows reordered).
                $browser->assertPresent("{$win} .sx-datagrid-bulk-count");

                // Change page -> next. Selection still survives (the count persists across the page change).
                $browser->click("{$win} table.sx-datagrid [data-sx-pager=\"next\"]")
                    ->pause(400);
                $browser->waitForTextIn("{$win} .sx-datagrid-bulk-count", '1 selected');
                $this->assertStringContainsString(
                    '1 selected',
                    $browser->text("{$win} .sx-datagrid-bulk-count"),
                    'the selection did not survive a sort + page change'
                );

                // Reload -> selection is durable (comes back from the user bag on rehydration).
                $browser->refresh()
                    ->waitFor("{$win} table.sx-datagrid tbody tr", 15)
                    ->waitFor("{$win} .sx-datagrid-bulk-count", 10);
                $this->assertStringContainsString(
                    '1 selected',
                    $browser->text("{$win} .sx-datagrid-bulk-count"),
                    'the selection did not persist across a full reload'
                );

                // Apply a FILTER -> selection CLEARS (re-scoping the set invalidates a selection). We drive the
                // status funnel, NOT the toolbar search box: while a selection is live the bulk bar REPLACES the
                // normal toolbar, so the search box isn't in the DOM -- but the column funnel lives in the header
                // and is reachable in either toolbar state. Applying it emits 'filter' -> resetSelection() ->
                // the bulk bar collapses back to the normal toolbar (search box returns).
                // Dispatch the funnel click via JS, not Dusk ->click(): the funnel is a small header button and
                // a coordinate click can be intercepted by an overlapping surface (the window isn't maximised
                // here). The button's own click listener opens the popover regardless of coordinates.
                $browser->script(
                    "document.querySelector('{$win} [data-sx-filter=\"status\"]').dispatchEvent(new MouseEvent('click', { bubbles: true }));"
                );
                $browser->waitFor('.sx-datagrid-popover select', 10);
                $browser->script(
                    "var s = document.querySelector('.sx-datagrid-popover select');"
                    ."s.value = 'fault';"
                    ."s.dispatchEvent(new Event('input', { bubbles: true }));"
                    ."s.dispatchEvent(new Event('change', { bubbles: true }));"
                );
                $browser->waitUntilMissing("{$win} .sx-datagrid-bulk-count", 10);
                $browser->assertPresent("{$win} .sx-datagrid-toolbar .sx-datagrid-search");
                $browser->assertMissing("{$win} .sx-datagrid-bulk-count");

                // Leave the durable view state clean for the other methods: clear the filter + reset the sort we
                // set, so a persisted status=fault / name-asc doesn't leak into the DG-1/DG-2 methods' fresh-
                // default assumptions. Clear filters wipes filter+search+page. The sort cycles asc->desc->none,
                // and step 5 left name on asc, so two more header clicks return it to the natural (unsorted)
                // default that DG-1 depends on.
                $browser->script("document.querySelector('{$win} .sx-datagrid-clear').dispatchEvent(new MouseEvent('click', { bubbles: true }));");
                $browser->pause(400);
                $resetSort = "document.querySelector('{$nameHeader}').dispatchEvent(new MouseEvent('click', { bubbles: true }));";
                $browser->script($resetSort); // asc -> desc
                $browser->pause(300)->waitUsing(10, 100, fn () => $browser->script(
                    "return document.querySelector('{$nameHeader}').dataset.sxSort === 'desc';"
                )[0]);
                $browser->script($resetSort); // desc -> none (natural order restored)
                $browser->pause(300)->waitUsing(10, 100, fn () => $browser->script(
                    "return document.querySelector('{$nameHeader}').dataset.sxSort === 'none';"
                )[0]);
            });
        } finally {
            // Restore the demo set to exactly what we found: re-insert any snapshotted camera whose id is
            // no longer present (the ones this method deleted), with its original attributes. Leaves the
            // shared DB untouched for the DG-1/DG-2 methods, which depend on the full seeded 30.
            $existing = Camera::query()->pluck('id')->all();
            $missing = array_filter($snapshot, fn (array $attrs): bool => ! in_array($attrs['id'], $existing, true));
            if ($missing !== []) {
                Camera::query()->insert(array_values($missing));
            }
        }
    }

    // The DG-6 end-to-end -- the slice's payoff and the close of DataGrid v1: double-click INLINE EDITING
    // (text, select, boolean), per-cell server validation with a revert-in-place, the focus guard that keeps
    // an open editor alive through a live morph, and the CSV export route -- all driven in a real browser.
    // What this proves that vitest/PHPUnit can't:
    //   - a double-click swaps the cell for an in-cell control, and committing (Enter) round-trips
    //     'cellEdit' -> server writeCell -> a durable re-query, proven by the value SURVIVING a full reload
    //     (not just the optimistic paint): once for a text column, once for the in-cell <select>, once for
    //     the boolean checkbox
    //   - an INVALID edit (name cleared, violates `required`) is rejected server-side: the cell REVERTS to
    //     its stored value, flags .sx-datagrid-cell-error with the message, and the DB is untouched
    //   - a cell with an OPEN editor survives an out-of-band live broadcast morph (the data-sx-editing focus
    //     guard) -- the editor stays present AND focused while a DIFFERENT row morphs in beside it
    //   - the toolbar Export button is present and its streaming CSV route returns 200 text/csv with a real
    //     header row + our rows, fetched on the live authenticated session (T7's feature tests already prove
    //     the CSV body / scope / auth-isolation, so here we prove END-TO-END reachability only)
    public function test_the_datagrid_edits_cells_validates_stays_live_safe_and_exports(): void
    {
        // The live-safe step (5) fires an out-of-band write whose observer flush must run inline (the Dusk
        // env has no queue worker) -- the DG-3 trick. Harmless for the plain edit round-trips above it.
        config(['queue.default' => 'sync']);

        // Our rows live in the SHARED Dusk DB and the seeder never cleans up, so purge any strays from a
        // crashed prior run up front and delete ours in a finally -- leaving the seeded 30 exactly as found.
        Camera::query()->where('name', 'like', 'Camera DG6-%')->delete();

        // Two OWN rows carrying the newest last_seen, so a last_seen-desc sort floats them onto page 1 where
        // we address them by key (data-sx-key), never positionally. A (the row we edit) is a hair newer than
        // B (the out-of-band target for the live-safe step) so their order is deterministic.
        $siteId = Site::query()->value('id');
        $a = Camera::create([
            'site_id' => $siteId, 'name' => 'Camera DG6-A', 'status' => 'active',
            'reads_today' => 5, 'last_seen' => now(), 'is_active' => true,
        ]);
        $b = Camera::create([
            'site_id' => $siteId, 'name' => 'Camera DG6-B', 'status' => 'active',
            'reads_today' => 9, 'last_seen' => now()->subSecond(), 'is_active' => true,
        ]);

        try {
            $this->browse(function (Browser $browser) use ($a, $b): void {
                $this->loginAsDemoUser($browser)->waitFor('[data-window-id="hello"] .sx-window');

                // Open the Cameras grid via the launcher (same flow the DG-1..5 methods use).
                $browser->waitFor('[data-sx-launcher]', 10)
                    ->click('[data-sx-launcher]')
                    ->pause(500)
                    ->waitFor('[data-sx-launch="sxpro.demo"]', 10)
                    ->click('[data-sx-launch="sxpro.demo"]')
                    ->waitUntilMissing('[data-sx-launch]', 10);

                $browser->waitFor('.sx-window-surface[data-app="sxpro.demo"] .sx-window', 10);
                $windowId = $browser->script(
                    "return document.querySelector('.sx-window-surface[data-app=\"sxpro.demo\"]').dataset.windowId;"
                )[0];
                $win = "[data-window-id=\"{$windowId}\"]";

                $browser->waitFor("{$win} table.sx-datagrid tbody tr", 10);

                // Sort last_seen DESC so our two freshest rows (A, then B) sit at the top of page 1.
                $lastSeenHeader = "{$win} table.sx-datagrid thead th[data-sx-col=\"last_seen\"]";
                $clickLastSeen = "document.querySelector('{$lastSeenHeader}').dispatchEvent(new MouseEvent('click', { bubbles: true }));";
                $browser->script($clickLastSeen); // asc
                $browser->pause(400)->waitUsing(10, 100, fn () => $browser->script(
                    "return document.querySelector('{$lastSeenHeader}').dataset.sxSort === 'asc';"
                )[0]);
                $browser->script($clickLastSeen); // desc -> newest first
                $browser->pause(400)->waitUsing(10, 100, fn () => $browser->script(
                    "return document.querySelector('{$lastSeenHeader}').dataset.sxSort === 'desc';"
                )[0]);

                $rowA = "{$win} table.sx-datagrid tbody tr[data-sx-key=\"{$a->id}\"]";
                $rowB = "{$win} table.sx-datagrid tbody tr[data-sx-key=\"{$b->id}\"]";
                $browser->waitFor($rowA, 10)->waitFor($rowB, 10);

                // ---------------------------------------------------------------------------------------
                // 1. TEXT EDIT persists across reload. Double-click the name cell -> an in-cell input; type
                //    a new value + Enter -> optimistic paint AND a server writeCell. Prove the SERVER write
                //    (DB re-query), then reload and assert the new value is STILL there (durable, not paint).
                // ---------------------------------------------------------------------------------------
                $nameCell = "{$rowA} td[data-sx-col=\"name\"]";
                $this->openEditorOn($browser, $nameCell);
                $this->commitEditorValue($browser, $nameCell, 'Camera DG6-A edited');
                $browser->waitUsing(10, 100, fn () => trim($browser->text($nameCell)) === 'Camera DG6-A edited');
                // The write actually reached the DB (not just an optimistic paint).
                $browser->waitUsing(10, 100, fn () => Camera::query()->whereKey($a->id)->value('name') === 'Camera DG6-A edited');

                $browser->refresh()->waitFor($rowA, 15);
                $this->assertSame(
                    'Camera DG6-A edited',
                    trim($browser->text($nameCell)),
                    'the text edit did not survive a full reload (durable write)'
                );

                // ---------------------------------------------------------------------------------------
                // 2. SELECT EDIT persists. Double-click the status cell -> an in-cell <select>; pick a
                //    different option + Enter -> writeCell. The badge re-renders to the new value; reload ->
                //    still there.
                // ---------------------------------------------------------------------------------------
                $statusCell = "{$rowA} td[data-sx-col=\"status\"]";
                $this->openEditorOn($browser, $statusCell, 'select.sx-datagrid-editor');
                $browser->script(
                    "var s = document.querySelector('{$statusCell} select.sx-datagrid-editor');"
                    ."s.value = 'offline';"
                    ."s.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));"
                );
                $browser->waitUsing(10, 100, fn () => str_contains(strtolower($browser->text($statusCell)), 'offline'));
                $browser->waitUsing(10, 100, fn () => Camera::query()->whereKey($a->id)->value('status') === 'offline');

                $browser->refresh()->waitFor($rowA, 15);
                $this->assertStringContainsStringIgnoringCase(
                    'offline',
                    $browser->text($statusCell),
                    'the select edit did not survive a full reload'
                );

                // ---------------------------------------------------------------------------------------
                // 3. BOOLEAN EDIT persists. Double-click the is_active cell -> an in-cell checkbox; toggle it
                //    off (A was created active) + Enter -> writeCell. Prove the DB flipped, reload -> still
                //    false, and the cell paints the false glyph.
                // ---------------------------------------------------------------------------------------
                $activeCell = "{$rowA} td[data-sx-col=\"is_active\"]";
                $this->openEditorOn($browser, $activeCell, 'input[type="checkbox"].sx-datagrid-editor');
                $browser->script(
                    "var c = document.querySelector('{$activeCell} input[type=\"checkbox\"].sx-datagrid-editor');"
                    .'c.checked = false;'
                    ."c.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));"
                );
                $browser->waitUsing(10, 100, fn () => (int) Camera::query()->whereKey($a->id)->value('is_active') === 0);

                $browser->refresh()->waitFor($rowA, 15);
                $this->assertSame(
                    0,
                    (int) Camera::query()->whereKey($a->id)->value('is_active'),
                    'the boolean edit did not survive a full reload'
                );
                $browser->waitUsing(10, 100, fn () => str_contains($browser->text($activeCell), '✗'));

                // ---------------------------------------------------------------------------------------
                // 4. INVALID edit reverts + errors, DB unchanged. Double-click the name cell, clear it to
                //    empty (violates `required`) + Enter. The server rejects -> the cell REVERTS to the
                //    stored value, gets flagged .sx-datagrid-cell-error with the message, the editor reopens
                //    seeded with the stored value, and the DB never changed.
                // ---------------------------------------------------------------------------------------
                $this->openEditorOn($browser, $nameCell);
                $browser->script(
                    "var i = document.querySelector('{$nameCell} .sx-datagrid-editor');"
                    ."i.value = '';"
                    ."i.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));"
                );
                // The reject frame flags the cell in error and reopens the editor on the authoritative value.
                $browser->waitFor("{$nameCell}.sx-datagrid-cell-error", 10);
                $browser->assertPresent("{$nameCell} .sx-datagrid-cell-error-msg");
                $reverted = $browser->script(
                    "return document.querySelector('{$nameCell} .sx-datagrid-editor').value;"
                )[0];
                $this->assertSame(
                    'Camera DG6-A edited',
                    $reverted,
                    'the rejected edit did not revert the cell to its stored value'
                );
                // The DB is untouched by the invalid edit.
                $this->assertSame(
                    'Camera DG6-A edited',
                    Camera::query()->whereKey($a->id)->value('name'),
                    'the rejected edit still wrote to the DB'
                );
                // Escape the error editor -> the cell restores to a clean painted state for the next step.
                $browser->script(
                    "document.querySelector('{$nameCell} .sx-datagrid-editor')"
                    .".dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));"
                );
                $browser->waitUsing(10, 100, fn () => trim($browser->text($nameCell)) === 'Camera DG6-A edited');

                // ---------------------------------------------------------------------------------------
                // 5. LIVE-SAFE. Open an editor on A's name cell, then fire an out-of-band write to a DIFFERENT
                //    visible row (B) -- the observer flush runs inline (queue=sync) and a live broadcast frame
                //    morphs B's status cell over Reverb. A's open editor MUST survive it: the data-sx-editing
                //    focus guard keeps the control present AND focused through the morph.
                // ---------------------------------------------------------------------------------------
                $this->openEditorOn($browser, $nameCell);
                $b->update(['status' => 'fault']);
                $statusCellB = "{$rowB} td[data-sx-col=\"status\"]";
                // The live frame landed (B's status morphed with no browser action)...
                $browser->waitUsing(10, 100, fn () => str_contains(strtolower($browser->text($statusCellB)), 'fault'));
                // ...and A's editor survived the morph -- still in the DOM and still the focused element.
                $stillEditing = $browser->script(
                    "var i = document.querySelector('{$nameCell} .sx-datagrid-editor');"
                    .'return !!i && document.activeElement === i;'
                )[0];
                $this->assertTrue(
                    $stillEditing,
                    'the live morph destroyed or blurred the open editor (the focus guard failed)'
                );
                // Tidy: cancel the open editor before the export step.
                $browser->script(
                    "document.querySelector('{$nameCell} .sx-datagrid-editor')"
                    .".dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));"
                );

                // ---------------------------------------------------------------------------------------
                // 6. EXPORT reachable end-to-end. The toolbar Export button is present, and its streaming CSV
                //    route returns 200 text/csv with a header row + our rows -- fetched on the live
                //    authenticated session. (T7's feature tests already prove the CSV body / scope / auth.)
                // ---------------------------------------------------------------------------------------
                $browser->assertPresent("{$win} .sx-datagrid-export-btn");
                $exportUrl = "/system-x/pro-datagrid/sxpro.demo/{$windowId}/export?scope=view";
                $browser->script(
                    'window.__dg6export = null;'
                    ."fetch('{$exportUrl}', { credentials: 'same-origin' }).then(function (r) {"
                    .'  return r.text().then(function (t) {'
                    ."    window.__dg6export = { status: r.status, ct: r.headers.get('content-type') || '', body: t };"
                    .'  });'
                    ."}).catch(function (e) { window.__dg6export = { status: -1, ct: '', body: String(e) }; });"
                );
                $browser->waitUsing(10, 100, fn () => $browser->script('return window.__dg6export !== null;')[0]);
                $export = $browser->script('return window.__dg6export;')[0];
                $this->assertSame(200, (int) $export['status'], 'the export route did not return 200');
                $this->assertStringContainsString('text/csv', $export['ct'], 'the export response was not text/csv');
                $firstLine = strtok((string) $export['body'], "\n");
                $this->assertStringContainsString(',', (string) $firstLine, 'the export CSV had no comma-separated header row');
                $this->assertStringContainsString('Name', (string) $firstLine, 'the export CSV header is missing the Name column');
                $this->assertStringContainsString('Camera DG6-A', (string) $export['body'], 'the export CSV did not stream our rows');

                // Leave the durable view state clean for the other methods: our last_seen sort would poison
                // DG-1/DG-2's natural-order assumption, so cycle it back to none (desc -> none).
                $browser->script($clickLastSeen);
                $browser->pause(300)->waitUsing(10, 100, fn () => $browser->script(
                    "return document.querySelector('{$lastSeenHeader}').dataset.sxSort === 'none';"
                )[0]);
            });
        } finally {
            // Leave the shared demo set exactly as found -- our two DG6 rows go, whichever edits they carry.
            Camera::query()->whereKey([$a->id, $b->id])->delete();
        }
    }

    // The grouped multiselect proof: open the site.name camera-picker funnel, tick across the two
    // sections, and prove (a) the grid narrows on a debounced auto-apply and (b) the popover SURVIVES
    // the mid-selection re-render. site.name's funnel is not driven by any other beat. Clears at the end.
    public function test_grouped_multiselect_filter_narrows_and_the_popover_survives(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAsDemoUser($browser)->waitFor('[data-window-id="hello"] .sx-window');

            // Open the Cameras grid via the launcher (same flow the DG-1/DG-2 methods use).
            $browser->waitFor('[data-sx-launcher]', 10)
                ->click('[data-sx-launcher]')
                ->pause(500)
                ->waitFor('[data-sx-launch="sxpro.demo"]', 10)
                ->click('[data-sx-launch="sxpro.demo"]')
                ->waitUntilMissing('[data-sx-launch]', 10);

            $browser->waitFor('.sx-window-surface[data-app="sxpro.demo"] .sx-window', 10);
            $windowId = $browser->script(
                "return document.querySelector('.sx-window-surface[data-app=\"sxpro.demo\"]').dataset.windowId;"
            )[0];
            $win = "[data-window-id=\"{$windowId}\"]";

            $browser->waitFor("{$win} table.sx-datagrid tbody tr", 10);

            $unfilteredRows = (int) $browser->script(
                "return document.querySelectorAll('{$win} table.sx-datagrid tbody tr').length;"
            )[0];
            $this->assertGreaterThan(0, $unfilteredRows, 'expected rows before filtering');

            $siteFunnel = "{$win} [data-sx-filter=\"site.name\"]";
            $browser->click($siteFunnel)
                ->waitFor('.sx-datagrid-filter-multiselect', 10);

            // Tick the first "By Site" checkbox -> debounced auto-apply (300ms) -> grid re-renders.
            $browser->click('.sx-datagrid-filter-multiselect-body .sx-datagrid-filter-multiselect-section:first-child input[type="checkbox"]')
                ->pause(600);

            // Funnel active AND popover STILL open (survives the morph -- the premise).
            $browser->waitFor("{$siteFunnel}.is-active", 10)
                ->assertVisible('.sx-datagrid-filter-multiselect');

            // The camera-picker narrowed the grid to one site's cameras -- fewer rows than unfiltered.
            $narrowedRows = (int) $browser->script(
                "return document.querySelectorAll('{$win} table.sx-datagrid tbody tr').length;"
            )[0];
            $this->assertGreaterThan(0, $narrowedRows, 'the multiselect filter emptied the grid');
            $this->assertLessThan($unfilteredRows, $narrowedRows, 'the multiselect filter did not shrink the grid');

            // Tick a "By Camera" checkbox in the SAME open popover.
            $browser->click('.sx-datagrid-filter-multiselect-body .sx-datagrid-filter-multiselect-section:last-child input[type="checkbox"]')
                ->pause(600)
                ->assertPresent("{$siteFunnel}.is-active");

            // Cleanup: untick both boxes inside the still-open popover to clear the filter. Clicking the
            // toolbar Clear-filters button would be intercepted -- the tall funnel popover overlays the
            // toolbar. Emptying the selection unsets the filter (handleFilter's === [] path), so the
            // funnel de-activates and nothing leaks into sibling beats.
            $browser->click('.sx-datagrid-filter-multiselect-body .sx-datagrid-filter-multiselect-section:first-child input[type="checkbox"]')
                ->click('.sx-datagrid-filter-multiselect-body .sx-datagrid-filter-multiselect-section:last-child input[type="checkbox"]')
                ->pause(600)
                ->waitUntilMissing("{$siteFunnel}.is-active", 10);
        });
    }

    // Open the in-cell editor on a data cell by dispatching a delegated dblclick on it, then wait for the
    // per-type control (default the generic .sx-datagrid-editor; pass a more specific selector for the
    // select / checkbox). Used only by the DG-6 method.
    private function openEditorOn(Browser $browser, string $cellSelector, string $control = '.sx-datagrid-editor'): void
    {
        $browser->script(
            "document.querySelector('{$cellSelector}').dispatchEvent(new MouseEvent('dblclick', { bubbles: true }));"
        );
        $browser->waitFor("{$cellSelector} {$control}", 10);
    }

    // Set the editor input's value and commit it (Enter), driving the value + keydown directly so the
    // renderer's own keydown listener fires. json_encode keeps arbitrary strings safe inside the script.
    private function commitEditorValue(Browser $browser, string $cellSelector, string $value): void
    {
        $js = json_encode($value);
        $browser->script(
            "var i = document.querySelector('{$cellSelector} .sx-datagrid-editor');"
            ."i.value = {$js};"
            ."i.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));"
        );
    }

    // Tick (or untick) the row-select checkbox on the Nth (0-based) visible body row, driving the change
    // event directly so the renderer's listener fires -- Dusk's ->check can miss the JS handler. Used only
    // by the DG-5 method.
    private function tickRow(Browser $browser, string $win, int $index): void
    {
        $browser->script(
            "var rows = document.querySelectorAll('{$win} table.sx-datagrid tbody tr');"
            ."var c = rows[{$index}] && rows[{$index}].querySelector('td[data-sx-syscol=\"select\"] input');"
            .'if (c) { c.checked = !c.checked; '
            ."c.dispatchEvent(new Event('change', { bubbles: true })); }"
        );
    }

    // Reset the whole column arrangement to defaults via the Columns menu's "Reset columns" action, then
    // wait for the default header order to settle. Used at the top (known start) and the bottom (leave
    // the shared DB clean) of the DG-4 method.
    private function resetColumns(Browser $browser, string $win): void
    {
        $this->openColumnsMenu($browser, $win);
        $browser->script("document.querySelector('.sx-datagrid-colmenu [data-sx-colreset]').click();");
        // Wait for the default order AND no pinned column to settle -- reset clears order/hidden/pinned/
        // widths server-side, and a prior aborted run can leak any of those into the shared DB. Order +
        // no-pins are the reliable, layout-independent settle signals. (Width is NOT waited on here: the
        // window is maximised and the table uses table-layout:fixed, so with the declared widths summing
        // under the table width the browser distributes the slack -- a "default" column renders far wider
        // than its declared px. So every width check in this method is RELATIVE to a freshly-reset
        // baseline, never an absolute px, and this reset is what makes that baseline clean.)
        $browser->waitUsing(10, 100, fn () => $browser->script(
            "var order = Array.from(document.querySelectorAll('{$win} table.sx-datagrid thead th[data-sx-col]')).map(function (th) { return th.dataset.sxCol; }).join(',');"
            ."var pinned = document.querySelectorAll('{$win} table.sx-datagrid thead th.sx-datagrid-pinned').length;"
            ."return order === 'name,status,reads_today,last_seen,site.name,is_active' && pinned === 0;"
        )[0]);
        // Settle: let the reset re-render's width re-apply land before the caller reads a baseline.
        $browser->pause(300);
        $this->closePopover($browser);
    }

    // Open the Columns menu (the .sx-datagrid-colmenu popover) from the toolbar Columns button.
    private function openColumnsMenu(Browser $browser, string $win): void
    {
        $browser->click("{$win} .sx-datagrid-columns-btn")
            ->waitFor('.sx-datagrid-colmenu', 10);
    }

    // Close whatever popover is open (the columns menu / a funnel) by clicking outside it, so a later
    // header measurement isn't sitting under the overlay. openPopover closes on an outside pointerdown.
    private function closePopover(Browser $browser): void
    {
        $browser->script(
            "document.body.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true }));"
            ."document.body.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));"
        );
        $browser->waitUntilMissing('.sx-datagrid-colmenu', 5);
    }

    // Toggle a column's visibility checkbox in the open Columns menu (fires the change the renderer
    // listens for; Dusk's ->check can miss the JS listener, so drive it directly).
    private function toggleVisibility(Browser $browser, string $key): void
    {
        $browser->script(
            "var c = document.querySelector('.sx-datagrid-colmenu input[data-sx-colvis=\"{$key}\"]');"
            .'c.checked = !c.checked;'
            ."c.dispatchEvent(new Event('change', { bubbles: true }));"
        );
    }

    // Toggle a column's pin in the open Columns menu.
    private function togglePin(Browser $browser, string $key): void
    {
        $browser->script(
            "document.querySelector('.sx-datagrid-colmenu [data-sx-colpin=\"{$key}\"]').click();"
        );
    }

    // Drive the resize handle for $key by $dx pixels via synthetic pointer events. The handle wires
    // pointerdown on itself then pointermove/pointerup on document (the pointer leaves the thin sliver
    // fast), so we dispatch pointerdown on the handle and move/up on document with the shifted clientX.
    private function dragHandle(Browser $browser, string $win, string $key, int $dx): void
    {
        $browser->script(
            "var h = document.querySelector('{$win} .sx-datagrid-resize-handle[data-sx-resize=\"{$key}\"]');"
            .'var r = h.getBoundingClientRect();'
            .'var x = r.left + r.width / 2, y = r.top + r.height / 2;'
            ."h.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, clientX: x, clientY: y }));"
            ."document.dispatchEvent(new PointerEvent('pointermove', { bubbles: true, clientX: x + {$dx}, clientY: y }));"
            ."document.dispatchEvent(new PointerEvent('pointerup', { bubbles: true, clientX: x + {$dx}, clientY: y }));"
        );
    }

    // Drive a reorder drag: grab $fromKey's grip and drop it over $toKey's header. The reorder resolves
    // the drop target via document.elementFromPoint(clientX, clientY), so pointerup MUST carry the real
    // screen coords of the target <th>'s centre -- a synthetic event with no coords lands nowhere and the
    // drop is a no-op. So we read the target th's rect and fire pointerup at its centre.
    private function dragReorder(Browser $browser, string $win, string $fromKey, string $toKey): void
    {
        $browser->script(
            "var grip = document.querySelector('{$win} .sx-datagrid-grip[data-sx-reorder=\"{$fromKey}\"]');"
            ."var target = document.querySelector('{$win} table.sx-datagrid thead th[data-sx-col=\"{$toKey}\"]');"
            .'var gr = grip.getBoundingClientRect();'
            .'var tr = target.getBoundingClientRect();'
            .'var tx = tr.left + tr.width / 2, ty = tr.top + tr.height / 2;'
            ."grip.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, clientX: gr.left + gr.width / 2, clientY: gr.top + gr.height / 2 }));"
            ."document.dispatchEvent(new PointerEvent('pointermove', { bubbles: true, clientX: tx, clientY: ty }));"
            ."document.dispatchEvent(new PointerEvent('pointerup', { bubbles: true, clientX: tx, clientY: ty }));"
        );
    }

    // True when the pinned header cell for $key keeps a ~constant viewport left as we scroll the
    // .sx-datagrid-scroll container horizontally -- the sticky-left proof. A non-pinned <th> would slide
    // left by the scroll amount; a sticky one holds. Allow a small tolerance for sub-pixel drift.
    private function pinnedHeaderStaysPutOnScroll(Browser $browser, string $win, string $key): bool
    {
        $th = "{$win} table.sx-datagrid thead th[data-sx-col=\"{$key}\"]";
        $leftBefore = (float) $browser->script(
            "return document.querySelector('{$th}').getBoundingClientRect().left;"
        )[0];

        // Scroll the container right by a healthy amount (guard: only meaningful if it can scroll).
        $browser->script(
            "var s = document.querySelector('{$win} .sx-datagrid-scroll');"
            .'s.scrollLeft = Math.max(150, Math.floor(s.scrollWidth - s.clientWidth));'
            ."s.dispatchEvent(new Event('scroll', { bubbles: true }));"
        );
        $browser->pause(200);

        $scrolled = (float) $browser->script(
            "return document.querySelector('{$win} .sx-datagrid-scroll').scrollLeft;"
        )[0];
        // If the container can't scroll horizontally at all, the assertion is vacuous -- fail loudly so
        // we don't get a false green (widen a column first to force overflow if this ever trips).
        if ($scrolled < 20) {
            return false;
        }

        $leftAfter = (float) $browser->script(
            "return document.querySelector('{$th}').getBoundingClientRect().left;"
        )[0];

        return abs($leftAfter - $leftBefore) <= 2.0;
    }

    // True when every visible body row's status cell (the badge) reads $status. Read straight off the DOM
    // by data-sx-col (not a positional child -- DG-5's leading select syscol column shifted the indices)
    // so the assertion reflects what actually painted after the server re-query.
    private function everyRowStatusIs(Browser $browser, string $win, string $status): bool
    {
        $statuses = $browser->script(
            "return Array.from(document.querySelectorAll('{$win} table.sx-datagrid tbody tr'))"
            .".map(function (tr) { return (tr.querySelector('td[data-sx-col=\"status\"]').textContent || '').trim().toLowerCase(); });"
        )[0];

        return count($statuses) > 0 && ! in_array(false, array_map(
            fn ($s): bool => str_contains($s, $status),
            $statuses
        ), true);
    }

    // True when the visible site column is in non-decreasing order -- the leftJoin sort proof. Address the
    // cell by data-sx-col (not a positional child -- DG-5's leading select syscol column shifted indices).
    private function siteColumnIsAscending(Browser $browser, string $win): bool
    {
        $sites = $browser->script(
            "return Array.from(document.querySelectorAll('{$win} table.sx-datagrid tbody tr'))"
            .".map(function (tr) { return (tr.querySelector('td[data-sx-col=\"site.name\"]').textContent || '').trim(); });"
        )[0];

        $sorted = $sites;
        sort($sorted, SORT_STRING);

        return $sites === $sorted;
    }
}
