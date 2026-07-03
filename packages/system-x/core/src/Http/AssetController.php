<?php

namespace SystemX\Core\Http;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use SystemX\Core\Support\AssetRegistry;
use SystemX\Core\Support\Assets;

// Serves the committed dist/ bundle with immutable long-cache headers.
// URL scheme:
//   JS/CSS: /system-x/assets/{name}.{8-char-hash}.{ext}  -- hash validated against current content
//   Fonts:  /system-x/assets/{esbuild-hashed-name}.woff2  -- already hash-named by esbuild; just verify exists
//
// The route 'where' constraint allows only [A-Za-z0-9._-], so slashes can never appear in
// {file}. We also reject '..' just in case.
//
// JS/CSS use a StreamedResponse so we own the Content-Type header exactly (BinaryFileResponse
// would append '; charset=utf-8' during prepare()). Fonts use BinaryFileResponse -- woff2
// is a binary type so Symfony leaves the Content-Type alone.
class AssetController
{
    private const MIME = [
        'js' => 'text/javascript',
        'css' => 'text/css',
        'woff2' => 'font/woff2',
    ];

    private const CACHE = 'max-age=31536000, public, immutable';

    public function serve(Request $request, string $file, Assets $assets): BinaryFileResponse|StreamedResponse
    {
        // Belt-and-braces: route constraint blocks slashes, but reject '..' explicitly
        if (str_contains($file, '..')) {
            abort(404);
        }

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        if (! array_key_exists($ext, self::MIME)) {
            abort(404);
        }

        if ($ext === 'woff2') {
            // Fonts are already hash-named by esbuild -- just check they exist in dist/
            $path = $assets->path($file);

            if (! is_file($path)) {
                abort(404);
            }

            return response()->file($path, [
                'Content-Type' => self::MIME['woff2'],
                'Cache-Control' => self::CACHE,
            ]);
        }

        // JS / CSS: validate the hash segment against the CORE dist dir.
        return $this->serveHashed($assets->distPath(), $file, $assets);
    }

    // Serve a third-party vendor bundle's JS/CSS (4c-ii). The namespace resolves to the vendor's
    // registered dist dir via the AssetRegistry; the file is hash-validated + streamed exactly like
    // a core asset. Fonts are core-only (vendors register js/css), so there's no woff2 branch here.
    public function serveVendor(Request $request, string $namespace, string $file, Assets $assets, AssetRegistry $registry): StreamedResponse
    {
        if (str_contains($namespace, '..') || str_contains($file, '..')) {
            abort(404);
        }

        $bundle = $registry->get($namespace);

        if ($bundle === null) {
            abort(404);
        }

        return $this->serveHashed($bundle['dir'], $file, $assets);
    }

    // The shared JS/CSS validate-and-stream body. {name}.{hash}.{ext}: the hash segment is checked
    // against the current content in $dir; a stale hash 404s (never serve wrong content). The only
    // difference between core and vendor serving is which base dir is passed in.
    private function serveHashed(string $dir, string $file, Assets $assets): StreamedResponse
    {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        // Only hashed JS/CSS come through here (fonts are handled in serve()).
        if ($ext !== 'js' && $ext !== 'css') {
            abort(404);
        }

        $basename = pathinfo($file, PATHINFO_FILENAME); // "example-todo.a1b2c3d4"
        $lastDot = strrpos($basename, '.');

        if ($lastDot === false) {
            abort(404);
        }

        $name = substr($basename, 0, $lastDot);          // "example-todo"
        $requestedHash = substr($basename, $lastDot + 1); // "a1b2c3d4"

        $distFile = "{$name}.{$ext}";
        $path = $assets->pathIn($dir, $distFile);

        if (! is_file($path)) {
            abort(404);
        }

        // Hash mismatch -> stale URL -> 404.
        if ($requestedHash !== $assets->hashIn($dir, $distFile)) {
            abort(404);
        }

        return response()->stream(function () use ($path): void {
            readfile($path);
        }, 200, [
            'Content-Type' => self::MIME[$ext],
            'Cache-Control' => self::CACHE,
            'Content-Length' => (string) filesize($path),
        ]);
    }
}
