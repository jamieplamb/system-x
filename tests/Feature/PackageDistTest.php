<?php

namespace Tests\Feature;

use Tests\TestCase;

class PackageDistTest extends TestCase
{
    public function test_the_committed_dist_exists_and_looks_right(): void
    {
        $base = base_path('packages/system-x/core/dist');
        $this->assertFileExists("{$base}/system-x.js");
        $this->assertFileExists("{$base}/system-x.css");
        $this->assertStringContainsString('sx-window', file_get_contents("{$base}/system-x.css"));
        // the bundle inlines echo+pusher -> sizeable; a sanity floor, not a byte check
        $this->assertGreaterThan(50_000, filesize("{$base}/system-x.js"));
    }
}
