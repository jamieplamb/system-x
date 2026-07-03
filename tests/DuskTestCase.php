<?php

namespace Tests;

use App\Models\User;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Dusk\Browser;
use Laravel\Dusk\TestCase as BaseTestCase;
use PHPUnit\Framework\Attributes\BeforeClass;

abstract class DuskTestCase extends BaseTestCase
{
    /**
     * Prepare for Dusk test execution.
     */
    #[BeforeClass]
    public static function prepare(): void
    {
        if (! static::runningInSail()) {
            static::startChromeDriver(['--port=9515']);
        }
    }

    /**
     * Give every browser test a known-empty durable state.
     *
     * The click count is now persisted in `system_x_window_states`, keyed by the
     * desktop/window cookies the browser session reuses across browse() calls.
     * The Dusk suite carries NO RefreshDatabase/DatabaseTruncation trait, so that
     * table is never reset between methods -- a count left behind by an earlier
     * test leaks into the next, so a method clicking once and asserting
     * "Clicked 1 times" would overshoot. Truncating here makes the empty-count
     * precondition a suite-wide invariant, exactly what durable state demands.
     *
     * It runs ONCE before each method (not between browse() calls inside one), so
     * it does not break across-refresh persistence that DurableStateTest proves.
     * Guarded so it is a no-op if migrations have not run yet.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (Schema::hasTable('system_x_window_states')) {
            DB::table('system_x_window_states')->truncate();
        }

        // B1: the open-window SET leaks across methods too (no RefreshDatabase here). Drop
        // it so the `/` shell's seedDefaults re-fires per method and every test starts from
        // the clean two-window (hello+notes) precondition -- otherwise a method that closed
        // `notes` would leave the next method's waitFor('[data-window-id="notes"]') hanging
        // (seedDefaults is a no-op once the user has ANY open window).
        if (Schema::hasTable('system_x_open_windows')) {
            DB::table('system_x_open_windows')->truncate();
        }

        // B1 (Plan 5b-2): the per-user prefs leak across methods too (no RefreshDatabase
        // here). Drop them so a test that flips a pref (theme/accent/wallpaper/panel) can't
        // poison a later method's no-flash-boot precondition -- every method starts from the
        // shipped default look (modern/blue/gradient/top).
        if (Schema::hasTable('system_x_preferences')) {
            DB::table('system_x_preferences')->truncate();
        }

        // App-install plan (Task 1 flagged it): the per-user uninstalled-apps SET leaks across
        // methods too (no RefreshDatabase here). Drop it so every method starts from a CLEAN user
        // with NOTHING uninstalled -- the AppInstallTest uninstalls hello/notes mid-run, and a
        // residual row would poison a later method's "the launcher shows both user apps" boot
        // precondition (the boot filter would silently drop a still-uninstalled app).
        if (Schema::hasTable('system_x_uninstalled_apps')) {
            DB::table('system_x_uninstalled_apps')->truncate();
        }

        // Launcher-folders plan (Slice 4a): the per-user launcher LAYOUT leaks across methods too
        // (no RefreshDatabase here). Drop it so every method starts from a fresh flat grid -- a
        // folder/reorder left behind by LauncherFoldersTest would otherwise poison a later method's
        // "the launcher shows a flat 4-tile grid" precondition (SystemMenuTest counts root tiles).
        if (Schema::hasTable('system_x_launcher_layout')) {
            DB::table('system_x_launcher_layout')->truncate();
        }

        // Audit plan (Task 11): activity + change rows from one method must not bleed into the
        // next -- the AuditTrailTest asserts on fresh rows, so stale rows from an earlier method
        // (e.g. a clicker click in DesignFoundationTest) would make any "first row is from this
        // test's interaction" assertion meaningless. Truncate both together (changes reference
        // activity by correlation_id, so clearing activity first is fine here -- no FK on the
        // column).
        if (Schema::hasTable('system_x_audit_activity')) {
            DB::table('system_x_audit_activity')->truncate();
        }

        if (Schema::hasTable('system_x_audit_changes')) {
            DB::table('system_x_audit_changes')->truncate();
        }

        // S3 (Plan 5c): the cosmetic remember-cookie (sx_last_user) ALSO leaks across methods --
        // once any test boots `/`, the desktop queues it onto the reused browser session, so the
        // next method's "fresh guest sees the blank brand state" assertion would be poisoned by a
        // stale greeting. It can't be cleared HERE (the Dusk browser doesn't exist until a
        // browse() call), so the greeter proof clears it at the top via $browser->deleteCookie()
        // before asserting the blank state -- the analogue of the prefs-table truncate above, but
        // for the one piece of state that lives in the browser, not the DB. loginAsDemoUser's
        // forceGuestThenLoginForm also deleteAllCookies(), so the login flow is unaffected.

        // Clear the login throttle between methods. EVERY browser test now logs in as
        // the same demo credential from the same IP, and the prod-shaped login throttle
        // (D8) allows only 5 attempts/minute on that key -- so the 6th method in a run
        // would hit a 429, never land on '/', and time out. Flushing the rate-limiter
        // cache here resets the per-method allowance WITHOUT weakening the real throttle
        // (it still guards the live surface; we only drop the hits the suite itself
        // accrues). Guarded so it is a no-op if the cache table has not been created.
        if (Schema::hasTable('cache')) {
            Cache::flush();
        }

        // The login fixture: a known demo user the browser logs in as. The Dusk suite
        // runs against the real MySQL dev DB with NO RefreshDatabase, so we seed it
        // here -- idempotent (firstOrCreate) so re-running a method never duplicates it,
        // and present before EVERY method so the login form always has a credential.
        // The model's 'hashed' password cast hashes the plain value on assignment.
        if (Schema::hasTable('users')) {
            User::query()->firstOrCreate(
                ['email' => 'demo@system-x.test'],
                ['name' => 'Demo User', 'password' => 'password'],
            );
        }
    }

    /**
     * Drive the REAL login form -> land on the authenticated desktop.
     *
     * Every browser test's flow now starts here: the desktop requires auth (D3), so
     * `/` bounces a guest to `/login`. A real FORM login (not a back-door actingAs)
     * proves the whole auth path end-to-end -- the same path a user walks. Returns the
     * browser parked on `/` so callers chain their existing desktop assertions on.
     *
     * The demo user resolved by id elsewhere (waitForChannel, system-x:push) is THIS
     * user -- always read its id off the live page (`#sx-desktop` data-desktop-id, now
     * the user id) or User::where('email', ...)->value('id'). NEVER hardcode an id: a
     * wrong channel id makes waitForChannel hang on a channel nobody publishes to.
     */
    protected function loginAsDemoUser(Browser $browser): Browser
    {
        // Land on the guaranteed-guest login form, no matter what the previous test in
        // this run left behind. Dusk reuses ONE browser session across every method in a
        // run, so a prior test routinely leaves an authenticated session cookie around --
        // and `/login` sits behind `guest` middleware, so visiting it while still logged
        // in 302s straight to `/`. That leaves the form (and its email field) off the
        // page, which is the intermittent "Unable to locate element {body email}" flake
        // that has dogged the suite. The fix is to FORCE a clean guest state and then
        // PROVE the form is actually on screen before touching it, retrying if not.
        $this->forceGuestThenLoginForm($browser);

        return $browser->type('email', 'demo@system-x.test')
            ->type('password', 'password')
            ->press('Sign in')
            ->waitForLocation('/');
    }

    /**
     * Log out via the user menu -> wait for `/login`.
     *
     * Logout moved into the tray user-menu dropdown (system-menu plan, D4): the
     * dusk="logout" hook is now a MENU ITEM inside a CLOSED `.sx-system-menu`. A
     * hidden item can't be pressed by Selenium (ElementNotInteractableException), so
     * EVERY logout path must OPEN the menu first. This is the one helper all four Dusk
     * callers route through -- click the user button, wait for the dropdown, press the
     * Log out item, land back on the guest login form.
     */
    protected function logoutViaMenu(Browser $browser): Browser
    {
        return $browser->click('.sx-panel-user')
            ->waitFor('.sx-system-menu')
            ->press('@logout')
            ->waitForLocation('/login');
    }

    /**
     * Drive the browser to a guest `/login` showing the email field -- robustly.
     *
     * `deleteAllCookies()` only clears cookies for the CURRENT document's origin, so it
     * is a no-op on a brand-new (about:blank) session and can silently miss the app's
     * session cookie if the browser is parked off-origin. So we first navigate onto the
     * app origin, THEN clear cookies + web storage, then visit `/login`. If the page
     * still redirected to `/` (a residual auth survived, or the clear raced the nav), we
     * tear the session down the real way -- POST `/logout` via the desktop control -- and
     * try once more. The loop's exit condition is the email field being genuinely
     * present, so callers can `type('email', ...)` without hitting the locate-element flake.
     */
    protected function forceGuestThenLoginForm(Browser $browser): void
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            // Get onto the app origin so the cookie/storage wipe targets the right domain,
            // then drop EVERYTHING that could carry a residual session: cookies + both
            // web-storage areas (a stray token in storage can re-auth on the next paint).
            $browser->visit('/login');
            $browser->driver->manage()->deleteAllCookies();
            $browser->script('try { window.localStorage.clear(); window.sessionStorage.clear(); } catch (e) {}');

            // Re-visit now that we are a clean guest. If the form renders, we are done.
            $browser->visit('/login');

            $onForm = $browser->script(
                "return !!document.querySelector('input[name=\"email\"]');"
            )[0];

            if ($onForm) {
                $browser->waitFor('input[name="email"]');

                return;
            }

            // Still bounced to `/` -- a session outlived the cookie wipe. End it the real
            // way (POST /logout through the desktop's logout control) and loop to retry.
            // Logout moved INTO the user menu (system-menu plan, D4): the dusk="logout" hook
            // now lives on a menu item inside a CLOSED dropdown, so Selenium can't press it
            // until the menu is open -- a bare press('@logout') on the hidden item throws
            // ElementNotInteractableException and breaks the WHOLE suite. So we open the menu
            // first via logoutViaMenu(), guarded on the user button actually being on screen.
            if ($browser->element('.sx-panel-user') !== null) {
                $this->logoutViaMenu($browser);
            }
        }

        // Last-ditch: assert the field so a genuine failure reports clearly rather than
        // failing later on a bare type().
        $browser->waitFor('input[name="email"]');
    }

    /**
     * Create the RemoteWebDriver instance.
     */
    protected function driver(): RemoteWebDriver
    {
        $options = (new ChromeOptions)->addArguments(collect([
            $this->shouldStartMaximized() ? '--start-maximized' : '--window-size=1920,1080',
            '--disable-search-engine-choice-screen',
            '--disable-smooth-scrolling',
        ])->unless($this->hasHeadlessDisabled(), function (Collection $items) {
            return $items->merge([
                '--disable-gpu',
                '--headless=new',
            ]);
        })->all());

        return RemoteWebDriver::create(
            $_ENV['DUSK_DRIVER_URL'] ?? env('DUSK_DRIVER_URL') ?? 'http://localhost:9515',
            DesiredCapabilities::chrome()->setCapability(
                ChromeOptions::CAPABILITY, $options
            )
        );
    }

    /**
     * Block until the desktop's private channel is actually subscribed.
     *
     * A pushed frame is fire-and-forget over Reverb with no replay, so pushing
     * before the listener is live drops the frame. Any test that reads the
     * desktop id and then `system-x:push`es a frame must wait on this first.
     * It is a synchronisation barrier (like waitFor('.sx-window')), NOT an
     * assertion -- it changes nothing the tests prove.
     */
    protected function waitForChannel(Browser $browser, string $desktopId): void
    {
        $browser->waitUntil(
            "window.Echo && window.Echo.connector.pusher.channel('private-user.{$desktopId}') && window.Echo.connector.pusher.channel('private-user.{$desktopId}').subscribed === true",
            10,
        );
    }
}
