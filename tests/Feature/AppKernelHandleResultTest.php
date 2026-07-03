<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use SystemX\Core\Runtime\AppKernel;
use SystemX\Core\Runtime\HandleResult;
use SystemX\Core\Runtime\WidgetEvent;
use SystemX\Core\State\StateKey;
use Tests\TestCase;

class AppKernelHandleResultTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_returns_tree_and_the_property_delta(): void
    {
        $kernel = $this->app->make(AppKernel::class);
        $key = new StateKey('user', '7', 'win-hello');

        $result = $kernel->handle($key, 'hello', new WidgetEvent('clicker', 'click', null, []));

        $this->assertInstanceOf(HandleResult::class, $result);
        $this->assertIsArray($result->tree);
        $this->assertSame(['count' => [0, 1]], $result->delta);
    }
}
