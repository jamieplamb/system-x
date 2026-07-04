<?php

namespace Tests\Feature\Demo;

use SystemX\Core\Runtime\AppRegistry;
use Tests\TestCase;

class WelcomeAppOffTest extends TestCase
{
    public function test_welcome_absent_when_demo_off(): void
    {
        // Plain TestCase boots with the env unset -> config default false -> not registered.
        $this->assertFalse(app(AppRegistry::class)->has('welcome'));
    }
}
