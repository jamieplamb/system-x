<?php

namespace SystemX\Core\Runtime;

use SystemX\Core\Wire\Node;

// The base App (D6). A concrete app subclass declares public typed persistent
// properties (PropertyHydrator maps them), a slug(), a render(): Node, and named
// handler methods. The base declares NO persistent state -- the hydrator's
// declaring-class gate (D2) excludes everything defined here, which is the
// structural reason an injected service or a render-support field can never leak
// into a user's bag.
abstract class App
{
    abstract public function slug(): string;

    abstract public function render(): Node;

    // App METADATA (Plan 5b, D2): the label + glyph the shell renders for this app -- the
    // panel button + the launcher tile. CONCRETE defaults so EVERY app has both for free
    // (a panel button / launcher tile always renders); an app overrides what differs. The
    // icon is a glyph NAME from the design Icon set (icons.js / Icon.jsx GLYPHS keys).
    public function title(): string
    {
        return ucfirst($this->slug());
    }

    public function icon(): string
    {
        return 'window';
    }

    // An app declares itself system FURNITURE (settings/about) -> it lives in the system menu
    // (the user-icon dropdown), not the launcher grid (plan system-menu, D1). A CONCRETE
    // default of false (like title()/icon() default) -- an app is a USER app unless it opts
    // in. Pure read, no state/render. The launcher filters !system; the system menu lists
    // system; metadata() + the boot blob carry the flag.
    public function system(): bool
    {
        return false;
    }

    // The lifecycle the kernel drives, minus the load/save (those are the kernel's,
    // around this): render once to build the binding table by WALKING the tree's
    // Node::bindings fields (D1/S2), dispatch the event against the hydrated $this, then
    // render AGAIN so the broadcast tree reflects the mutation.
    public function boot(WidgetEvent $event): Node
    {
        $first = $this->render();              // first render: nodes carry their bindings
        $table = $this->collectBindings($first);

        $table->dispatch($event->widgetId, $event->event, $event);

        return $this->render();                // second render: reflects the mutated state
    }

    // The boot/resync read: render once with no dispatch (the kernel calls this for
    // GET /system-x/desktop and the static-window boot).
    public function renderInitial(): Node
    {
        return $this->render();
    }

    // Walk the freshly rendered tree, draining each node's bindings into a fresh
    // HandlerTable bound to THIS hydrated instance (D1/S2). The binding spec rides on the
    // node's DEDICATED, NON-serialized `bindings` field (set by Node::on()) -- it is
    // NOT in `props`, so the serializer (which reads only type/id/props/children) drops it
    // for free, keeping the wire byte-identical (D6). Rebuilt per boot() so a stale binding
    // never survives across events.
    private function collectBindings(Node $node, ?HandlerTable $table = null): HandlerTable
    {
        $table ??= new HandlerTable;

        foreach ($node->bindings as [$event, $handler]) {
            // A binding requires an id to be addressable; an unidentified node's
            // bindings are unreachable and skipped.
            if ($node->id !== null) {
                $table->bind($node->id, $event, BoundHandler::from($this, $handler));
            }
        }

        foreach ($node->children as $child) {
            $this->collectBindings($child, $table);
        }

        return $table;
    }
}
