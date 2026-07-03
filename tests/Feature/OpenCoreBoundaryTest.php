<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class OpenCoreBoundaryTest extends TestCase
{
    public function test_core_declares_no_pro_dependency(): void
    {
        $composer = json_decode(
            file_get_contents(base_path('packages/system-x/core/composer.json')),
            true,
        );

        $deps = array_merge(
            array_keys($composer['require'] ?? []),
            array_keys($composer['require-dev'] ?? []),
        );

        foreach ($deps as $dep) {
            $this->assertDoesNotMatchRegularExpression(
                '/pro/i',
                $dep,
                "Open-core boundary: core must not depend on a pro pack, found: {$dep}",
            );
        }
    }

    public function test_core_source_imports_no_pro_namespace(): void
    {
        $files = File::allFiles(base_path('packages/system-x/core/src'));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $this->assertDoesNotMatchRegularExpression(
                '/use\s+SystemX\\\\[A-Za-z0-9_\\\\]*Pro\\\\/',
                $file->getContents(),
                "Open-core boundary: core src must not import a Pro namespace -- {$file->getRelativePathname()}",
            );
        }
    }
}
