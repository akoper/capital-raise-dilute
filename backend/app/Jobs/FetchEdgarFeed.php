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
            'User-Agent' => 'Detroit-Issuance-Tracker findlikemindedcom@gmail.com'
        ])->get('https://www.sec.gov/cgi-bin/browse-edgar?action=getcurrent&CIK=&type=&company=&dateb=&owner=include&start=0&count=40&output=atom');

        if (!$response->successful()) {
            return;
        }

        $body = trim($response->body());
        if (empty($body)) {
            return;
        }

        $newItems = [];

        // Check if response is JSON
        $jsonData = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($jsonData)) {
            $recent = $jsonData['filings']['recent'] ?? null;
            if ($recent && !empty($recent['accessionNumber'])) {
                $cik = ltrim($jsonData['cik'] ?? '', '0');
                $companyName = $jsonData['name'] ?? 'Company';
                $count = count($recent['accessionNumber']);

                for ($i = 0; $i < min($count, 40); $i++) {
                    $accessionRaw = $recent['accessionNumber'][$i];
                    $accessionClean = str_replace('-', '', $accessionRaw);
                    $primaryDoc = $recent['primaryDocument'][$i] ?? '';
                    $form = $recent['form'][$i] ?? 'Filing';
                    $filingDate = $recent['filingDate'][$i] ?? '';
                    $reportDate = $recent['reportDate'][$i] ?? $filingDate;
                    $updated = strtotime($filingDate) ?: time();

                    $link = "https://www.sec.gov/Archives/edgar/data/{$cik}/{$accessionClean}/{$primaryDoc}";
                    $title = "{$companyName} - Form {$form} ({$reportDate})";

                    $payload = json_encode([
                        'id' => $accessionRaw,
                        'title' => $title,
                        'updated' => $updated,
                        'link' => $link
                    ]);

                    $exists = Redis::zscore('edgar:feed', $payload);
                    if ($exists === null || $exists === false) {
                        Redis::zadd('edgar:feed', $updated, $payload);
                        $newItems[] = json_decode($payload, true);
                    }
                }
            }
        } else {
            // Parse as XML (Atom / RSS)
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($body);
            libxml_clear_errors();

            if ($xml) {
                $entries = $xml->xpath('//*[local-name()="entry"]');
                if (empty($entries)) {
                    $entries = $xml->xpath('//*[local-name()="item"]');
                }

                if (!empty($entries)) {
                    foreach ($entries as $entry) {
                        $idMatches = $entry->xpath('./*[local-name()="id" or local-name()="guid"]');
                        $id = !empty($idMatches) ? (string) $idMatches[0] : (string) ($entry->id ?? $entry->guid ?? '');

                        $titleMatches = $entry->xpath('./*[local-name()="title"]');
                        $title = !empty($titleMatches) ? (string) $titleMatches[0] : (string) ($entry->title ?? '');

                        $updatedMatches = $entry->xpath('./*[local-name()="updated" or local-name()="pubDate"]');
                        $updatedStr = !empty($updatedMatches) ? (string) $updatedMatches[0] : (string) ($entry->updated ?? $entry->pubDate ?? '');
                        $updated = $updatedStr ? strtotime($updatedStr) : time();

                        $linkHrefMatches = $entry->xpath('./*[local-name()="link"]/@href');
                        $linkTextMatches = $entry->xpath('./*[local-name()="link"]');
                        $link = '';
                        if (!empty($linkHrefMatches)) {
                            $link = (string) $linkHrefMatches[0];
                        } elseif (!empty($linkTextMatches) && (string) $linkTextMatches[0] !== '') {
                            $link = (string) $linkTextMatches[0];
                        } elseif (isset($entry->link['href'])) {
                            $link = (string) $entry->link['href'];
                        } elseif (isset($entry->link)) {
                            $link = (string) $entry->link;
                        }

                        $payload = json_encode([
                            'id' => $id,
                            'title' => $title,
                            'updated' => $updated,
                            'link' => $link
                        ]);

                        $exists = Redis::zscore('edgar:feed', $payload);
                        if ($exists === null || $exists === false) {
                            Redis::zadd('edgar:feed', $updated, $payload);
                            $newItems[] = json_decode($payload, true);
                        }
                    }
                }
            }
        }

        // Trim the feed to keep only the 500 latest entries to prevent memory leaks
        Redis::zremrangebyrank('edgar:feed', 0, -501);

        // If new entries exist, broadcast them to the frontend
        if (!empty($newItems)) {
            broadcast(new NewFilingsDetected($newItems));
        }
    }
}
