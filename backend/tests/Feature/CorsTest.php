<?php

namespace Tests\Feature;

use Tests\TestCase;

class CorsTest extends TestCase
{
    public function test_cors_headers_are_present(): void
    {
        $response = $this->withHeaders([
            'Origin' => 'http://localhost:4200',
        ])->getJson('/api/user');

        $response->assertHeader('Access-Control-Allow-Origin', 'http://localhost:4200');
    }

    public function test_cors_headers_on_edgar_feed(): void
    {
        $response = $this->withHeaders([
            'Origin' => 'http://localhost:4200',
        ])->getJson('/api/edgar-feed');

        $response->assertHeader('Access-Control-Allow-Origin', 'http://localhost:4200');
    }

    public function test_cors_headers_on_ws_config_from_cloud_run_frontend(): void
    {
        $response = $this->withHeaders([
            'Origin' => 'https://edgar-frontend-random123-uc.a.run.app',
        ])->getJson('/api/ws-config');

        $response->assertStatus(200);
        $response->assertHeader('Access-Control-Allow-Origin', 'https://edgar-frontend-random123-uc.a.run.app');
    }

    public function test_ws_config_returns_http_on_localhost(): void
    {
        $response = $this->withHeaders([
            'Host' => 'localhost',
        ])->getJson('/api/ws-config');

        $response->assertStatus(200);
        $response->assertJson([
            'scheme' => 'http',
            'useTLS' => false,
            'port' => 8080,
        ]);
    }

    public function test_ws_config_returns_https_for_cloud_run_host(): void
    {
        $response = $this->getJson('https://edgar-api-dynamichash99-uc.a.run.app/api/ws-config');

        $response->assertStatus(200);
        $response->assertJson([
            'host' => 'edgar-reverb-dynamichash99-uc.a.run.app',
            'scheme' => 'https',
            'useTLS' => true,
            'port' => 443,
        ]);
    }

    public function test_edgar_parsing(): void
    {
        $mockXml = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <entry>
    <id>urn:tag:sec.gov,2008:accession-number=0001493152-26-041638</id>
    <title>4 - Novick Jared (0002006392) (Reporting)</title>
    <updated>2026-09-04T21:56:31-04:00</updated>
    <link rel="alternate" type="text/html" href="https://www.sec.gov/Archives/edgar/data/2006392/000149315226041638/0001493152-26-041638-index.htm"/>
  </entry>
</feed>
XML;

        $xml = simplexml_load_string($mockXml);
        $entries = $xml->xpath('//*[local-name()="entry"]');
        $entry = $entries[0];
        $namespaces = $entry->getNamespaces(true);
        $atomNs = $namespaces[''] ?? ($namespaces['atom'] ?? null);
        $children = $atomNs ? $entry->children($atomNs) : $entry;

        $this->assertSame('4 - Novick Jared (0002006392) (Reporting)', (string) $children->title);
    }
}
