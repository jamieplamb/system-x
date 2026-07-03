<?php

namespace Tests\Feature;

use SystemX\Core\Launcher\LauncherLayoutService;
use Tests\TestCase;

class LauncherReconcileTest extends TestCase
{
    public function test_a_fresh_layout_appends_all_live_apps_at_root_in_order(): void
    {
        $out = LauncherLayoutService::reconcile([], ['hello', 'notes', 'controls']);

        $this->assertSame([
            ['type' => 'app', 'slug' => 'hello'],
            ['type' => 'app', 'slug' => 'notes'],
            ['type' => 'app', 'slug' => 'controls'],
        ], $out);
    }

    public function test_it_drops_an_unknown_root_app_and_appends_a_new_one(): void
    {
        $layout = [
            ['type' => 'app', 'slug' => 'gone'],
            ['type' => 'app', 'slug' => 'hello'],
        ];

        $out = LauncherLayoutService::reconcile($layout, ['hello', 'fresh']);

        $this->assertSame([
            ['type' => 'app', 'slug' => 'hello'],
            ['type' => 'app', 'slug' => 'fresh'],
        ], $out);
    }

    public function test_it_drops_an_unknown_slug_from_a_folder(): void
    {
        $layout = [
            ['type' => 'folder', 'id' => 'f1', 'name' => 'T', 'apps' => ['hello', 'gone']],
        ];

        $out = LauncherLayoutService::reconcile($layout, ['hello']);

        $this->assertSame([
            ['type' => 'folder', 'id' => 'f1', 'name' => 'T', 'apps' => ['hello']],
        ], $out);
    }

    public function test_a_folder_emptied_by_the_drop_is_kept_empty(): void
    {
        $layout = [
            ['type' => 'folder', 'id' => 'f1', 'name' => 'T', 'apps' => ['gone']],
            ['type' => 'app', 'slug' => 'hello'],
        ];

        $out = LauncherLayoutService::reconcile($layout, ['hello']);

        $this->assertSame([
            ['type' => 'folder', 'id' => 'f1', 'name' => 'T', 'apps' => []],
            ['type' => 'app', 'slug' => 'hello'],
        ], $out);
    }

    public function test_an_app_already_in_a_folder_is_not_re_appended_at_root(): void
    {
        $layout = [
            ['type' => 'folder', 'id' => 'f1', 'name' => 'T', 'apps' => ['notes']],
        ];

        $out = LauncherLayoutService::reconcile($layout, ['hello', 'notes']);

        $this->assertSame([
            ['type' => 'folder', 'id' => 'f1', 'name' => 'T', 'apps' => ['notes']],
            ['type' => 'app', 'slug' => 'hello'],
        ], $out);
    }
}
