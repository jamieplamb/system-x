<?php

namespace Tests\Feature\Demo;

use App\Apps\WelcomeApp;
use SystemX\Core\Runtime\AppRegistry;
use Tests\DemoModeTestCase;

class WelcomeAppTest extends DemoModeTestCase
{
    public function test_welcome_registered_when_demo_on(): void
    {
        $this->assertTrue(app(AppRegistry::class)->has('welcome'));
    }

    public function test_welcome_renders_a_window(): void
    {
        $app = new WelcomeApp;
        $node = $app->render();

        // Node::$type is a public PROPERTY, not a method.
        $this->assertSame('window', $node->type);
        $this->assertSame('welcome', $app->slug());
    }
}
