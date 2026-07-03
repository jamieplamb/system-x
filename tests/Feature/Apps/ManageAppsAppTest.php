<?php

namespace Tests\Feature\Apps;

use Illuminate\Foundation\Testing\RefreshDatabase;
use SystemX\Core\Runtime\AppRegistry;
use SystemX\Core\Wire\Serializer;
use Tests\TestCase;

class ManageAppsAppTest extends TestCase
{
    use RefreshDatabase;

    public function test_manage_apps_renders_a_toggle_per_user_app(): void
    {
        // The App is a STATIC render (the Appearance pattern, D4): it injects AppRegistry but
        // reads NO principal. It emits the toggle LAYOUT only -- a row per USER app (system=false:
        // hello/notes) with a data-sx-app-action hook; the installed/uninstalled STATE is
        // CLIENT-seeded on window-open (Task 5). So this asserts the hooks are PRESENT, not state.
        $app = app(AppRegistry::class)->resolve('apps');
        $json = json_encode((new Serializer)->serialize($app->renderInitial()));

        // A toggle per USER app, each carrying its data-sx-app-action hook (the slug).
        $this->assertStringContainsString('"appAction":"hello"', $json);
        $this->assertStringContainsString('"appAction":"notes"', $json);
    }

    public function test_manage_apps_lists_no_system_app(): void
    {
        // The list is USER apps only (metadata() filtered system === false, D6): no system app
        // (appearance/about/apps itself) ever appears in its own list.
        $app = app(AppRegistry::class)->resolve('apps');
        $json = json_encode((new Serializer)->serialize($app->renderInitial()));

        $this->assertStringNotContainsString('"appAction":"appearance"', $json);
        $this->assertStringNotContainsString('"appAction":"about"', $json);
        $this->assertStringNotContainsString('"appAction":"apps"', $json);
    }

    public function test_manage_apps_is_a_system_app(): void
    {
        // system: true -> it lives in the user-icon menu, is never uninstallable, never in its
        // own list.
        $app = app(AppRegistry::class)->resolve('apps');
        $this->assertTrue($app->system());
    }

    public function test_manage_apps_is_registered_with_its_metadata(): void
    {
        $registry = app(AppRegistry::class);

        $this->assertTrue($registry->has('apps'));

        $meta = collect($registry->metadata())->firstWhere('slug', 'apps');
        $this->assertSame('Manage apps', $meta['title']);
        $this->assertTrue($meta['system']);
    }
}
