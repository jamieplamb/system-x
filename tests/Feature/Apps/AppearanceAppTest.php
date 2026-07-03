<?php

namespace Tests\Feature\Apps;

use Illuminate\Foundation\Testing\RefreshDatabase;
use SystemX\Core\Runtime\AppRegistry;
use SystemX\Core\Wire\Serializer;
use Tests\TestCase;

class AppearanceAppTest extends TestCase
{
    use RefreshDatabase;

    public function test_appearance_renders_a_pref_control_for_each_theme(): void
    {
        // The App is a STATIC render (B1) -- it reads NO principal + NO PreferencesService.
        // It emits the control LAYOUT only; the PRESSED-state is CLIENT-seeded (prefs.test.js).
        // So this asserts the data-sx-pref HOOKS are PRESENT, NOT a server pressed-state.
        $app = app(AppRegistry::class)->resolve('appearance');
        $json = json_encode((new Serializer)->serialize($app->renderInitial()));

        // A control per theme value, each carrying its data-sx-pref hook.
        $this->assertStringContainsString('theme:pewter', $json);
        $this->assertStringContainsString('theme:modern', $json);
        // The accent + wallpaper + panel controls are present too.
        $this->assertStringContainsString('accent:amber', $json);
        $this->assertStringContainsString('wallpaper:grid', $json);
        $this->assertStringContainsString('panel:bottom', $json);
    }

    public function test_each_section_wraps_its_options_in_a_labelled_button_row(): void
    {
        // POLISH (feature/polish-appearance-launcher): each section is now a heading Label +
        // a SEPARATE inner row stack holding the option buttons, so the CSS can flow the
        // options horizontally. The row stack carries a stable id the appearance CSS targets.
        $app = app(AppRegistry::class)->resolve('appearance');
        $json = json_encode((new Serializer)->serialize($app->renderInitial()));

        // The heading label + the row container, per section, by their ids.
        foreach (['theme', 'accent', 'wallpaper', 'panel'] as $key) {
            $this->assertStringContainsString("appearance-{$key}-label", $json);
            $this->assertStringContainsString("appearance-{$key}-row", $json);
        }
    }

    public function test_appearance_is_registered_with_its_metadata(): void
    {
        $meta = collect(app(AppRegistry::class)->metadata());
        $appearance = $meta->firstWhere('slug', 'appearance');
        $this->assertSame('Appearance', $appearance['title']);
        $this->assertSame('gear', $appearance['icon']);
    }

    public function test_about_is_registered_with_its_metadata(): void
    {
        $about = collect(app(AppRegistry::class)->metadata())->firstWhere('slug', 'about');
        $this->assertSame('About system-x', $about['title']);
        $this->assertSame('info', $about['icon']);
    }
}
