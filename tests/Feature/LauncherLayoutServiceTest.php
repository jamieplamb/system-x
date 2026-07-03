<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use SystemX\Core\Launcher\LauncherLayoutService;
use SystemX\Core\State\StateKey;
use Tests\TestCase;

class LauncherLayoutServiceTest extends TestCase
{
    use RefreshDatabase;

    private function principal(): StateKey
    {
        return new StateKey('user', '1', '');
    }

    public function test_a_fresh_user_has_an_empty_layout(): void
    {
        $this->assertSame([], app(LauncherLayoutService::class)->layoutFor($this->principal()));
    }

    public function test_it_saves_and_returns_the_layout_document(): void
    {
        $svc = app(LauncherLayoutService::class);
        $layout = [
            ['type' => 'app', 'slug' => 'hello'],
            ['type' => 'folder', 'id' => 'f_ab12', 'name' => 'Tools', 'apps' => ['controls', 'notes']],
        ];

        $svc->save($this->principal(), $layout);

        $this->assertSame($layout, $svc->layoutFor($this->principal()));
    }

    public function test_save_overwrites_the_whole_document(): void
    {
        $svc = app(LauncherLayoutService::class);
        $svc->save($this->principal(), [['type' => 'app', 'slug' => 'hello']]);
        $svc->save($this->principal(), [['type' => 'app', 'slug' => 'notes']]);

        $this->assertSame([['type' => 'app', 'slug' => 'notes']], $svc->layoutFor($this->principal()));
    }

    public function test_it_is_bound_as_a_singleton(): void
    {
        $this->assertSame(
            app(LauncherLayoutService::class),
            app(LauncherLayoutService::class),
        );
    }
}
