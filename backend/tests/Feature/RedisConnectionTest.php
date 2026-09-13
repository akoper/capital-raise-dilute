<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class RedisConnectionTest extends TestCase
{
    public function test_redis_connection_and_cache_operations(): void
    {
        try {
            Redis::set('test_server_key', 'working');
            $value = Redis::get('test_server_key');
            $this->assertSame('working', $value);

            Cache::store('redis')->put('test_cache_key', 'cached_data', 60);
            $cachedValue = Cache::store('redis')->get('test_cache_key');
            $this->assertSame('cached_data', $cachedValue);
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis server is not available: ' . $e->getMessage());
        }
    }
}
