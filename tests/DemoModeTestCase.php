<?php

namespace Tests;

use Illuminate\Foundation\Application;

// Boots the app with SYSTEM_X_DEMO_MODE=true set BEFORE bootstrap, so config()-gated route
// registration (routes/web.php) and the welcome-app registration (AppServiceProvider::boot)
// are actually on. Runtime config([...]) is too late for bootstrap-time gates -- this is the
// only reliable way to exercise the flag-ON surface. Flag-OFF tests extend the plain TestCase
// (which boots with the env unset -> flag off by the config default).
abstract class DemoModeTestCase extends TestCase
{
    public function createApplication(): Application
    {
        putenv('SYSTEM_X_DEMO_MODE=true');
        $_ENV['SYSTEM_X_DEMO_MODE'] = 'true';
        $_SERVER['SYSTEM_X_DEMO_MODE'] = 'true';

        return parent::createApplication();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        putenv('SYSTEM_X_DEMO_MODE');
        unset($_ENV['SYSTEM_X_DEMO_MODE'], $_SERVER['SYSTEM_X_DEMO_MODE']);
    }
}
