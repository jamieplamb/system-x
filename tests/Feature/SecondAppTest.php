<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use SystemX\Core\Runtime\AppKernel;
use SystemX\Core\Runtime\WidgetEvent;
use SystemX\Core\State\StateKey;
use SystemX\Core\State\StateStore;
use Tests\TestCase;

class SecondAppTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_submit_event_stores_the_typed_string_via_the_widget_event_value(): void
    {
        $key = new StateKey('user', '1', 'notes'); // window id == slug (D8)

        $result = $this->app->make(AppKernel::class)->handle(
            $key,
            'notes',
            new WidgetEvent('message-field', 'submit', 'buy milk', []),
        );

        // The inline-closure handler wrote the WidgetEvent value into the typed prop,
        // and the re-render echoes it in the preview label.
        $this->assertStringContainsString('buy milk', $this->previewText($result->tree));
        $this->assertSame('buy milk', $this->app->make(StateStore::class)->load($key)->get('message'));
    }

    public function test_a_checkbox_change_stores_the_boolean_value(): void
    {
        $key = new StateKey('user', '1', 'notes');

        $this->app->make(AppKernel::class)->handle(
            $key,
            'notes',
            new WidgetEvent('notify-toggle', 'change', true, []),
        );

        $this->assertTrue($this->app->make(StateStore::class)->load($key)->get('notify'));
    }

    public function test_its_bag_shape_is_distinct_from_helloapp(): void
    {
        $key = new StateKey('user', '1', 'notes');

        $this->app->make(AppKernel::class)->handle(
            $key, 'notes', new WidgetEvent('message-field', 'submit', 'x', []),
        );

        // No `count` key -- a different app, a different bag shape on the SAME store.
        $bag = $this->app->make(StateStore::class)->load($key)->toArray();
        $this->assertArrayHasKey('message', $bag);
        $this->assertArrayNotHasKey('count', $bag);
    }

    private function previewText(array $tree): string
    {
        // Walk to the preview label -- index per the NotesApp tree (Task 9 implementation).
        return $tree['children'][2]['props']['text'] ?? '';
    }
}
