<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class ProSplitOutputTest extends TestCase
{
    public function test_scrub_strips_path_repo_and_version_and_keeps_core_constraint(): void
    {
        $root = dirname(__DIR__, 2);
        // Self-skip in the public snapshot (pro pack + scripts/ are stripped there).
        if (! is_file($root.'/scripts/lib/pro-split.sh') || ! is_file($root.'/packages/system-x/pro-datagrid/composer.json')) {
            $this->markTestSkipped('pro split artifacts not present (public snapshot)');
        }
        $src = $root.'/packages/system-x/pro-datagrid/composer.json';
        $tmp = tempnam(sys_get_temp_dir(), 'proc').'.json';
        copy($src, $tmp);

        // Read the constraint off the source rather than hardcoding it: the invariant under test is
        // that the scrub PRESERVES whatever core floor the pack declares, not that the floor is any
        // particular value (it moves whenever the pack needs a newer core).
        $expectedCore = json_decode(file_get_contents($src), true, flags: JSON_THROW_ON_ERROR)['require']['system-x/core'];

        // Run the scrub function from lib/pro-split.sh against the temp copy.
        exec('bash -c '.escapeshellarg(
            'source '.escapeshellarg($root.'/scripts/lib/pro-split.sh').
            ' && sx_pro_scrub_composer '.escapeshellarg($tmp)
        ), $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));

        $j = json_decode(file_get_contents($tmp), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('repositories', $j, 'split output must have no path repo');
        $this->assertArrayNotHasKey('version', $j, 'split output strips version (tag infers it)');
        $this->assertSame($expectedCore, $j['require']['system-x/core'], 'core constraint preserved');
        @unlink($tmp);
    }
}
