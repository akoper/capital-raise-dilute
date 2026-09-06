<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use App\Events\NewFilingsDetected;

class FetchEdgarFeed implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        // SEC requires a declared User-Agent string or they will block requests
        $response = Http::withHeaders([
            'User-Agent' => 'YourCompanyYourName contact@yourdomain.com'
        ])->get('https://sec.gov');

        if ($response->failed()) return;

        $xml = simplexml_load_string($response->body());
        $newItems = [];

        foreach ($xml->entry as $entry) {
            $id = (string) $entry->id;
            $title = (string) $entry->title;
            $updated = strtotime((string) $entry->updated);
            $link = (string) $entry->link['href'];

            $payload = json_encode([
                'id' => $id,
                'title' => $title,
                'updated' => $updated,
                'link' => $link
            ]);

            // Check if item already exists using Redis Sorted Set score check
            $exists = Redis::zscore('edgar:feed', $payload);

            if (is_null($exists)) {
                // Add to Redis Sorted Set with the timestamp as score
                Redis::zadd('edgar:feed', $updated, $payload);
                $newItems[] = json_decode($payload, true);
            }
        }

        // Trim the feed to keep only the 500 latest entries to prevent memory leaks
        Redis::zremrangebyrank('edgar:feed', 0, -501);

        // If new entries exist, broadcast them to the frontend
        if (!empty($newItems)) {
            broadcast(new NewFilingsDetected($newItems))->toOthers();
        }
    }
}
