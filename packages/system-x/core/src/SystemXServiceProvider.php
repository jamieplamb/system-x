<?php

namespace SystemX\Core;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use SystemX\Core\Apps\AboutApp;
use SystemX\Core\Apps\AppearanceApp;
use SystemX\Core\Apps\AuditApp;
use SystemX\Core\Apps\Installs\AppInstallService;
use SystemX\Core\Apps\ManageAppsApp;
use SystemX\Core\Audit\AuditRedactor;
use SystemX\Core\Audit\NullAuditRedactor;
use SystemX\Core\Console\DoctorCommand;
use SystemX\Core\Console\MakeAppCommand;
use SystemX\Core\Console\PruneAuditCommand;
use SystemX\Core\Console\PruneWindowStateCommand;
use SystemX\Core\Console\PushDesktopCommand;
use SystemX\Core\Demo\ControlsApp;
use SystemX\Core\Demo\HelloApp;
use SystemX\Core\Demo\NotesApp;
use SystemX\Core\Launcher\LauncherLayoutService;
use SystemX\Core\Preferences\PreferencesService;
use SystemX\Core\Runtime\AppKernel;
use SystemX\Core\Runtime\AppRegistry;
use SystemX\Core\State\DatabaseStateStore;
use SystemX\Core\State\StatePrincipalResolver;
use SystemX\Core\State\StateStore;
use SystemX\Core\Support\AssetRegistry;
use SystemX\Core\Widgets\Badge;
use SystemX\Core\Widgets\Box;
use SystemX\Core\Widgets\Button;
use SystemX\Core\Widgets\Checkbox;
use SystemX\Core\Widgets\Dialog;
use SystemX\Core\Widgets\Grid;
use SystemX\Core\Widgets\GroupBox;
use SystemX\Core\Widgets\Label;
use SystemX\Core\Widgets\ListItem;
use SystemX\Core\Widgets\ListWidget;
use SystemX\Core\Widgets\MenuBar;
use SystemX\Core\Widgets\MenuButton;
use SystemX\Core\Widgets\ProgressBar;
use SystemX\Core\Widgets\RadioGroup;
use SystemX\Core\Widgets\Raw;
use SystemX\Core\Widgets\Select;
use SystemX\Core\Widgets\Separator;
use SystemX\Core\Widgets\Slider;
use SystemX\Core\Widgets\Stack;
use SystemX\Core\Widgets\SwitchWidget;
use SystemX\Core\Widgets\Tabs;
use SystemX\Core\Widgets\TextField;
use SystemX\Core\Widgets\Toolbar;
use SystemX\Core\Widgets\Tooltip;
use SystemX\Core\Widgets\Window;
use SystemX\Core\Wire\WidgetRegistry;
use SystemX\Core\Wm\OpenWindowService;

class SystemXServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The package config (packaging plan, Task 9). Merged so config('system-x.logout_url')
        // resolves even when the consumer hasn't published it -- ClientConfig reads that key.
        $this->mergeConfigFrom(__DIR__.'/../config/system-x.php', 'system-x');

        $this->app->singleton(WidgetRegistry::class, function (): WidgetRegistry {
            $registry = new WidgetRegistry;

            // Core widgets self-register through the SAME call a pro pack uses --
            // the seam is non-privileged.
            $registry->register('badge', Badge::class);
            $registry->register('groupbox', GroupBox::class);
            $registry->register('progressbar', ProgressBar::class);
            $registry->register('separator', Separator::class);
            $registry->register('window', Window::class);
            $registry->register('label', Label::class);
            $registry->register('button', Button::class);
            $registry->register('stack', Stack::class);
            $registry->register('textfield', TextField::class);
            $registry->register('list', ListWidget::class);
            $registry->register('listitem', ListItem::class);
            $registry->register('checkbox', Checkbox::class);
            $registry->register('switch', SwitchWidget::class);
            $registry->register('select', Select::class);
            $registry->register('radiogroup', RadioGroup::class);
            $registry->register('slider', Slider::class);
            $registry->register('tabs', Tabs::class);
            $registry->register('toolbar', Toolbar::class);
            $registry->register('dialog', Dialog::class);
            $registry->register('menu', MenuButton::class);
            $registry->register('menubar', MenuBar::class);
            $registry->register('tooltip', Tooltip::class);
            $registry->register('raw', Raw::class);
            $registry->register('box', Box::class);
            $registry->register('grid', Grid::class);

            return $registry;
        });

        $this->app->singleton(AppRegistry::class, function (): AppRegistry {
            $registry = new AppRegistry;

            // Core demo apps self-register through the SAME call a pro pack uses.
            $registry->register('hello', HelloApp::class);
            $registry->register('notes', NotesApp::class);

            // The Controls gallery (widget-plan Task 9) -- a USER app showcasing the
            // eight form/display widgets, with its four inputs wired to durable state.
            $registry->register('controls', ControlsApp::class);

            // The Appearance app (Plan 5b-2, D5) -- a real framework app, so the D2 metadata
            // seam gives it a launcher tile + panel button for free.
            $registry->register('appearance', AppearanceApp::class);

            // The About app (Plan 5b-2, D7) -- a trivial static dogfood; completes the launcher
            // grid + gives the desktop context menu's "About" a real target.
            $registry->register('about', AboutApp::class);

            // The Manage-apps app (App-install plan, D4) -- a SYSTEM app; auto-appears in the
            // user-icon menu (the menu lists system apps), lists the USER apps with install/
            // uninstall toggles, is itself never uninstallable.
            $registry->register('apps', ManageAppsApp::class);

            // The Audit app (audit plan §7) -- a SYSTEM app; auto-appears in the user-icon menu,
            // renders a Raw shell the client fills from GET /system-x/audit.
            $registry->register('audit', AuditApp::class);

            return $registry;
        });

        // The runtime lifecycle (D4). Bound next to AppRegistry for clarity -- it composes
        // the registry + store + hydrator (it does NOT extend the store, D3).
        $this->app->singleton(AppKernel::class);

        // The durable state seam. A bare interface->class singleton: the swappable-
        // driver requirement is satisfied by the interface existing + the binding
        // being rebindable. A config-driven match() registry lands with the SECOND
        // driver (Redis/BrowserEcho), not now (D2).
        $this->app->singleton(StateStore::class, DatabaseStateStore::class);

        // The principal seam. Bound as a singleton so the controller DI-resolves it
        // instead of reading the principal off the request itself. It keys on the
        // authenticated user (4c) -- store, schema, and controller call sites stay
        // agnostic to how the principal is derived.
        $this->app->singleton(StatePrincipalResolver::class);

        // The per-user open-window SET (Plan 5a, D7). It owns which windows exist for a
        // user (the set), distinct from the App that renders into each. The controller
        // gates events on its membership and resolves a launched window's app from it.
        $this->app->singleton(OpenWindowService::class);

        // The per-USER preferences store (Plan 5b-2, D1) -- the durable look (theme/accent/
        // wallpaper/panel_position), keyed by the principal 2-tuple, separate from the state
        // bag + the open-set.
        $this->app->singleton(PreferencesService::class);

        // The SUBTRACTIVE per-user uninstalled-app set (App-install plan, D1) -- a row =
        // "this user uninstalled this app", keyed by the principal 2-tuple. The boot filter
        // shows registered-minus-uninstalled; a fresh user has nothing uninstalled.
        $this->app->singleton(AppInstallService::class);

        // The per-user LAUNCHER LAYOUT store (Plan 4a) -- the durable folder arrangement, keyed by
        // the principal 2-tuple, separate from the prefs bag + the uninstalled set.
        $this->app->singleton(LauncherLayoutService::class);

        // The audit redactor seam (audit plan §8) -- a no-op by default; a host rebinds it to scrub PII.
        $this->app->singleton(AuditRedactor::class, NullAuditRedactor::class);

        // The client-asset seam (4c-ii). A vendor package registers its widget's JS/CSS bundle
        // here (in its provider's boot()); the Blade directives emit it + AssetController serves
        // it. Empty by default -- core's own bundle goes through @systemxScripts, not this.
        $this->app->singleton(AssetRegistry::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        // The package owns the per-window state table (D3). Loading it here runs
        // it everywhere the provider is discovered: the host sqlite suite, the
        // package Testbench suite, and dev/Dusk MySQL -- no host-side copy needed.
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // The package's OWN views (packaging plan, Task 9): registers the system-x:: namespace
        // so Desktop::render() can resolve system-x::desktop / system-x::greeter -- the copied
        // shells that emit @systemxStyles/@systemxScripts + window.sxConfig.
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'system-x');

        // Broadcasting channel auth, package-owned (packaging plan, Task 9 + 13). Register
        // /broadcasting/auth only if the consumer hasn't already wired their own broadcasting --
        // so live updates work out of the box (the host relies on this) without stomping a
        // consumer who runs their own Broadcast::routes().
        //
        // We check by URI, NOT Route::has('broadcasting.auth'): Broadcast::routes() registers the
        // endpoint with a NULL route name in Laravel 13, so the name-based lookup is permanently
        // false (it would never skip). Do NOT "simplify" this back to Route::has. Re-requiring
        // channels.php to register the user.{id} callback is harmless (last wins).
        $authRouteExists = collect(Route::getRoutes()->getRoutes())->contains(
            fn ($route): bool => $route->uri() === 'broadcasting/auth'
        );

        if (! $authRouteExists) {
            Broadcast::routes();
        }
        require __DIR__.'/../routes/channels.php';

        if ($this->app->runningInConsole()) {
            $this->commands([
                PushDesktopCommand::class,
                PruneWindowStateCommand::class,
                PruneAuditCommand::class,
                MakeAppCommand::class,
                DoctorCommand::class,
            ]);

            // Publish groups (packaging hygiene, Task 9): a consumer can override the config,
            // fork the views, or self-serve the dist.
            $this->publishes([
                __DIR__.'/../config/system-x.php' => config_path('system-x.php'),
            ], 'system-x-config');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/system-x'),
            ], 'system-x-views');

            // OPTIONAL serve path: lets a consumer serve dist via their own webserver/CDN. Our
            // default is the AssetController route (Task 7) -- this is only for those who'd rather not.
            $this->publishes([
                __DIR__.'/../dist' => public_path('vendor/system-x'),
            ], 'system-x-assets');
        }

        Blade::directive('systemxStyles', fn () => "<?php echo app(\SystemX\Core\Support\Assets::class)->styleTag(); ?>");
        Blade::directive('systemxScripts', fn () => "<?php echo app(\SystemX\Core\Support\Assets::class)->scriptTag(); ?>");
        Blade::directive('systemxGreeterScripts', fn () => "<?php echo app(\SystemX\Core\Support\Assets::class)->greeterScriptTag(); ?>");
        Blade::directive('systemxVendorStyles', fn () => "<?php echo app(\SystemX\Core\Support\Assets::class)->vendorStyleTags(app(\SystemX\Core\Support\AssetRegistry::class)); ?>");
        Blade::directive('systemxVendorScripts', fn () => "<?php echo app(\SystemX\Core\Support\Assets::class)->vendorScriptTags(app(\SystemX\Core\Support\AssetRegistry::class)); ?>");
    }
}
