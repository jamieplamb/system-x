<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class AuthJsonIgnoredTest extends TestCase
{
    // A leaked auth.json means leaked composer/anystack credentials. This just checks
    // git itself agrees the file is ignored -- it never creates one.
    public function test_auth_json_is_gitignored(): void
    {
        $root = dirname(__DIR__, 2);

        exec('cd '.escapeshellarg($root).' && git check-ignore auth.json', $out, $code);

        $this->assertSame(0, $code, 'auth.json must be gitignored');
    }
}
