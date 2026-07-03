<?php

namespace SystemX\Core\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use SystemX\Core\Runtime\App;
use SystemX\Core\Runtime\PropertyHydrator;
use SystemX\Core\Runtime\WidgetEvent;
use SystemX\Core\State\StateBag;
use SystemX\Core\Widgets\Button;
use SystemX\Core\Widgets\Checkbox;
use SystemX\Core\Widgets\TextField;
use SystemX\Core\Widgets\Window;
use SystemX\Core\Wire\Node;
use SystemX\Core\Wire\Serializer;

class NodeBindingTest extends TestCase
{
    public function test_on_records_the_binding_and_opens_the_events_allowlist(): void
    {
        $button = Button::make('Click')->id('go')->on('click', 'increment');

        // The handler rides the dedicated bindings field...
        $this->assertSame([['click', 'increment']], $button->bindings);
        // ...and the event is also opened on the props.events round-trip allowlist.
        $this->assertSame(['click'], $button->props['events']);
    }

    public function test_onclick_is_sugar_for_on_click(): void
    {
        $handler = fn () => null;
        $button = Button::make('Click')->id('go')->onClick($handler);

        $this->assertSame([['click', $handler]], $button->bindings);
        $this->assertSame(['click'], $button->props['events']);
    }

    public function test_handles_is_sugar_for_a_named_click_handler(): void
    {
        $button = Button::make('Click')->id('go')->handles('increment');

        $this->assertSame([['click', 'increment']], $button->bindings);
        $this->assertSame(['click'], $button->props['events']);
    }

    public function test_textfield_onsubmit_with_a_handler_records_the_binding_and_opens_the_allowlist(): void
    {
        $handler = fn () => null;
        $field = TextField::make('message')->id('msg')->onSubmit($handler);

        $this->assertSame([['submit', $handler]], $field->bindings);
        $this->assertSame(['submit'], $field->props['events']);
    }

    public function test_textfield_onsubmit_without_a_handler_only_opens_the_allowlist(): void
    {
        $field = TextField::make('message')->id('msg')->onSubmit();

        // No handler passed -> nothing bound, just the round-trip opened (back-compat).
        $this->assertSame([], $field->bindings);
        $this->assertSame(['submit'], $field->props['events']);
    }

    public function test_textfield_onchange_with_a_handler_records_the_binding(): void
    {
        $handler = fn () => null;
        $field = TextField::make('message')->id('msg')->onChange($handler);

        $this->assertSame([['change', $handler]], $field->bindings);
        $this->assertContains('change', $field->props['events']);
    }

    public function test_checkbox_onchange_with_a_handler_records_the_binding(): void
    {
        $handler = fn () => null;
        $box = Checkbox::make('Notify')->id('notify')->onChange($handler);

        $this->assertSame([['change', $handler]], $box->bindings);
        $this->assertContains('change', $box->props['events']);
    }

    public function test_textfield_onsubmit_binds_a_handler_that_fires_on_dispatch(): void
    {
        $app = new class extends App
        {
            public string $message = '';

            public function slug(): string
            {
                return 'fixture';
            }

            public function render(): Node
            {
                return Window::make('Fixture')->content([
                    TextField::make('message')->id('msg')
                        ->onSubmit(function (WidgetEvent $event): void {
                            $this->message = (string) $event->value;
                        }),
                ]);
            }
        };
        (new PropertyHydrator)->hydrate($app, new StateBag(['message' => ''], 1));

        $app->boot(new WidgetEvent('msg', 'submit', 'hello', []));

        $this->assertSame('hello', $app->message);
    }

    public function test_bindings_never_reach_the_serialized_wire(): void
    {
        $button = Button::make('Click')->id('go')->handles('increment');

        $result = (new Serializer)->serialize($button);

        // Only the four wire keys exist -- no bindings key leaks.
        $this->assertSame(['type', 'id', 'props', 'children'], array_keys($result));
        $this->assertArrayNotHasKey('bindings', $result);
        // The event allowlist DID make it onto props (binding opens the round-trip).
        $this->assertSame(['click'], $result['props']['events']);
    }

    public function test_a_button_with_no_binding_serializes_with_empty_events_absent(): void
    {
        $result = (new Serializer)->serialize(Button::make('Click')->id('go'));

        $this->assertArrayNotHasKey('bindings', $result);
        // No binding means no events key was ever opened.
        $this->assertArrayNotHasKey('events', $result['props']);
    }
}
