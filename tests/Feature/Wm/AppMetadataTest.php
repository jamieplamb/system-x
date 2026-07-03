<?php

namespace Tests\Feature\Wm;

use Illuminate\Foundation\Testing\RefreshDatabase;
use SystemX\Core\Demo\HelloApp;
use SystemX\Core\Demo\NotesApp;
use SystemX\Core\Runtime\App;
use SystemX\Core\Runtime\AppRegistry;
use SystemX\Core\Widgets\Window;
use SystemX\Core\Wire\Node;
use Tests\TestCase;

class AppMetadataTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_base_app_defaults_title_to_the_ucfirst_slug_and_icon_to_window(): void
    {
        // A concrete default on the base App (D2): every app has a label + glyph for free,
        // so a panel button / launcher tile always renders. The defaults derive from the
        // slug; an app overrides what it wants to differ.
        $bare = new class extends App
        {
            public function slug(): string
            {
                return 'gizmo';
            }

            public function render(): Node
            {
                return Window::make('Gizmo');
            }
        };

        // The bare subclass leans on the base defaults.
        $this->assertSame('Gizmo', $bare->title());
        $this->assertSame('window', $bare->icon());

        // Overridden by the demo apps...
        $hello = new HelloApp;
        $notes = new NotesApp;
        $this->assertSame('Hello', $hello->title());
        $this->assertSame('Notes', $notes->title());
        // ...icons are glyph NAMES from the design Icon set.
        $this->assertSame('notes', $notes->icon());
    }

    public function test_the_registry_exposes_metadata_for_every_registered_app(): void
    {
        $registry = app(AppRegistry::class);

        $meta = collect($registry->metadata());

        // hello + notes + appearance + about + apps + audit are registered (core); each carries slug + title + icon.
        // example.todo is a third-party package app auto-discovered into the same registry (see ThirdPartyAppTest).
        $this->assertEqualsCanonicalizing(['hello', 'notes', 'controls', 'appearance', 'about', 'apps', 'audit', 'example.todo'], $meta->pluck('slug')->all());
        $hello = $meta->firstWhere('slug', 'hello');
        $this->assertSame('Hello', $hello['title']);
        $this->assertArrayHasKey('icon', $hello);
    }

    public function test_metadata_carries_the_system_flag_per_app(): void
    {
        // The system flag (plan system-menu, D1): an app declares itself system FURNITURE
        // (settings/about) so the shell knows it belongs in the system menu, not the launcher
        // grid. metadata() carries it per app -- a pure read like title()/icon(). Appearance +
        // About + Manage-apps are system; the user apps (hello, notes) are not.
        $registry = app(AppRegistry::class);

        $meta = collect($registry->metadata())->keyBy('slug');

        $this->assertFalse($meta['hello']['system']);
        $this->assertFalse($meta['notes']['system']);
        $this->assertTrue($meta['appearance']['system']);
        $this->assertTrue($meta['about']['system']);
        $this->assertTrue($meta['apps']['system']);
    }

    public function test_the_base_app_defaults_system_to_false(): void
    {
        // A concrete default on the base App: an app is a USER app unless it opts in. The
        // bare subclass leans on the default; the framework's own apps override to true.
        $bare = new class extends App
        {
            public function slug(): string
            {
                return 'gizmo';
            }

            public function render(): Node
            {
                return Window::make('Gizmo');
            }
        };

        $this->assertFalse($bare->system());
    }
}
