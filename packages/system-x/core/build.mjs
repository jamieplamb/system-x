import * as esbuild from 'esbuild';

// Build the self-contained, consumer-agnostic bundle (packaging spec §3). The JS entry is app.js
// (imports echo.js + display-server.js); laravel-echo + pusher-js are bundled IN so the dist needs
// nothing at the consumer's build time. No baked config -- echo.js/transport.js read window.sxConfig
// at runtime. CSS resolves the @import chain + minifies; the .woff2 file loader + publicPath emit the
// vendored fonts and rewrite the @font-face urls to the served asset path. Output committed to dist/.
await esbuild.build({
    entryPoints: { 'system-x': 'resources/js/app.js' },
    bundle: true, minify: true, format: 'iife', outdir: 'dist', sourcemap: false,
});
await esbuild.build({
    entryPoints: { 'greeter': 'resources/js/greeter.js' },
    bundle: true, minify: true, format: 'iife', outdir: 'dist',
});
await esbuild.build({
    entryPoints: { 'system-x': 'resources/css/system-x/system-x.css' },
    bundle: true, minify: true, loader: { '.woff2': 'file' },
    publicPath: '/system-x/assets', outdir: 'dist',
});
console.log('built dist/');
