<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

// Serve built Angular single-page application if index.html exists in public folder
Route::get('/{any?}', function (Request $request) {
    $indexPath = public_path('index.html');
    if (File::exists($indexPath)) {
        $html = File::get($indexPath);

        $host = env('REVERB_HOST');
        if (empty($host) || $host === '0.0.0.0' || $host === 'localhost') {
            $host = $request->getHost();
            if (str_contains($host, 'edgar-api')) {
                $host = str_replace('edgar-api', 'edgar-reverb', $host);
            }
        }
        $scheme = env('REVERB_SCHEME', $request->isSecure() ? 'https' : 'http');
        $port = (int) env('REVERB_PORT', $scheme === 'https' ? 443 : 8080);

        $reverbConfig = json_encode([
            'key' => env('REVERB_APP_KEY', 'capital_raise_reverb_key'),
            'host' => $host,
            'port' => $port,
            'scheme' => $scheme,
            'useTLS' => $scheme === 'https',
        ]);
        $script = '<script>window.__REVERB_CONFIG__=' . $reverbConfig . ';</script>';
        if (str_contains($html, '</head>')) {
            $html = str_replace('</head>', $script . '</head>', $html);
        } else {
            $html .= $script;
        }

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    return view('welcome');
})->where('any', '^(?!api|sanctum|_debugbar).*$');
