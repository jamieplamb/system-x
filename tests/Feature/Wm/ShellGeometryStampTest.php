<?php

namespace Tests\Feature\Wm;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SystemX\Core\State\StateKey;
use SystemX\Core\Wm\OpenWindow;
use SystemX\Core\Wm\OpenWindowService;
use Tests\TestCase;

// Plan 5e, Task 4 (D4/S3): the boot stamp. The `/` route carries each open window's saved
// geometry (forPrincipal, Task 1); the blade STAMPS it onto the .sx-window-surface as the
// exact data-sx-* attributes the WM adopt() reads. A window with saved geometry gets the full
// stamp (position + size + sized + flags + z); a maximised window stamps the RESTORE rect (NOT
// the maximised fill) + the flag; a null-geometry window stamps NOTHING extra (-> cascade).
class ShellGeometryStampTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_saved_geometry_window_stamps_the_exact_data_attributes_the_wm_reads(): void
    {
        $user = User::factory()->create();
        $principal = new StateKey('user', (string) $user->id, '');
        // hello has saved geometry (resized, raised); notes stays null (never positioned).
        $service = app(OpenWindowService::class);
        $service->seedDefaults($principal);
        $service->saveGeometry($principal, 'hello', [
            'x' => 220, 'y' => 140, 'w' => 500, 'h' => 360,
            'sized' => true, 'maximised' => false, 'minimised' => false, 'z' => 3,
        ]);

        $response = $this->actingAs($user)->get('/')->assertOk();

        // The saved-geometry surface (hello) stamps position + z + the sized dims + the flag.
        $response->assertSee('data-window-id="hello"', false);
        $response->assertSee('data-sx-x="220"', false);
        $response->assertSee('data-sx-y="140"', false);
        $response->assertSee('data-sx-z="3"', false);
        $response->assertSee('data-sx-sized="true"', false);
        $response->assertSee('width:500px', false);
        $response->assertSee('height:360px', false);

        // notes has NULL geometry -> NO stamped position attribute on its surface.
        $html = $response->getContent();
        $this->assertStringContainsString('data-window-id="notes"', $html);
        // The notes surface carries no data-sx-x (it cascades). Assert by isolating its tag.
        $notesTag = $this->surfaceTag($html, 'notes');
        $this->assertStringNotContainsString('data-sx-x', $notesTag);
        $this->assertStringNotContainsString('data-sx-sized', $notesTag);
    }

    public function test_a_maximised_window_stamps_the_restore_rect_plus_the_flag_not_the_fill(): void
    {
        $user = User::factory()->create();
        $principal = new StateKey('user', (string) $user->id, '');
        $service = app(OpenWindowService::class);
        $service->seedDefaults($principal);
        // A maximised window persists its un-maximised RESTORE rect + the flag.
        $service->saveGeometry($principal, 'hello', [
            'x' => 120, 'y' => 90, 'w' => 600, 'h' => 400,
            'sized' => true, 'maximised' => true, 'minimised' => false, 'z' => 2,
        ]);

        $response = $this->actingAs($user)->get('/')->assertOk();
        $tag = $this->surfaceTag($response->getContent(), 'hello');

        // The RESTORE rect is stamped (NOT the work-area fill) + the max flag.
        $this->assertStringContainsString('data-sx-x="120"', $tag);
        $this->assertStringContainsString('data-sx-y="90"', $tag);
        $this->assertStringContainsString('width:600px', $tag);
        $this->assertStringContainsString('height:400px', $tag);
        $this->assertStringContainsString('data-sx-max="true"', $tag);
        $this->assertStringNotContainsString('data-sx-min', $tag);
    }

    public function test_a_minimised_window_stamps_the_min_flag(): void
    {
        $user = User::factory()->create();
        $principal = new StateKey('user', (string) $user->id, '');
        $service = app(OpenWindowService::class);
        $service->seedDefaults($principal);
        $service->saveGeometry($principal, 'notes', [
            'x' => 50, 'y' => 60, 'sized' => false, 'maximised' => false, 'minimised' => true, 'z' => 1,
        ]);

        $response = $this->actingAs($user)->get('/')->assertOk();
        $tag = $this->surfaceTag($response->getContent(), 'notes');

        $this->assertStringContainsString('data-sx-min="true"', $tag);
        // Un-sized -> no width/height style, no data-sx-sized.
        $this->assertStringNotContainsString('data-sx-sized', $tag);
        $this->assertStringNotContainsString('width:', $tag);
    }

    public function test_persisted_geometry_round_trips_onto_the_row(): void
    {
        // A guardrail: the stamp is fed by the actual stored row (proves the route reads geometry).
        $user = User::factory()->create();
        $principal = new StateKey('user', (string) $user->id, '');
        $service = app(OpenWindowService::class);
        $service->seedDefaults($principal);
        $service->saveGeometry($principal, 'hello', [
            'x' => 7, 'y' => 8, 'w' => 300, 'h' => 200, 'sized' => true, 'z' => 4,
        ]);

        $row = OpenWindow::query()
            ->where('principal_id', (string) $user->id)
            ->where('window_id', 'hello')
            ->first();

        $this->assertSame(7, $row->x);
        $this->assertSame(300, $row->w);
        $this->assertTrue($row->sized);
    }

    // Isolate a single .sx-window-surface opening tag from the rendered HTML by its window id,
    // so an assertion about ONE surface's attributes isn't fooled by a sibling surface.
    private function surfaceTag(string $html, string $windowId): string
    {
        $needle = 'data-window-id="'.$windowId.'"';
        $pos = strpos($html, $needle);
        $this->assertNotFalse($pos, "surface for {$windowId} not found");
        // Walk back to the opening < of this div, forward to its closing >.
        $start = strrpos(substr($html, 0, $pos), '<');
        $end = strpos($html, '>', $pos);

        return substr($html, $start, $end - $start + 1);
    }
}
