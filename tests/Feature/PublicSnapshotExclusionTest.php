<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class PublicSnapshotExclusionTest extends TestCase
{
    public function test_publish_strips_every_proprietary_package(): void
    {
        $publish = dirname(__DIR__, 2).'/scripts/publish.sh';
        if (! is_file($publish)) {
            $this->markTestSkipped('scripts/ absent (public snapshot)');
        }
        $sh = file_get_contents($publish);
        foreach (['pro-datagrid', 'licensing'] as $pkg) {
            $this->assertMatchesRegularExpression(
                '#rm -rf .*packages/system-x/'.preg_quote($pkg, '#').'#',
                $sh,
                "publish.sh must strip packages/system-x/$pkg from the public snapshot"
            );
        }
    }
}
