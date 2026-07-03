<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SystemX\Core\Support\Desktop;
use Tests\TestCase;

class DesktopServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_render_assembles_boot_data_and_sxconfig(): void
    {
        $user = User::factory()->create();

        // ClientConfig reads $request->session()->token(); bind a real session driver the same
        // way the web middleware stack would (the host route gets one for free).
        $request = request();
        $request->setLaravelSession(session()->driver());
        $request->setUserResolver(fn () => $user);

        $view = app(Desktop::class)->render($request);
        $data = $view->getData();

        $this->assertSame('system-x::desktop', $view->name());
        $this->assertArrayHasKey('apps', $data);
        $this->assertArrayHasKey('prefs', $data);
        $this->assertArrayHasKey('sxConfig', $data);

        // The per-window shape MUST survive the lift: the seeded hello window carries its
        // title/icon (the $metaBySlug join) AND its geometry fields (forPrincipal's geometry).
        $w = collect($data['windows'])->firstWhere('app', 'hello');
        $this->assertSame('Hello', $w['title']);
        $this->assertArrayHasKey('icon', $w);
        $this->assertArrayHasKey('x', $w);       // geometry survives (x/y/w/h/sized/max/min/z)
        $this->assertArrayHasKey('sized', $w);
    }
}
