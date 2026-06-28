<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TestImageController extends Controller
{
    public function proxy(Request $request)
    {
        $requestUrl = trim((string) $request->query('url', ''));

        // 保留舊有測試行為
        $imageUrl = $requestUrl !== '' ? $requestUrl : 'https://85sugarbaby.com.tw/home';

        $host = strtolower((string) parse_url($imageUrl, PHP_URL_HOST));
        if ($requestUrl !== '' && $host !== '85sugarbaby.com.tw') {
            return response('forbidden host', 403);
        }

        try {
            $response = Http::withOptions([
                'verify'      => false,
                'timeout'     => 15,
                'http_errors' => false,
            ])->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
                'Referer'    => 'https://85sugarbaby.com.tw/',
                'Accept'     => 'image/avif,image/webp,*/*',
            ])->get($imageUrl);

            if ($response->status() >= 400) {
                return response('Image not found', 404);
            }

            $contentType = $response->header('Content-Type') ?: 'application/octet-stream';

            return response($response->body(), 200)
                ->header('Content-Type', $contentType)
                ->header('Cache-Control', 'public, max-age=86400');
        } catch (\Throwable $e) {
            Log::error('proxy image failed', [
                'url'   => $imageUrl,
                'error' => $e->getMessage(),
            ]);

            return response('Image proxy error', 502);
        }
    }
}
