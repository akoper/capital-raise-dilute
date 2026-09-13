<?php

use App\Jobs\FetchEdgarFeed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Redis;

Route::get('/ws-config', function () {
    $reqHost = request()->getHost();
    $isSecure = request()->isSecure() || request()->header('X-Forwarded-Proto') === 'https';

    $configuredHost = config('broadcasting.connections.reverb.options.host') ?: env('REVERB_HOST');
    if (str_contains($reqHost, 'edgar-api')) {
        $host = str_replace('edgar-api', 'edgar-reverb', $reqHost);
    } elseif (!empty($configuredHost) && $configuredHost !== '0.0.0.0' && $configuredHost !== 'localhost' && $configuredHost !== '127.0.0.1') {
        $host = $configuredHost;
    } else {
        $host = $reqHost ?: '127.0.0.1';
    }

    $isLocalHost = in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_starts_with($host, '192.168.') || str_starts_with($host, '10.');

    if ($isLocalHost) {
        $scheme = env('REVERB_SCHEME', 'http');
        $port = (int) env('REVERB_PORT', 8080);
    } else {
        $isCloudRun = str_contains($host, '.run.app');
        $scheme = $isCloudRun ? 'https' : (env('REVERB_SCHEME') ?: ($isSecure ? 'https' : 'http'));
        $defaultPort = ($scheme === 'https') ? 443 : 8080;
        if ($scheme === 'https') {
            $port = (env('REVERB_PORT') && env('REVERB_PORT') != 8080) ? (int) env('REVERB_PORT') : 443;
        } else {
            $port = (int) (env('REVERB_PORT') ?: $defaultPort);
        }
    }

    $key = config('broadcasting.connections.reverb.key') ?: env('REVERB_APP_KEY', 'capital_raise_reverb_key');

    return response()->json([
        'key' => $key,
        'host' => $host,
        'port' => $port,
        'scheme' => $scheme,
        'useTLS' => $scheme === 'https',
    ]);
});

Route::get('/edgar-feed', function () {
    // Fetch the 50 latest records from the Redis Sorted Set (highest scores first)
    try {
        $feed = Redis::zrevrange('edgar:feed', 0, 49);

        if (empty($feed)) {
            (new FetchEdgarFeed())->handle();
            $feed = Redis::zrevrange('edgar:feed', 0, 49);
        }

        return response()->json(
            array_map('json_decode', $feed ?: [])
        );
    } catch (\Throwable $e) {
        return response()->json([], 200);
    }
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
