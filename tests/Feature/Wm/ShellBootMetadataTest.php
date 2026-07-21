<?php

namespace Tests\Feature\Wm;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SystemX\Core\Apps\Installs\AppInstallService;
use SystemX\Core\Runtime\App;
use SystemX\Core\Runtime\AppRegistry;
use SystemX\Core\State\StateKey;
use SystemX\Core\Wire\Node;
use SystemX\Core\Wm\OpenWindow;
use Tests\TestCase;

class ShellBootMetadataTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_malicious_app_title_cannot_break_out_of_the_boot_script_blob(): void
    {
        // system-x is open-core: a third-party app declares its OWN title(). A hostile (or
        // careless) app returning a title carrying </script> would break out of the inline
        // <script id="sx-boot"> at the HTML-parser level -- before any JSON.parse -- and
        // inject markup. The boot json_encode must hex-escape <, >, &, ', " so the literal
        // </script> can NEVER appear inside the blob, while the client JSON.parse still
        // decodes the escapes back to the exact title.
        $user = User::factory()->create();

        // Register a malicious-title app on the live registry the shell reads (D2: title()
        // is App-declared, so this is exactly how a third-party app would land its title in
        // the blob) and open a window for it so the title rides both maps.
        $this->app->make(AppRegistry::class)->register('evil', EvilTitleApp::class);
        OpenWindow::query()->create([
            'principal_type' => 'user',
            'principal_id' => (string) $user->id,
            'window_id' => 'evil',
            'app' => 'evil',
        ]);

        $html = $this->actingAs($user)->get('/')->assertOk()->getContent();

        $blob = $this->bootBlobRaw($html);

        // The breakout is closed: no literal </script> survives in the embedded JSON, and
        // no raw < at all -- the HTML parser can never see a tag-open inside the blob.
        $this->assertStringNotContainsString('</script>', $blob);
        $this->assertStringNotContainsString('<', $blob);
        // The title IS in there, just hex-escaped (proof it wasn't simply dropped) --
        // the < of <script> becomes the JSON hex escape \u003C.
        $this->assertStringContainsString('\u003Cscript\u003Ealert(1)', $blob);

        // Zero behaviour change: the blob still parses and carries the EXACT title back
        // (JSON.parse decodes the < escapes transparently).
        $boot = $this->parseBoot($html);
        $evil = collect($boot['apps'])->firstWhere('slug', 'evil');
        $this->assertNotNull($evil);
        $this->assertSame('</script><script>alert(1)</script>', $evil['title']);
    }

    public function test_the_shell_boot_payload_carries_each_open_windows_title_and_icon(): void
    {
        $user = User::factory()->create();

        $html = $this->actingAs($user)->get('/')->assertOk()->getContent();

        // The panel labels each open window with its app's title + icon (D2/D3). They ride
        // to the client via the boot payload (the <script id="sx-boot"> JSON blob, Step 5).
        // Assert against the STRUCTURED shape, not a bare 'Hello' substring -- a bare match
        // can pass spuriously off some app's content text or fail to prove the embedding,
        // and the window trees self-hydrate (they aren't server-rendered into the blade), so
        // there's no surface markup carrying the title to lean on (S4). Pin the JSON shape
        // Step 5 decides (here: the title key in the boot blob).
        $boot = $this->parseBoot($html);

        $windows = collect($boot['windows']);
        $hello = $windows->firstWhere('app', 'hello');
        $notes = $windows->firstWhere('app', 'notes');

        $this->assertNotNull($hello);
        $this->assertNotNull($notes);
        $this->assertSame('Hello', $hello['title']);
        $this->assertSame('window', $hello['icon']);
        $this->assertSame('Notes', $notes['title']);
        $this->assertSame('notes', $notes['icon']);
        $this->assertSame('hello', $hello['window']);

        // The launcher's app grid rides in the same blob (the registry metadata).
        $apps = collect($boot['apps']);
        $this->assertEqualsCanonicalizing(['hello', 'notes', 'controls', 'appearance', 'about', 'apps', 'audit', 'example.todo', 'sxpro.demo'], $apps->pluck('slug')->all());

        // Belt-and-braces: the concrete JSON substring is present too (S4).
        $this->assertStringContainsString('"title":"Hello"', $html);
        $this->assertStringContainsString('"title":"Notes"', $html);
    }

    public function test_the_boot_payload_carries_the_system_flag_on_each_app(): void
    {
        // The system flag (plan system-menu, D1) rides to the client in the same blob the
        // launcher reads, so the shell can split user apps (the launcher grid) from system
        // apps (the user-icon menu). Appearance + About + Manage-apps are system; hello + notes are not.
        $user = User::factory()->create();

        $html = $this->actingAs($user)->get('/')->assertOk()->getContent();

        $apps = collect($this->parseBoot($html)['apps'])->keyBy('slug');

        $this->assertFalse($apps['hello']['system']);
        $this->assertFalse($apps['notes']['system']);
        $this->assertTrue($apps['appearance']['system']);
        $this->assertTrue($apps['about']['system']);
        $this->assertTrue($apps['apps']['system']);
    }

    public function test_the_boot_payload_carries_the_users_name(): void
    {
        // The user-icon menu greets the user by name + shows their initials on the tray
        // button (plan system-menu, D5). The name is NOT JS-readable any other way (it lives
        // in the encrypted httpOnly remember-cookie), so it must ride the boot blob.
        $user = User::factory()->create(['name' => 'Demo User']);

        $html = $this->actingAs($user)->get('/')->assertOk()->getContent();

        $boot = $this->parseBoot($html);

        $this->assertSame('Demo User', $boot['user']['name']);
    }

    public function test_a_malicious_user_name_cannot_break_out_of_the_boot_script_blob(): void
    {
        // The name rides the SAME hardened json_encode as the apps (D5/S5) -- a user whose
        // name carries </script> must NOT break out of the inline <script id="sx-boot"> at
        // the HTML-parser level. Mirrors the evil-app-title breakout test for the name field.
        $user = User::factory()->create(['name' => '</script><script>alert(1)</script>']);

        $html = $this->actingAs($user)->get('/')->assertOk()->getContent();

        $blob = $this->bootBlobRaw($html);

        // The breakout is closed: no literal </script> survives in the embedded JSON, and no
        // raw < at all -- the HTML parser can never see a tag-open inside the blob.
        $this->assertStringNotContainsString('</script>', $blob);
        $this->assertStringNotContainsString('<', $blob);
        // The name IS in there, just hex-escaped (proof it wasn't simply dropped) -- the < of
        // <script> becomes the JSON hex escape <.
        $this->assertStringContainsString('\u003Cscript\u003Ealert(1)', $blob);

        // Zero behaviour change: the blob still parses and carries the EXACT name back.
        $boot = $this->parseBoot($html);
        $this->assertSame('</script><script>alert(1)</script>', $boot['user']['name']);
    }

    public function test_an_uninstalled_user_app_is_filtered_out_of_the_boot_apps(): void
    {
        // The per-user boot filter (App-install plan, D2): the launcher's source is the boot
        // 'apps' blob, filtered to system || !uninstalled. With hello uninstalled for this user
        // the blob EXCLUDES hello but keeps notes + the system apps (appearance/about). The
        // filter is route-level only -- metadata() (the registry) is untouched.
        $user = User::factory()->create();
        $this->app->make(AppInstallService::class)->uninstall(
            new StateKey('user', (string) $user->id, ''),
            'hello'
        );

        $html = $this->actingAs($user)->get('/')->assertOk()->getContent();

        $apps = collect($this->parseBoot($html)['apps'])->pluck('slug')->all();

        $this->assertNotContains('hello', $apps);
        $this->assertEqualsCanonicalizing(['notes', 'controls', 'appearance', 'about', 'apps', 'audit', 'example.todo', 'sxpro.demo'], $apps);
    }

    public function test_a_system_app_is_never_filtered_out_of_the_boot_apps(): void
    {
        // The system short-circuit (D2/D6): even if a system app is somehow marked uninstalled
        // (the endpoints 403 that, but the service is agnostic), $a['system'] short-circuits the
        // filter -- appearance STILL rides the boot blob. System furniture is never uninstallable.
        $user = User::factory()->create();
        $this->app->make(AppInstallService::class)->uninstall(
            new StateKey('user', (string) $user->id, ''),
            'appearance'
        );

        $html = $this->actingAs($user)->get('/')->assertOk()->getContent();

        $apps = collect($this->parseBoot($html)['apps'])->pluck('slug')->all();

        $this->assertContains('appearance', $apps);
        $this->assertEqualsCanonicalizing(['hello', 'notes', 'controls', 'appearance', 'about', 'apps', 'audit', 'example.todo', 'sxpro.demo'], $apps);
    }

    public function test_a_fresh_user_with_nothing_uninstalled_gets_every_app_in_the_boot(): void
    {
        // The subtractive default (D1): a brand-new user has ZERO uninstalled rows, so the boot
        // 'apps' carries every registered app -- the launcher shows everything. (The existing
        // boot/metadata assertions lean on this; here it's pinned explicitly for the filter.)
        $user = User::factory()->create();

        $html = $this->actingAs($user)->get('/')->assertOk()->getContent();

        $apps = collect($this->parseBoot($html)['apps'])->pluck('slug')->all();

        $this->assertEqualsCanonicalizing(['hello', 'notes', 'controls', 'appearance', 'about', 'apps', 'audit', 'example.todo', 'sxpro.demo'], $apps);
    }

    public function test_the_boot_layout_excludes_system_apps(): void
    {
        // The launcher grid is a USER-app arrangement (plan system-menu, D2): system furniture
        // (Appearance/About/Manage-apps) lives in the user-icon menu, never the grid. The client
        // renders the grid straight from the reconciled `layout` blob now (Slice 4a), so the
        // server MUST keep system apps out of that layout -- otherwise a fresh user (whose layout
        // is reconcile-appended from the live set) would get a system tile the client can't filter.
        // The `apps` blob still carries the system apps (labels/flags) -- only the layout omits them.
        $user = User::factory()->create();

        $html = $this->actingAs($user)->get('/')->assertOk()->getContent();

        $boot = $this->parseBoot($html);
        $layoutSlugs = collect($boot['layout'])
            ->where('type', 'app')
            ->pluck('slug')
            ->all();

        // The user apps ARE in the layout...
        $this->assertContains('hello', $layoutSlugs);
        $this->assertContains('notes', $layoutSlugs);

        // ...and NONE of the system apps leak into it.
        $this->assertNotContains('appearance', $layoutSlugs);
        $this->assertNotContains('about', $layoutSlugs);
        $this->assertNotContains('apps', $layoutSlugs);
        $this->assertNotContains('audit', $layoutSlugs);

        // The `apps` blob still keeps the system apps (byApp labels + the client's !system flag).
        $this->assertContains('appearance', collect($boot['apps'])->pluck('slug')->all());
    }

    public function test_an_uninstalled_apps_open_window_keeps_its_label_join(): void
    {
        // The landmine (D2): the per-window title/icon join must keep using the FULL meta, NOT
        // the filtered launcher set -- an OPEN window of an uninstalled app still needs its label.
        // Uninstall hello but leave an open hello window: the boot 'windows' entry must still
        // carry hello's real title/icon (the join reads $meta, not the filtered 'apps').
        $user = User::factory()->create();
        $this->app->make(AppInstallService::class)->uninstall(
            new StateKey('user', (string) $user->id, ''),
            'hello'
        );

        $html = $this->actingAs($user)->get('/')->assertOk()->getContent();

        $boot = $this->parseBoot($html);

        // hello is gone from the launcher set...
        $this->assertNotContains('hello', collect($boot['apps'])->pluck('slug')->all());

        // ...but its open window (seeded on first boot) still labels correctly off the full meta.
        $hello = collect($boot['windows'])->firstWhere('app', 'hello');
        $this->assertNotNull($hello);
        $this->assertSame('Hello', $hello['title']);
        $this->assertSame('window', $hello['icon']);
    }

    public function test_launch_returns_the_apps_title_and_icon(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/system-x/wm/launch', ['app' => 'notes'])
            ->assertOk()
            ->assertJsonStructure(['app', 'window', 'title', 'icon', 'tree'])
            ->assertJson(['title' => 'Notes', 'icon' => 'notes']);
    }

    /**
     * Parse the boot JSON blob the display server reads at boot (the <script id="sx-boot">).
     *
     * @return array{windows: array<int, array<string, mixed>>, apps: array<int, array<string, mixed>>}
     */
    private function parseBoot(string $html): array
    {
        $this->assertMatchesRegularExpression(
            '/<script[^>]*id="sx-boot"[^>]*>(.*?)<\/script>/s',
            $html,
            'The shell must embed a <script id="sx-boot"> boot blob.'
        );

        preg_match('/<script[^>]*id="sx-boot"[^>]*>(.*?)<\/script>/s', $html, $m);

        return json_decode(html_entity_decode($m[1]), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * The RAW inner text of the boot blob, exactly as it sits in the HTML (no entity decode,
     * no JSON parse) -- so a breakout test can assert on the literal bytes the HTML parser sees.
     */
    private function bootBlobRaw(string $html): string
    {
        preg_match('/<script[^>]*id="sx-boot"[^>]*>(.*?)<\/script>/s', $html, $m);

        return $m[1] ?? '';
    }
}

// A test-only app whose title() breaks out of the inline <script> if it isn't hex-escaped.
// Mirrors how a third-party open-core app declares its own metadata (D2).
class EvilTitleApp extends App
{
    public function slug(): string
    {
        return 'evil';
    }

    public function title(): string
    {
        return '</script><script>alert(1)</script>';
    }

    public function render(): Node
    {
        return new Node('window');
    }
}
