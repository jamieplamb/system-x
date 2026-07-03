<?php

namespace SystemX\Core\Runtime;

use Illuminate\Container\Container;
use InvalidArgumentException;

// The app-extension seam, mirroring Wire\WidgetRegistry. Maps an app SLUG to its App
// class; resolve() container-makes a FRESH App instance per call (the kernel hydrates
// it per event -- apps are never shared singletons). A pro pack registers its app the
// same way core does: register('vendor.app', VendorApp::class).
class AppRegistry
{
    /** @var array<string, class-string<App>> */
    private array $apps = [];

    /** @param class-string<App> $appClass */
    public function register(string $slug, string $appClass): void
    {
        if (isset($this->apps[$slug])) {
            throw new InvalidArgumentException("system-x: app slug \"{$slug}\" is already registered -- two providers registering the same slug collide. Namespace third-party slugs as vendor.name.");
        }

        $this->apps[$slug] = $appClass;
    }

    public function has(string $slug): bool
    {
        return isset($this->apps[$slug]);
    }

    public function resolve(string $slug): App
    {
        if (! isset($this->apps[$slug])) {
            throw new InvalidArgumentException("system-x: no app registered for slug \"{$slug}\".");
        }

        // Container-make so an app can constructor-inject services (which the hydrator
        // gate then keeps OUT of the durable bag, D2).
        return Container::getInstance()->make($this->apps[$slug]);
    }

    /** @return array<int, string> */
    public function slugs(): array
    {
        return array_keys($this->apps);
    }

    /**
     * App metadata for every registered app (Plan 5b, D2): the launcher's app grid + the
     * source the shell/launch join per-window labels from. Resolve each registered App ONCE
     * to read its declared title()/icon()/system() -- metadata is App-declared, NOT stored (no
     * schema, no column, the App IS the source). Keep this a READ: title()/icon()/system() are
     * pure, never a render or a state read. The system flag (plan system-menu, D1) lets the
     * shell split user apps (the launcher grid) from system apps (the user-icon menu).
     *
     * @return array<int, array{slug: string, title: string, icon: string, system: bool}>
     */
    public function metadata(): array
    {
        $meta = [];
        foreach (array_keys($this->apps) as $slug) {
            $app = $this->resolve($slug);
            $meta[] = [
                'slug' => $slug,
                'title' => $app->title(),
                'icon' => $app->icon(),
                'system' => $app->system(),
            ];
        }

        return $meta;
    }
}
