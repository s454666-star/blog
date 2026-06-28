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
        $allowedHosts = ['85sugarbaby.com.tw'];
        if ($requestUrl !== '' && ! in_array($host, $allowedHosts, true)) {
            return response('forbidden host', 403);
        }

        $response = $this->fetchImage($imageUrl);
        if ($response === null) {
            return response('Image not found', 404);
        }

        return response($response['body'], 200)
            ->header('Content-Type', $response['content_type'])
            ->header('Cache-Control', 'public, max-age=86400');
    }

    private function fetchImage(string $imageUrl): ?array
    {
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
                return $this->fetchImageWithCurl($imageUrl);
            }

            $contentType = $response->header('Content-Type') ?: 'application/octet-stream';
            return ['body' => $response->body(), 'content_type' => $contentType];
        } catch (\Throwable $e) {
            Log::warning('proxy image failed with Http client, fallback to cURL', [
                'url'   => $imageUrl,
                'error' => $e->getMessage(),
            ]);

            return $this->fetchImageWithCurl($imageUrl);
        }
    }

    private function fetchImageWithCurl(string $imageUrl): ?array
    {
        $ch = curl_init();
        if ($ch === false) {
            Log::error('proxy image failed', [
                'url'   => $imageUrl,
                'error' => 'curl_init_failed',
            ]);

            return null;
        }

        try {
            curl_setopt_array($ch, [
                CURLOPT_URL            => $imageUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
                CURLOPT_REFERER        => 'https://85sugarbaby.com.tw/',
                CURLOPT_HTTPHEADER     => [
                    'Accept: image/avif,image/webp,*/*',
                ],
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_FAILONERROR    => false,
            ]);

            $body = (string) curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            if ($httpCode >= 400 || $body === '' || $contentType === '') {
                Log::warning('proxy image cURL failed', [
                    'url'         => $imageUrl,
                    'status'      => $httpCode,
                    'has_body'    => $body !== '',
                    'contentType' => $contentType,
                ]);

                return null;
            }

            return ['body' => $body, 'content_type' => $contentType];
        } finally {
            curl_close($ch);
        }
    }
}
