<?php

use App\Jobs\FetchEdgarFeed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Redis;

Route::get('/edgar-feed', function () {
    // Fetch the 50 latest records from the Redis Sorted Set (highest scores first)
    $feed = Redis::zrevrange('edgar:feed', 0, 49);

    if (empty($feed)) {
        (new FetchEdgarFeed())->handle();
        $feed = Redis::zrevrange('edgar:feed', 0, 49);
    }

    return response()->json(
        array_map('json_decode', $feed)
    );
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
