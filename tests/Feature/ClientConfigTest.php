<?php

namespace Tests\Feature;

use SystemX\Core\Support\ClientConfig;
use Tests\TestCase;

class ClientConfigTest extends TestCase
{
    public function test_it_builds_the_sxconfig_with_baseurl_csrf_reverb_and_logout(): void
    {
        config(['broadcasting.connections.reverb.key' => 'app-key-1']);
        config(['broadcasting.connections.reverb.options.host' => 'ws.example.test']);
        config(['broadcasting.connections.reverb.options.port' => 6001]);
        config(['broadcasting.connections.reverb.options.scheme' => 'https']);

        $request = request();
        $session = session()->driver();
        $session->regenerateToken();
        $request->setLaravelSession($session);

        $config = (new ClientConfig)->forRequest($request);

        $this->assertSame('', $config['baseUrl']);            // app at root -> '' prefix
        $this->assertNotEmpty($config['csrfToken']);
        $this->assertSame('/logout', $config['logoutUrl']);   // default; consumer-overridable
        $this->assertSame('app-key-1', $config['reverb']['key']);
        $this->assertSame('ws.example.test', $config['reverb']['host']);
        $this->assertSame(6001, $config['reverb']['port']);
        $this->assertSame('https', $config['reverb']['scheme']);
    }
}
