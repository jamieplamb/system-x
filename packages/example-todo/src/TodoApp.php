<?php

namespace Example\TodoApp;

use Example\TodoApp\Widgets\Gauge;
use SystemX\Core\Runtime\App;
use SystemX\Core\Runtime\WidgetEvent;
use SystemX\Core\Widgets\Button;
use SystemX\Core\Widgets\Label;
use SystemX\Core\Widgets\Stack;
use SystemX\Core\Widgets\TextField;
use SystemX\Core\Widgets\Window;
use SystemX\Core\Wire\Node;

// A minimal third-party app -- built from CORE widgets only (no custom widget, no JS). Durable
// typed state ($items, $draft) auto-persists per user/window. Handlers coerce $event->value via
// the safe accessors (treat it as untrusted). This is the template a stranger copies.
class TodoApp extends App
{
    /** @var array<int, array{text: string, done: bool}> */
    public array $items = [];

    public string $draft = '';

    public int $count = 0;

    public function slug(): string
    {
        return 'example.todo';
    }

    public function title(): string
    {
        return 'Todo';
    }

    public function icon(): string
    {
        // A real glyph name from the design Icon set (icons.js GLYPHS). `list` is NOT a key --
        // it would silently fall back to the generic `window` glyph -- so use `notes`, the
        // checklist-style glyph that fits a todo.
        return 'notes';
    }

    public function render(): Node
    {
        $rows = [];
        foreach ($this->items as $i => $item) {
            $mark = $item['done'] ? '[x] ' : '[ ] ';
            $rows[] = Stack::make()->content([
                Label::make($mark.$item['text'])->id("item-{$i}"),
                Button::make('Done')->id("done-{$i}")->handles('toggle'.$i),
                Button::make('Remove')->id("remove-{$i}")->handles('remove'.$i),
            ]);
        }

        return Window::make('Todo')->size(320, 420)->content([
            Stack::make()->content([
                Label::make('A tiny third-party app. Add a task and it persists.'),
                // A CUSTOM third-party widget (example.gauge) -- its renderer + CSS ship in this
                // package's dist bundle via the vendor-asset seam. Clicking it round-trips through
                // the delegated dispatcher to this handler, proving the full client contract.
                Gauge::make($this->count)->id('gauge')
                    ->on('click', fn () => $this->count++),
                TextField::make('draft')->value($this->draft)->id('draft')
                    ->onChange(fn (WidgetEvent $e) => $this->draft = $e->asString()),
                Button::make('Add')->id('add')->handles('add'),
                ...$rows,
            ]),
        ]);
    }

    public function add(): void
    {
        $text = trim($this->draft);
        if ($text !== '') {
            $this->items[] = ['text' => $text, 'done' => false];
            $this->draft = '';
        }
    }

    public function __call(string $method, array $args): void
    {
        if (preg_match('/^toggle(\d+)$/', $method, $m) && isset($this->items[(int) $m[1]])) {
            $this->items[(int) $m[1]]['done'] = ! $this->items[(int) $m[1]]['done'];
        } elseif (preg_match('/^remove(\d+)$/', $method, $m)) {
            unset($this->items[(int) $m[1]]);
            $this->items = array_values($this->items);
        }
    }
}
