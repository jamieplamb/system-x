<?php

namespace SystemX\Core\Support;

// Content-hash helper for the committed dist/ bundle AND for third-party vendor bundles
// (4c-ii). The hash is computed ONCE per (dir, file) and memoised -- sha1_file() touches the
// filesystem; we don't want that on every request. Used by AssetController to validate the hash
// segment in the URL and by Blade directives to build the cache-busted URL. The memo is keyed by
// "$dir/$file" (NOT the bare filename) so a core file and a vendor file that share a name never
// collide on the memo entry.
class Assets
{
    /** @var array<string, string> "$dir/$file" -> 8-char sha1 prefix */
    private array $hashes = [];

    public function distPath(): string
    {
        return dirname(__DIR__, 2).'/dist';
    }

    // Absolute path to a dist file in the CORE dist dir. Does NOT check existence.
    public function path(string $file): string
    {
        return $this->pathIn($this->distPath(), $file);
    }

    // Absolute path to a file in an arbitrary dir (a vendor's dist). Does NOT check existence.
    public function pathIn(string $dir, string $file): string
    {
        return $dir.'/'.$file;
    }

    // 8-char content hash for a CORE dist file.
    public function hash(string $file): string
    {
        return $this->hashIn($this->distPath(), $file);
    }

    // 8-char content hash for a file in an arbitrary dir. Memoised per (dir, file): sha1_file
    // runs at most once per (dir, file) per process lifetime. The dir-qualified key is what keeps
    // a vendor style.css from serving a core style.css's hash (and vice versa).
    public function hashIn(string $dir, string $file): string
    {
        $key = $dir.'/'.$file;

        if (! isset($this->hashes[$key])) {
            $hash = @sha1_file($this->pathIn($dir, $file));

            if ($hash === false) {
                throw new \RuntimeException("system-x dist asset not found: {$key}");
            }

            $this->hashes[$key] = substr($hash, 0, 8);
        }

        return $this->hashes[$key];
    }

    public function styleTag(): string
    {
        $hash = $this->hash('system-x.css');

        return '<link rel="stylesheet" href="/system-x/assets/system-x.'.$hash.'.css">';
    }

    public function scriptTag(): string
    {
        return $this->scriptTagFor('system-x.js');
    }

    public function greeterScriptTag(): string
    {
        return $this->scriptTagFor('greeter.js');
    }

    private function scriptTagFor(string $distFile): string
    {
        $hash = $this->hash($distFile);
        $name = pathinfo($distFile, PATHINFO_FILENAME);
        $ext = pathinfo($distFile, PATHINFO_EXTENSION);

        return '<script src="/system-x/assets/'.$name.'.'.$hash.'.'.$ext.'" defer></script>';
    }

    // Vendor client bundles (4c-ii). Emits ONE hashed <link> per registered bundle that has a css,
    // in registration order. Placed AFTER @systemxStyles in the head so vendor CSS can override core.
    public function vendorStyleTags(AssetRegistry $registry): string
    {
        $out = '';
        foreach ($registry->all() as $namespace => $bundle) {
            if ($bundle['css'] === null) {
                continue;
            }
            $url = $this->vendorUrl($namespace, $bundle['css'], $this->hashIn($bundle['dir'], $bundle['css']));
            $out .= '<link rel="stylesheet" href="'.$url.'">';
        }

        return $out;
    }

    // Vendor client bundles (4c-ii). Emits ONE hashed <script defer> per registered bundle that has
    // a js. LOAD-BEARING: the directive that echoes this sits AFTER @systemxScripts, so the vendor
    // script runs in the defer window after the core bundle exposes window.SystemX.renderers and
    // before boot()'s first reconcile(). `defer` is enforced here (never async -- async would break
    // source order and race the first render).
    public function vendorScriptTags(AssetRegistry $registry): string
    {
        $out = '';
        foreach ($registry->all() as $namespace => $bundle) {
            if ($bundle['js'] === null) {
                continue;
            }
            $url = $this->vendorUrl($namespace, $bundle['js'], $this->hashIn($bundle['dir'], $bundle['js']));
            $out .= '<script src="'.$url.'" defer></script>';
        }

        return $out;
    }

    private function vendorUrl(string $namespace, string $file, string $hash): string
    {
        $name = pathinfo($file, PATHINFO_FILENAME);
        $ext = pathinfo($file, PATHINFO_EXTENSION);

        return '/system-x/vendor/'.$namespace.'/'.$name.'.'.$hash.'.'.$ext;
    }
}
