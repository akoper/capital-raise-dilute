<?php

namespace Tests\Feature;

use App\Jobs\FetchEdgarFeed;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class EdgarFeedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Redis::del('edgar:feed');
    }

    public function test_fetch_edgar_feed_job_parses_and_stores_in_redis(): void
    {
        $mockJson = [
            'cik' => '0000320193',
            'name' => 'Apple Inc.',
            'filings' => [
                'recent' => [
                    'accessionNumber' => ['0000320193-24-000001'],
                    'filingDate' => ['2024-01-15'],
                    'reportDate' => ['2024-01-15'],
                    'form' => ['8-K'],
                    'primaryDocument' => ['aapl-20240115.htm']
                ]
            ]
        ];

        Http::fake([
            '*' => Http::response($mockJson, 200),
        ]);

        (new FetchEdgarFeed())->handle();

        $feed = Redis::zrevrange('edgar:feed', 0, 49);
        $this->assertCount(1, $feed);

        $decoded = json_decode($feed[0], true);
        $this->assertSame('Apple Inc. - Form 8-K (2024-01-15)', $decoded['title']);
        $this->assertSame('https://www.sec.gov/Archives/edgar/data/320193/000032019324000001/aapl-20240115.htm', $decoded['link']);
    }

    public function test_edgar_feed_api_endpoint_returns_feed_data(): void
    {
        $mockJson = [
            'cik' => '0000320193',
            'name' => 'Apple Inc.',
            'filings' => [
                'recent' => [
                    'accessionNumber' => ['0000320193-24-000001'],
                    'filingDate' => ['2024-01-15'],
                    'reportDate' => ['2024-01-15'],
                    'form' => ['8-K'],
                    'primaryDocument' => ['aapl-20240115.htm']
                ]
            ]
        ];

        Http::fake([
            '*' => Http::response($mockJson, 200),
        ]);

        $response = $this->getJson('/api/edgar-feed');

        $response->assertStatus(200);
        $response->assertJsonCount(1);
        $response->assertJsonFragment([
            'title' => 'Apple Inc. - Form 8-K (2024-01-15)',
            'link' => 'https://www.sec.gov/Archives/edgar/data/320193/000032019324000001/aapl-20240115.htm',
        ]);
    }
}
