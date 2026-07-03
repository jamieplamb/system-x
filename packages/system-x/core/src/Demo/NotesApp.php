<?php

namespace SystemX\Core\Demo;

use SystemX\Core\Runtime\App;
use SystemX\Core\Runtime\WidgetEvent;
use SystemX\Core\Widgets\Checkbox;
use SystemX\Core\Widgets\Label;
use SystemX\Core\Widgets\TextField;
use SystemX\Core\Widgets\Window;
use SystemX\Core\Wire\Node;

// The SECOND demo app (D6). Distinct slug `notes`, distinct ids, distinct bag shape
// (message + notify -- NO count). Exercises the INLINE closure binding (D1) and the
// WidgetEvent value (string from a TextField submit, bool from a Checkbox change).
class NotesApp extends App
{
    public string $message = '';

    public bool $notify = false;

    public function slug(): string
    {
        return 'notes';
    }

    public function title(): string
    {
        return 'Notes';
    }

    public function icon(): string
    {
        // The design Icon set's `notes` glyph.
        return 'notes';
    }

    public function render(): Node
    {
        $status = $this->notify ? 'on' : 'off';

        return Window::make('Notes')->size(360, 220)->content([
            // Inline-closure binding via the ->onSubmit($fn) sugar (D1). Passing a handler
            // BOTH records it AND opens `submit` in the props.events allowlist -- it routes
            // through the ONE registrar `on('submit', $fn)` internally, so binding and
            // round-trip stay one act. (->on('submit', $fn) is the explicit equivalent.)
            TextField::make('message')->value($this->message)->id('message-field')
                ->onSubmit(function (WidgetEvent $event): void {
                    $this->message = $event->asString();
                }),

            Checkbox::make('Notify me')->checked($this->notify)->id('notify-toggle')
                ->onChange(function (WidgetEvent $event): void {
                    $this->notify = $event->asBool();
                }),

            // The preview label echoes the durable state -- children.2.
            Label::make("Note: {$this->message} (notify {$status})")->id('preview'),
        ]);
    }
}
