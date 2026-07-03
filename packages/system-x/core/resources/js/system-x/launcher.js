// The launcher overlay (Plan 5b, D5) -- the start-menu app grid behind the panel's
// system-x button. Ported from the design mockup's blurred command palette
// (ui_kits/desktop/index.html:367-380): a full-screen backdrop, a centred search box, and
// a grid of AppIcon tiles (a glyph + a label, the design AppIcon shape). Picking a tile
// calls back to openApp(slug) (the EXISTING focus-if-open-else-launch path,
// display-server.js) and closes the overlay -- the launcher is just a nicer trigger over
// the same 5a open mechanism, NOT a new launch path. It REPLACES the temporary open stub.
//
// Mount: the display server appends this.el to document.body (B4), NOT inside #sx-desktop
// (which is overflow:hidden and carries the WM's mount-scoped pointerdown/click listeners --
// the overlay would clip, and a backdrop mousedown in the mount could be misread as a
// desktop blur). The overlay carries z 100002 (S6) so it sits ABOVE the panel (100000) and
// the reconnect badge (100001); its backdrop covers the whole desktop INCLUDING the panel
// button that opened it (you can't click the panel underneath while the launcher is up).
import { icon } from './icons.js';
import { saveLayout } from './transport.js';
import { ContextMenu } from './context-menu.js';
import { makeReorderable } from './tile-reorder.js';

export class Launcher {
    // apps   -> [{slug, title, icon, system}, ...] the registered apps' metadata (D2, the boot
    //           blob's `apps` grid). One tile per USER app.
    // onPick(slug) -> a tile was picked: the display server runs openApp(slug) (focus-if-open-
    //           else-launch). The launcher closes itself after.
    // layout -> the reconciled per-user launcher layout (Slice 4a): an ordered list of
    //           {type:'app', slug} or {type:'folder', id, name, apps:[slug,...]} items. A flat
    //           all-app layout (the fresh-user case) renders BYTE-IDENTICAL to the pre-folders
    //           grid. When absent/empty we fall back to a flat layout derived from `apps`.
    // transport -> the persist seam (Slice 4a). Defaulted to the real saveLayout; injectable for
    //           tests. Persisting is wired in a later task -- this task only establishes the seam.
    constructor(host, { apps = [], onPick = () => {}, layout, transport = { saveLayout } } = {}) {
        this.host = host;
        // The launcher owns "I show USER apps only" (D2): system apps (Appearance/About) are the
        // framework's own furniture and live in the user-icon dropdown (Task 3), never the grid.
        // We're passed the FULL app list + self-filter here -- so parseBootMeta / byApp (the
        // panel's window-label lookup) stays over the full set (S5), untouched by this rule.
        this.apps = apps.filter((a) => !a.system);
        // The layout drives the grid render (Slice 4a). A reconciled layout is used as-is; with
        // none given we synthesise a flat one from the user apps so the fresh-user grid is identical
        // to today. addApp/removeApp keep this in sync with this.apps.
        this.layout = Array.isArray(layout) && layout.length
            ? layout
            : this.apps.map((a) => ({ type: 'app', slug: a.slug }));
        this.transport = transport;
        // Single-flight persist bookkeeping (Slice 4a, Piece 5): _saving marks a save in flight,
        // _pendingSave remembers a mutation landed mid-flight so we re-save the LATEST doc once.
        this._saving = false;
        this._pendingSave = false;
        this.onPick = onPick;
        this.open_ = false;
        this.query = '';
        // Monotonic folder-id source (Slice 4a, Phase 6b) -- new folders get f_1, f_2, ... . A
        // counter, NOT Math.random/Date.now, so tests stay deterministic and ids never collide.
        // Seed it from the highest existing f_<n> in the persisted layout, else a folder minted
        // after reload would reuse f_1 and collide with a folder from a prior session.
        this._folderSeq = this.layout.reduce((max, i) => {
            const m = i.type === 'folder' && /^f_(\d+)$/.exec(i.id);
            return m ? Math.max(max, Number(m[1])) : max;
        }, 0);
        // The per-tile right-click menu (Phase 6b) -- generalised ContextMenu reused with our own
        // item lists (its default desktop items are never shown; we always call open(x, y, items)).
        this.menu = new ContextMenu();
        // The id of the folder whose shelf is currently expanded (Slice 4a, Task 5), or null when
        // none is open. Only ONE shelf is open at a time. Reset on close() so a reopen starts collapsed.
        this.openFolderId = null;

        // The backdrop overlay (covers the whole viewport, blurs what's behind). A mousedown
        // DIRECTLY on the backdrop closes; a mousedown that started inside the panel does not
        // (the panel stops propagation), mirroring the mockup's `e.target === e.currentTarget`.
        this.el = document.createElement('div');
        this.el.className = 'sx-launcher';
        this.el.setAttribute('data-sx-launcher-backdrop', '');
        this.el.hidden = true;
        this.el.addEventListener('mousedown', (event) => {
            if (event.target === this.el) {
                this.close();
            }
        });

        // The centred panel: a search box + the app grid. A mousedown inside it must NOT
        // bubble to the backdrop's close handler.
        this.panel = document.createElement('div');
        this.panel.className = 'sx-launcher-panel';
        this.panel.setAttribute('data-sx-launcher-panel', '');
        // A mousedown inside the panel must NOT bubble to the backdrop's close handler (so the
        // launcher stays open). But a mousedown INSIDE the panel yet OUTSIDE the open shelf closes
        // the shelf (macOS-style dismiss) -- clicking anywhere off the expanded folder collapses it.
        this.panel.addEventListener('mousedown', (event) => {
            event.stopPropagation();
            if (this.openFolderId !== null && !event.target.closest('.sx-launcher-shelf')) {
                this.closeShelf();
            }
        });

        const searchBox = document.createElement('div');
        searchBox.className = 'sx-launcher-search';
        searchBox.appendChild(icon('search', 15));
        this.search = document.createElement('input');
        this.search.type = 'text';
        this.search.className = 'sx-launcher-search-input';
        this.search.setAttribute('data-sx-launcher-search', '');
        this.search.placeholder = 'Search apps...';
        this.search.addEventListener('input', () => {
            this.query = this.search.value;
            // Searching flattens: a live query collapses any open shelf (the flat filtered list
            // suppresses folders, so a lingering openFolderId has nothing to anchor to). Clearing
            // the query drops back to the folder view with everything collapsed.
            if (this.query.trim()) {
                this.openFolderId = null;
            }
            this.renderGrid();
        });
        searchBox.appendChild(this.search);
        this.panel.appendChild(searchBox);

        this.grid = document.createElement('div');
        this.grid.className = 'sx-launcher-grid';
        this.panel.appendChild(this.grid);

        // Root-grid drag-reorder (Slice 4a, Task 7). Attached ONCE here -- the listener is delegated
        // on this.grid (which persists across re-renders), so it never stacks. from/to are indices
        // over the grid's DIRECT-child .sx-launcher-tile elements, which map 1:1 to this.layout items
        // (a folder's shelf is a direct child too but it's .sx-launcher-shelf, so it's excluded).
        makeReorderable(this.grid, {
            onReorder: (from, to) => this.reorderRoot(from, to),
            onOnto: (from, onto) => this.dropOntoRoot(from, onto),
        });

        // Right-click the EMPTY launcher area (not a tile) -> New folder (Slice 4a discoverability).
        // A tile has its own contextmenu handler (seed-from-app / delete); guard so we don't double-fire.
        this.grid.addEventListener('contextmenu', (event) => {
            if (event.target.closest('.sx-launcher-tile') || event.target.closest('.sx-launcher-shelf')) {
                return; // a tile or shelf owns this right-click
            }
            event.preventDefault();
            this.menu.open(event.clientX, event.clientY, [
                { id: 'new-folder-empty', label: 'New folder', icon: 'window', run: () => this.newEmptyFolder() },
            ]);
        });

        this.el.appendChild(this.panel);

        // The lone-Meta-tap toggle (how the OS start menu opens on a bare Cmd/Win tap). We
        // track a Meta press+release with NO other key in between: metaDown marks Meta is
        // held, metaCombo trips the moment any other key joins it (Cmd+C etc.) so its release
        // does NOT toggle. The actual toggle fires on the Meta keyup -- a clean lone tap.
        this.metaDown = false;
        this.metaCombo = false;

        // Escape closes the overlay (only while open). Plus the Meta-combo tracking: a Meta
        // keydown arms a potential lone tap; any other key while Meta is held marks it a combo.
        this.onKeydown = (event) => {
            if (this.open_ && event.key === 'Escape') {
                // Escape precedence (Slice 4a, Task 5): an open shelf soaks the Escape -- it collapses
                // the shelf and the launcher STAYS open. Only when no shelf is open does Escape close
                // the launcher itself.
                if (this.openFolderId !== null) {
                    this.closeShelf();
                } else {
                    this.close();
                }
            }

            if (event.key === 'Meta') {
                if (!event.repeat) {
                    this.metaDown = true;
                    this.metaCombo = false;
                }
            } else if (this.metaDown) {
                // Meta is being used in a combo -- suppress the toggle on its release.
                this.metaCombo = true;
            }
        };
        document.addEventListener('keydown', this.onKeydown);

        // A Meta keyup with no combo in between is a lone tap -> toggle the launcher.
        this.onKeyup = (event) => {
            if (event.key === 'Meta') {
                if (this.metaDown && !this.metaCombo) {
                    this.toggle();
                }
                this.metaDown = false;
                this.metaCombo = false;
            }
        };
        document.addEventListener('keyup', this.onKeyup);

        // Cmd+Tab (and any focus loss) blurs the window mid-combo -- the Meta keyup never
        // lands here. Reset so stale state doesn't toggle on return.
        this.onBlur = () => {
            this.metaDown = false;
            this.metaCombo = false;
        };
        window.addEventListener('blur', this.onBlur);

        host.appendChild(this.el);
    }

    isOpen() {
        return this.open_;
    }

    // The IN-MEMORY install check (App-install plan, B1 -- the seed source). this.apps is the
    // launcher's live user-app set (already !system-filtered in the ctor), so hasApp over it ==
    // "this user app is installed". It reads the in-memory array, NOT the grid DOM -- which only
    // exists while the launcher is OPEN, and Manage-apps is open while the launcher is CLOSED, so
    // a DOM query would read empty and seed everything as uninstalled. Always read this.apps.
    hasApp(slug) {
        return this.apps.some((a) => a.slug === slug);
    }

    // The installed user-app slugs (App-install plan, B1) -- the in-memory set projected to slugs.
    installedSlugs() {
        return this.apps.map((a) => a.slug);
    }

    // Does the layout ALREADY place this slug anywhere (Slice 4a) -- as a root app item OR inside a
    // folder? addApp uses this so an app that lives in a folder isn't ALSO added at root.
    layoutHas(slug) {
        return this.layout.some((i) =>
            (i.type === 'app' && i.slug === slug)
            || (i.type === 'folder' && i.apps.includes(slug)));
    }

    // Drop a slug from the layout wherever it sits (Slice 4a) -- the uninstall sync. Removes a root
    // app item and strips the slug from any folder's list. An emptied folder is KEPT (Slice 4a
    // explicit-container model): folders never auto-dissolve, they go only via an explicit Delete
    // (deleteFolder). Pure -- rebuilds this.layout, no in-place mutation.
    dropFromLayout(slug) {
        this.layout = this.layout
            .map((i) => (i.type === 'folder' ? { ...i, apps: i.apps.filter((s) => s !== slug) } : i))
            .filter((i) => !(i.type === 'app' && i.slug === slug));
    }

    // Add an app live (App-install plan, D5) -- the install path. Adds {slug, title, icon} to the
    // in-memory set FIRST (the seed source, B1), APPENDS a root app item to the layout unless the
    // slug is already placed (Slice 4a), then repaints the grid if the launcher is open so the tile
    // appears on the spot. Idempotent: an app already in the set is a no-op (no dup entry, no dup
    // tile). The meta comes from the Manage-apps row (the launcher's boot set no longer has it).
    addApp(meta) {
        if (!meta || !meta.slug) {
            return;
        }
        // In-memory set stays idempotent (App-install plan) -- only push when it's genuinely new.
        if (!this.hasApp(meta.slug)) {
            this.apps.push({ slug: meta.slug, title: meta.title ?? meta.slug, icon: meta.icon ?? 'window' });
        }
        // Sync the layout (Slice 4a): append a root app item unless the slug is already placed
        // (at root OR inside a folder). Guarded independently of hasApp so an installed app that is
        // somehow missing from the layout still gets a tile.
        let changed = false;
        if (!this.layoutHas(meta.slug)) {
            this.layout.push({ type: 'app', slug: meta.slug });
            changed = true;
        }
        if (changed && this.isOpen()) {
            this.renderGrid();
        }
        this.persist();
    }

    // Remove an app live (App-install plan, D5) -- the uninstall path. Drops it from the in-memory
    // set FIRST (B1), then from the layout (an emptied folder is KEPT, Slice 4a) + repaints the
    // grid if open so the tile disappears on the spot. A no-op for an app not in the set. The app's
    // open WINDOWS are closed by the interceptor (manage-apps.js) via wm.removeSurface directly (B2)
    // -- the launcher owns only its tile, not the surfaces.
    removeApp(slug) {
        const before = this.apps.length;
        this.apps = this.apps.filter((a) => a.slug !== slug);
        if (this.apps.length === before) {
            return;
        }
        this.dropFromLayout(slug);
        if (this.isOpen()) {
            this.renderGrid();
        }
        this.persist();
    }

    // Persist the current layout (Slice 4a, Piece 5) via the transport seam, single-flight.
    persist() {
        // Single-flight: if a save is in flight, remember we need another with the LATEST doc and
        // fire it once the current one settles (coalescing intermediate mutations). The per-document
        // race guard (Plan 4a, Piece 5) -- reorder racing an install can't clobber.
        if (this._saving) {
            this._pendingSave = true;
            return;
        }
        this._saving = true;
        this._pendingSave = false;
        Promise.resolve(this.transport.saveLayout(this.layout)).finally(() => {
            this._saving = false;
            if (this._pendingSave) {
                this.persist();
            }
        });
    }

    // A deterministic folder id (Phase 6b) -- a monotonic counter, never Math.random/Date.now, so
    // ids are stable+unique across a session and reproducible in tests.
    genFolderId() {
        this._folderSeq += 1;
        return 'f_' + this._folderSeq;
    }

    // New folder from an app (Phase 6b) -- pull the app out of wherever it sits (root or a folder,
    // an emptied source folder is KEPT) then push a fresh 1-app folder at root. Re-render + persist.
    newFolderFrom(slug, name) {
        this.dropFromLayout(slug);
        this.layout.push({ type: 'folder', id: this.genFolderId(), name: name || 'Folder', apps: [slug] });
        this.renderGrid();
        this.persist();
    }

    // Move an app to a folder or Home (Phase 6b). dropFromLayout first (this strips it from any
    // source folder, KEEPING that folder even if it empties). folderId null -> back to root as an
    // app item; else find the target folder and append the slug. If the target no longer exists,
    // fall back to a root push so the app is never lost.
    moveTo(slug, folderId) {
        this.dropFromLayout(slug);
        const target = folderId === null
            ? null
            : this.layout.find((i) => i.type === 'folder' && i.id === folderId);
        if (target) {
            target.apps.push(slug);
        } else {
            this.layout.push({ type: 'app', slug });
        }
        this.renderGrid();
        this.persist();
    }

    // Rename a folder (Phase 6b) -- set its name, re-render + persist. A no-op if the id is stale.
    renameFolder(id, name) {
        const folder = this.layout.find((i) => i.type === 'folder' && i.id === id);
        if (!folder) {
            return;
        }
        folder.name = name;
        this.renderGrid();
        this.persist();
    }

    // Delete a folder (Slice 4a explicit-container) -- the ONLY way a folder is removed. Its member
    // apps are returned to root (as app items) unless they're already placed elsewhere, so no app is
    // ever lost with the container. If the deleted folder's shelf was open, collapse it. Re-render +
    // persist. A no-op for a stale id.
    deleteFolder(id) {
        const folder = this.layout.find((i) => i.type === 'folder' && i.id === id);
        if (!folder) {
            return;
        }
        const slugs = folder.apps.slice();
        this.layout = this.layout.filter((i) => !(i.type === 'folder' && i.id === id));
        for (const slug of slugs) {
            if (!this.layoutHas(slug)) {
                this.layout.push({ type: 'app', slug });
            }
        }
        if (this.openFolderId === id) {
            this.openFolderId = null;
        }
        this.renderGrid();
        this.persist();
    }

    // Reorder a ROOT item (Slice 4a, Task 7) -- splice the item out of `from` and back in at `to`,
    // re-render + persist. `from`/`to` are indices into this.layout (the Nth root .sx-launcher-tile
    // maps 1:1 to this.layout[N] -- the shelf sits between tiles but isn't a .sx-launcher-tile, so it
    // never shifts the index). Fired by makeReorderable on a settled drag-drop over the grid.
    reorderRoot(from, to) {
        const [item] = this.layout.splice(from, 1);
        this.layout.splice(to, 0, item);
        this.renderGrid();
        this.persist();
    }

    // Drag-onto (Slice 4a discoverability -- the Launchpad group gesture). An APP dropped onto another
    // APP makes a folder of the two; onto a FOLDER, joins it. Only an app can be the dragged source.
    dropOntoRoot(fromIndex, ontoIndex) {
        const from = this.layout[fromIndex];
        const onto = this.layout[ontoIndex];
        if (!from || !onto || from === onto || from.type !== 'app') {
            return;
        }
        if (onto.type === 'folder') {
            this.moveTo(from.slug, onto.id); // re-renders + persists
            return;
        }
        if (onto.type === 'app') {
            const slugA = onto.slug;
            const slugB = from.slug;
            this.dropFromLayout(slugA);
            this.dropFromLayout(slugB);
            this.layout.push({ type: 'folder', id: this.genFolderId(), name: 'Folder', apps: [slugA, slugB] });
            this.renderGrid();
            this.persist();
        }
    }

    // Reorder an app WITHIN a folder (Slice 4a, Task 7) -- splice the folder's apps list, re-render +
    // persist. A no-op if the id is stale. Fired by makeReorderable attached to the open shelf's tiles.
    reorderFolder(id, from, to) {
        const folder = this.layout.find((i) => i.type === 'folder' && i.id === id);
        if (!folder) {
            return;
        }
        const [slug] = folder.apps.splice(from, 1);
        folder.apps.splice(to, 0, slug);
        this.renderGrid();
        this.persist();
    }

    // Build the right-click item list for a tile (Phase 6b). A ROOT app can start a new folder or
    // move into any existing folder. A SHELF app (inside the open folder) can go Home or move to a
    // DIFFERENT folder. The menu renders a flat list, so "move to X" is emitted as one flat item
    // per destination rather than a submenu. Item shape matches the desktop menu: {id,label,icon,run}.
    contextItemsFor(slug, inShelf, currentFolderId) {
        const folders = this.layout.filter((i) => i.type === 'folder');
        const items = [];
        if (inShelf) {
            items.push({ id: 'move-home', label: 'Move to Home', icon: 'window', run: () => this.moveTo(slug, null) });
            for (const f of folders) {
                if (f.id === currentFolderId) {
                    continue;
                }
                items.push({ id: 'move-' + f.id, label: 'Move to: ' + f.name, icon: 'window', run: () => this.moveTo(slug, f.id) });
            }
        } else {
            items.push({ id: 'new-folder', label: 'New folder...', icon: 'window', run: () => this.newFolderFrom(slug, 'Folder') });
            for (const f of folders) {
                items.push({ id: 'move-' + f.id, label: 'Move to: ' + f.name, icon: 'window', run: () => this.moveTo(slug, f.id) });
            }
        }
        return items;
    }

    // Flip the overlay (the start-menu tap behaviour): open if closed, close if open.
    toggle() {
        if (this.isOpen()) {
            this.close();
        } else {
            this.open();
        }
    }

    open() {
        this.open_ = true;
        this.query = '';
        this.search.value = '';
        this.el.hidden = false;
        this.renderGrid();
        // Focus the search box so typing filters immediately (the mockup's autoFocus).
        this.search.focus();
    }

    close() {
        this.open_ = false;
        this.el.hidden = true;
        // A reopen starts collapsed -- drop any expanded shelf so it doesn't linger across opens.
        this.openFolderId = null;
    }

    // Toggle a folder's shelf (Slice 4a, Task 5): opening a folder expands its shelf; re-clicking the
    // SAME folder collapses it. Opening a different folder swaps the shelf (only one open at a time,
    // enforced by the single openFolderId + a full renderGrid repaint).
    openFolder(id) {
        this.openFolderId = (this.openFolderId === id) ? null : id; // re-click toggles closed
        this.renderGrid();
    }

    // Collapse the open shelf (Slice 4a, Task 5) -- Escape / outside-shelf mousedown path. A no-op
    // when no shelf is open (so a stray dismiss doesn't force a pointless repaint).
    closeShelf() {
        if (this.openFolderId !== null) {
            this.openFolderId = null;
            this.renderGrid();
        }
    }

    // Build the expanded folder shelf (Slice 4a, Task 5) -- a full-width grid item inserted right
    // after the open folder's tile. It carries a name header + the folder's app tiles. LOAD-BEARING:
    // the app tiles reuse appTile() (so they carry data-sx-launch and LAUNCH), so they MUST be nested
    // under this .sx-launcher-shelf wrapper -- NOT loose direct grid children -- or they'd inflate the
    // root-tile count (the ':scope > .sx-launcher-tile[data-sx-launch]' selector).
    // Create an empty folder at root (Slice 4a discoverability -- the backdrop New folder path). Opens
    // its shelf + focuses the name input so the user can rename + drag apps in. Explicit-container
    // model: an empty folder is valid + persists until filled or deleted.
    newEmptyFolder() {
        const id = this.genFolderId();
        this.layout.push({ type: 'folder', id, name: 'Folder', apps: [] });
        this.openFolderId = id;
        this.renderGrid();
        // Focus the shelf name input for immediate rename (guard: it exists after render).
        const nameInput = this.grid.querySelector(`[data-sx-shelf="${id}"] .sx-launcher-shelf-name`);
        nameInput?.focus();
        nameInput?.select?.();
        this.persist();
    }

    shelfEl(folder) {
        const shelf = document.createElement('div');
        shelf.className = 'sx-launcher-shelf';
        shelf.setAttribute('data-sx-shelf', folder.id);

        const header = document.createElement('div');
        header.className = 'sx-launcher-shelf-header';
        const name = document.createElement('input');
        name.type = 'text';
        name.className = 'sx-launcher-shelf-name';
        name.value = folder.name;
        name.setAttribute('data-sx-shelf-name', folder.id);
        // Inline rename commit (Phase 6b): Enter or blur commits the trimmed value. An empty name
        // falls back to the folder's current name so a folder is never left nameless. renameFolder
        // re-renders (which rebuilds this shelf), so guard against a re-entrant commit on the blur
        // that firing the re-render triggers.
        let committed = false;
        const commit = () => {
            if (committed) {
                return;
            }
            committed = true;
            this.renameFolder(folder.id, name.value.trim() || folder.name);
        };
        name.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                commit();
            }
        });
        name.addEventListener('blur', commit);
        header.appendChild(name);
        shelf.appendChild(header);

        const tiles = document.createElement('div');
        tiles.className = 'sx-launcher-shelf-tiles';
        for (const slug of folder.apps) {
            tiles.appendChild(this.appTile(this.metaFor(slug)));
        }
        // Shelf drag-reorder (Slice 4a, Task 7). Attached per-build -- shelfEl mints a fresh `tiles`
        // element on every render, so there's no listener stacking. from/to are indices over the
        // shelf tiles, which map 1:1 to folder.apps in order.
        makeReorderable(tiles, { onReorder: (from, to) => this.reorderFolder(folder.id, from, to) });
        shelf.appendChild(tiles);
        return shelf;
    }

    // Resolve a slug to its app metadata (Slice 4a) -- the layout carries bare slugs (a root app
    // item or a folder's member list), so a render looks the {title, icon} up here. Falls back to a
    // slug-titled window-icon placeholder for a slug with no metadata (a stale layout entry), so a
    // dangling reference never throws mid-render.
    metaFor(slug) {
        return this.apps.find((a) => a.slug === slug) ?? { slug, title: slug, icon: 'window' };
    }

    // Build one app tile (the design AppIcon shape) -- a glyph + the title. A click runs
    // onPick(slug) + closes (the open path lives in the display server). Extracted from renderGrid
    // (Slice 4a) so both a root app item and a folder's contents (a later task) share one builder.
    appTile(app) {
        const title = app.title ?? app.slug;
        const tile = document.createElement('button');
        tile.type = 'button';
        tile.className = 'sx-launcher-tile';
        tile.setAttribute('data-sx-launch', app.slug);
        tile.title = title;

        const glyph = document.createElement('span');
        glyph.className = 'sx-launcher-tile-icon';
        glyph.appendChild(icon(app.icon ?? 'window', 24, 1.4));
        tile.appendChild(glyph);

        const label = document.createElement('span');
        label.className = 'sx-launcher-tile-label';
        label.textContent = title;
        tile.appendChild(label);

        tile.addEventListener('click', () => {
            this.onPick(app.slug);
            this.close();
        });

        // Right-click opens the per-tile management menu (Phase 6b). A tile inside an open shelf is
        // a folder member (Move to Home / other folders); a bare grid tile is a root app (New folder
        // / Move to a folder). The shelf ancestor + its data-sx-shelf id tell the two apart.
        tile.addEventListener('contextmenu', (event) => {
            event.preventDefault();
            event.stopPropagation(); // don't also hit the grid backdrop handler
            const shelf = tile.closest('.sx-launcher-shelf');
            const inShelf = shelf !== null;
            const currentFolderId = shelf ? shelf.getAttribute('data-sx-shelf') : this.openFolderId;
            const items = this.contextItemsFor(app.slug, inShelf, currentFolderId);
            this.menu.open(event.clientX, event.clientY, items);
        });

        return tile;
    }

    // Build one folder tile (Slice 4a) -- a mini-grid of up to four member glyphs + the folder name.
    // Marked with data-sx-folder (NOT data-sx-launch) so it's distinguishable from an app tile. The
    // shelf-open wiring (clicking a folder to reveal its apps) lands in the next task.
    folderTile(folder) {
        const tile = document.createElement('button');
        tile.type = 'button';
        tile.className = 'sx-launcher-tile sx-launcher-folder';
        tile.setAttribute('data-sx-folder', folder.id);
        tile.title = folder.name;

        const mini = document.createElement('span');
        mini.className = 'sx-launcher-folder-mini';
        for (const slug of folder.apps.slice(0, 4)) {
            const g = document.createElement('span');
            g.className = 'sx-launcher-folder-mini-icon';
            g.appendChild(icon(this.metaFor(slug).icon ?? 'window', 12, 1.4));
            mini.appendChild(g);
        }
        tile.appendChild(mini);

        const label = document.createElement('span');
        label.className = 'sx-launcher-tile-label';
        label.textContent = folder.name;
        tile.appendChild(label);

        tile.addEventListener('click', () => this.openFolder(folder.id));

        // Right-click a folder tile -> the folder management menu (Slice 4a explicit-container). Just
        // Delete folder for now (New folder / drag-onto land in later tasks). stopPropagation so it
        // doesn't ALSO trip the backdrop menu that a later task wires on the grid.
        tile.addEventListener('contextmenu', (event) => {
            event.preventDefault();
            event.stopPropagation();
            this.menu.open(event.clientX, event.clientY, [
                { id: 'delete-folder', label: 'Delete folder', icon: 'window', run: () => this.deleteFolder(folder.id) },
            ]);
        });

        return tile;
    }

    // Rebuild the grid from the LAYOUT (Slice 4a), filtered by the search query (case-insensitive
    // title match on app items). A flat all-app layout renders one app tile per item, in order --
    // byte-identical to the pre-folders grid. A folder item renders a folder tile. The search
    // filter applies to app items only (folders always show while a query is active this task).
    // A tile click -> onPick(slug) + close (the open path lives in the display server).
    renderGrid() {
        const q = this.query.trim().toLowerCase();
        this.grid.replaceChildren();

        // The empty-launcher state (App-install plan, D7): uninstalling every user app leaves ZERO
        // tiles (system apps live in the user menu, not the grid). Show a hint pointing at Manage
        // apps instead of a blank grid -- the user is never stuck (Manage apps is always reachable
        // from the user menu). Only on a TRULY empty set (no apps installed), not a search miss.
        if (this.apps.length === 0) {
            const hint = document.createElement('div');
            hint.className = 'sx-launcher-empty';
            hint.setAttribute('data-sx-launcher-empty', '');
            hint.textContent = 'No apps installed. Open Manage apps from the user menu to add some.';
            this.grid.appendChild(hint);
            return;
        }

        // Search-flatten (Slice 4a, Piece 6a): a live query renders a FLAT filtered list of ALL
        // apps -- walking this.apps directly (not the layout), so apps tucked inside folders still
        // surface. No folder tiles, no shelf while searching; clearing the query returns to the
        // layout walk below (folders + shelf).
        if (q) {
            for (const app of this.apps) {
                const title = app.title ?? app.slug;
                if (!title.toLowerCase().includes(q)) {
                    continue;
                }
                this.grid.appendChild(this.appTile(app));
            }
            return;
        }

        for (const item of this.layout) {
            if (item.type === 'folder') {
                this.grid.appendChild(this.folderTile(item));
                // The open folder's shelf drops in RIGHT AFTER its tile (Slice 4a, Task 5) -- a
                // full-width grid item that pushes later tiles down. Its app tiles stay nested under
                // .sx-launcher-shelf, so they never count as root tiles.
                if (item.id === this.openFolderId) {
                    this.grid.appendChild(this.shelfEl(item));
                }
            } else {
                const app = this.metaFor(item.slug);
                const title = app.title ?? app.slug;
                if (q && !title.toLowerCase().includes(q)) {
                    continue;
                }
                this.grid.appendChild(this.appTile(app));
            }
        }
    }
}
