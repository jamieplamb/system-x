<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class ProDistributionGuardTest extends TestCase
{
    // These guards travel into the PUBLIC monorepo snapshot, where the pro pack is stripped out.
    // Self-skip when the pack is absent so public CI stays green.
    protected function setUp(): void
    {
        if (! is_dir(dirname(__DIR__, 2).'/packages/system-x/pro-datagrid')) {
            $this->markTestSkipped('pro-datagrid not present (public snapshot)');
        }
    }

    private function proComposer(): array
    {
        $path = dirname(__DIR__, 2).'/packages/system-x/pro-datagrid/composer.json';

        return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_pro_pins_core_to_a_real_constraint_not_wildcard(): void
    {
        $constraint = $this->proComposer()['require']['system-x/core'] ?? null;
        $this->assertNotNull($constraint, 'pro must require system-x/core');
        $this->assertNotSame('*', $constraint, 'core must be pinned to a real constraint, not "*"');
        $this->assertStringStartsWith('^1.', $constraint);
    }

    public function test_committed_source_keeps_the_local_core_path_repo_for_dev(): void
    {
        $repos = $this->proComposer()['repositories'] ?? [];
        $paths = array_filter($repos, fn ($r) => ($r['type'] ?? null) === 'path' && ($r['url'] ?? null) === '../core');
        $this->assertNotEmpty($paths, 'committed composer.json keeps ../core path repo for local dev');
    }

    public function test_dist_assets_are_committed_and_non_empty(): void
    {
        $dir = dirname(__DIR__, 2).'/packages/system-x/pro-datagrid/dist';
        foreach (['datagrid.js', 'datagrid.css'] as $f) {
            $this->assertFileExists("$dir/$f");
            $this->assertGreaterThan(0, filesize("$dir/$f"), "$f must be non-empty");
        }
    }
}
