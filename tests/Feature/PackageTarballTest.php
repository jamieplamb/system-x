<?php

namespace Tests\Feature;

use Tests\TestCase;

// Proves the shape of the tarball Composer would publish for packages/system-x/core.
// We export-ignore the JS/CSS SOURCE + the build toolchain + tests in the package's
// .gitattributes, so this guards that the slimmed tarball STILL ships everything the
// runtime needs (the committed dist/ bundle, its fonts, the package views, src/, the
// composer.json) AND that the dead weight is genuinely gone.
//
// `git archive` applies .gitattributes export-ignore exactly as Composer's dist tarball
// does, and only sees COMMITTED files + a COMMITTED .gitattributes -- so this asserts the
// real published shape against HEAD.
class PackageTarballTest extends TestCase
{
    /** @return list<string> the file paths inside the published package tarball */
    private function tarballFiles(): array
    {
        $cmd = 'cd '.escapeshellarg(base_path()).' && git archive HEAD packages/system-x/core | tar -t 2>/dev/null';
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, 'git archive failed');

        return $out;
    }

    public function test_the_tarball_includes_everything_the_runtime_needs(): void
    {
        $files = $this->tarballFiles();
        $needle = fn (string $suffix): bool => (bool) collect($files)->first(fn ($f) => str_contains($f, $suffix));

        // The served bundle + its fonts (AssetController reads dist/ at runtime).
        $this->assertTrue($needle('packages/system-x/core/dist/system-x.js'), 'dist js missing from tarball');
        $this->assertTrue($needle('packages/system-x/core/dist/system-x.css'), 'dist css missing from tarball');
        $this->assertTrue((bool) collect($files)->first(fn ($f) => str_contains($f, 'packages/system-x/core/dist/') && str_ends_with($f, '.woff2')), 'dist fonts missing');
        // The package views (Desktop::render resolves system-x::desktop / greeter).
        $this->assertTrue($needle('packages/system-x/core/resources/views/desktop.blade.php'), 'desktop view missing');
        $this->assertTrue($needle('packages/system-x/core/resources/views/greeter.blade.php'), 'greeter view missing');
        // Source + config the package needs.
        $this->assertTrue($needle('packages/system-x/core/src/'), 'src missing');
        $this->assertTrue($needle('packages/system-x/core/composer.json'), 'composer.json missing');
    }

    public function test_the_tarball_excludes_the_source_and_dev_cruft(): void
    {
        $files = $this->tarballFiles();
        $absent = fn (string $suffix): bool => collect($files)->every(fn ($f) => ! str_contains($f, $suffix));

        // The JS/CSS SOURCE is export-ignored -- the consumer runs dist/, never source.
        $this->assertTrue($absent('packages/system-x/core/resources/js/'), 'resources/js source leaked into tarball');
        $this->assertTrue($absent('packages/system-x/core/resources/css/'), 'resources/css source leaked into tarball');
        $this->assertTrue($absent('packages/system-x/core/tests/'), 'tests leaked into tarball');
        $this->assertTrue($absent('packages/system-x/core/build.mjs'), 'build.mjs leaked into tarball');
        $this->assertTrue($absent('packages/system-x/core/package.json'), 'package.json leaked into tarball');
    }
}
