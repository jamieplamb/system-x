<?php

namespace SystemX\Core\Demo;

use SystemX\Core\Runtime\App;
use SystemX\Core\Widgets\Button;
use SystemX\Core\Widgets\Checkbox;
use SystemX\Core\Widgets\Label;
use SystemX\Core\Widgets\ListItem;
use SystemX\Core\Widgets\ListWidget;
use SystemX\Core\Widgets\Raw;
use SystemX\Core\Widgets\Stack;
use SystemX\Core\Widgets\TextField;
use SystemX\Core\Widgets\Window;
use SystemX\Core\Wire\Node;

class HelloApp extends App
{
    // The ONE persistent property -- bag key stays `count` (D6), so every 4a seeded
    // bag keyed `count` rehydrates unchanged.
    public int $count = 0;

    public function slug(): string
    {
        return 'hello';
    }

    public function title(): string
    {
        return 'Hello';
    }

    public function icon(): string
    {
        return 'window';
    }

    public function render(): Node
    {
        return Window::make('Hello')->size(360, 280)->content([
            // BYTE-IDENTICAL to Plan 3: counter children.0, clicker children.1, the
            // toolkit Stack children.2. The clicker now binds a named increment handler
            // instead of the controller doing $count++ by hand (D1/D6).
            Label::make("Clicked {$this->count} times")->id('counter'),
            Button::make('Click me')->id('clicker')->handles('increment'),

            // Plan 3 toolkit (children.2): a stateful field (focus/caret preservation
            // under morph), a toggle (second interactive widget on the same seam), a
            // keyed list whose length tracks the count (keyed reconciliation), and a
            // raw escape-hatch node (opaque to the morph), wrapped in a Stack to prove
            // composition.
            Stack::make()->content([
                TextField::make('note')->value('')->id('note'),
                Checkbox::make('Notify me')->id('notify'),
                ListWidget::make()->id('items')->content(
                    $this->items($this->count),
                ),
                // Static, developer-authored markup -- NOT end-user input, so the
                // app-owned no-sanitise posture is safe here (D7). The morph treats
                // this node as opaque: it is never rewritten across re-renders.
                Raw::make()->html('<small class="sx-raw-note">Toolkit demo</small>')->id('raw-note'),
            ]),
        ]);
    }

    public function increment(): void
    {
        $this->count++;
    }

    /** @return array<int, Node> */
    private function items(int $count): array
    {
        // One row per click (capped), each keyed by a stable id so the morph matches
        // rows across re-renders instead of rebuilding them.
        $rows = [];
        for ($i = 1; $i <= min($count, 5); $i++) {
            $rows[] = ListItem::make("Item {$i}")->key("item-{$i}")->id("item-{$i}");
        }

        return $rows;
    }
}
