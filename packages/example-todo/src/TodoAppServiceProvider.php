<?php

namespace Example\TodoApp;

use Example\TodoApp\Widgets\Gauge;
use Illuminate\Support\ServiceProvider;
use SystemX\Core\Runtime\AppRegistry;
use SystemX\Core\Support\AssetRegistry;
use SystemX\Core\Wire\WidgetRegistry;

// A THIRD-PARTY package's provider. Laravel auto-discovers it (extra.laravel.providers), and this
// boot() lands the app + its custom widget + its client bundle in the shared singletons BEFORE any
// request is routed -- so `example.todo` (app) and `example.gauge` (a custom widget with its own
// JS/CSS) appear with ZERO changes to the host. This is the canonical shape a system-x app + widget
// pack ships. See the shipping-a-system-x-app skill.
//
// Registration happens in boot(), not register(): the core singletons are defined by the core
// provider, and provider register() order isn't dependency-ordered (this package is discovered
// before system-x/core alphabetically). Resolving a registry in register() would autowire a
// throwaway instance before core binds the real singleton. By boot() every provider has registered,
// so make() returns the shared singleton the host actually reads.
class TodoAppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(AppRegistry::class)->register('example.todo', TodoApp::class);

        // The custom widget's PHP half -- a new wire type, vendor-namespaced.
        $this->app->make(WidgetRegistry::class)->register('example.gauge', Gauge::class);

        // The custom widget's CLIENT half -- the hand-written renderer + CSS this package ships in
        // its own dist/. Keyed by the vendor id `example` (so doctor prefix-matches `example.gauge`).
        // The directives emit these as content-hashed tags after the core bundle; AssetController
        // serves them from this dir.
        $this->app->make(AssetRegistry::class)->register(
            'example',
            __DIR__.'/../dist',
            js: 'example-todo.js',
            css: 'example-todo.css',
        );
    }
}
