<?php

namespace SystemX\Core\Tests\Apps;

use PHPUnit\Framework\TestCase;
use SystemX\Core\Apps\AuditApp;
use SystemX\Core\Wire\Serializer;

class AuditAppTest extends TestCase
{
    public function test_is_a_system_app_with_slug_audit(): void
    {
        $app = new AuditApp;
        $this->assertSame('audit', $app->slug());
        $this->assertTrue($app->system());
    }

    public function test_renders_a_raw_mount_shell(): void
    {
        $json = json_encode((new Serializer)->serialize((new AuditApp)->renderInitial()));
        $this->assertStringContainsString('sx-audit', $json); // the [data-sx-audit] mount marker
    }
}
