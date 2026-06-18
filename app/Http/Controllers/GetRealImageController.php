<?php

namespace App\Http\Controllers;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class GetRealImageController
{
    /**
     * @var Client
     */
    private $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    public function processImage(string $detailPageUrl): ?string
    {
        try {
            [$html, $detailPageUrl, $isDirectImage] = $this->fetchImagePageHtml($detailPageUrl);

            if ($html === null) {
                return $isDirectImage ? $detailPageUrl : null;
            }

            $crawler = new Crawler($html);

            // 嘗試各種 selector
            $checks = [
                ['css',   '.fileviewer-file img',        'src'],
                ['xpath', '//meta[@property="og:image"]','content'],
                ['xpath', '//meta[@name="twitter:image"]','content'],
            ];

            foreach ($checks as list($method, $expr, $attr)) {
                if ($method === 'css') {
                    $count = $crawler->filter($expr)->count();
                    Log::info('processImage CSS 檢查', compact('expr','count'));
                    if ($count) {
                        $url = $crawler->filter($expr)->attr($attr);
                        Log::info('processImage CSS 取到', ['url'=>$url]);
                        return $url;
                    }
                } else {
                    $count = $crawler->filterXpath($expr)->count();
                    Log::info('processImage XPath 檢查', compact('expr','count'));
                    if ($count) {
                        $url = $crawler->filterXpath($expr)->attr($attr);
                        Log::info('processImage XPath 取到', ['url'=>$url]);
                        return $url;
                    }
                }
            }

            Log::warning('processImage 解析完畢但沒取到任何圖片 URL', ['detailPageUrl'=>$detailPageUrl]);
            return null;

        } catch (\Exception $e) {
            Log::error('processImage 發生例外', [
                'url'   => $detailPageUrl,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function fetchImagePageHtml(string $detailPageUrl): array
    {
        $currentUrl = $detailPageUrl;
        $visited = [];

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $visited[$currentUrl] = true;

            $response = $this->client->request('GET', $currentUrl, [
                'verify'      => false,
                'http_errors' => false,  // 不拋例外
                'headers'     => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
                    'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Referer'    => 'https://image.javbee.vip/',
                ],
            ]);

            $status = $response->getStatusCode();
            $html = (string) $response->getBody();
            $contentType = strtolower(trim((string) $response->getHeaderLine('Content-Type')));
            Log::info('processImage HTTP 回應', [
                'detailPageUrl' => $currentUrl,
                'status' => $status,
                'body_len' => strlen($html),
            ]);

            // 只在 5xx（真正的 Server Error）才跳過
            if ($status >= 500) {
                Log::warning('processImage HTTP ≥500，跳過解析', [
                    'detailPageUrl' => $currentUrl,
                    'status' => $status,
                ]);

                return [null, $currentUrl, false];
            }

            // 某些圖床連結會直接回傳圖片，不會有可解析的 HTML。
            if (str_starts_with($contentType, 'image/')) {
                Log::info('processImage 偵測到直接圖片回應', [
                    'detailPageUrl' => $currentUrl,
                    'content_type' => $contentType,
                ]);

                return [null, $currentUrl, true];
            }

            $redirectUrl = $this->extractSoftRedirectUrl(
                (string) $response->getHeaderLine('Location'),
                $html,
                $currentUrl
            );

            if ($redirectUrl === null || isset($visited[$redirectUrl])) {
                return [$html, $currentUrl, false];
            }

            Log::info('processImage 跟隨 soft redirect', [
                'from' => $currentUrl,
                'to' => $redirectUrl,
                'status' => $status,
            ]);

            $currentUrl = $redirectUrl;
        }

        return [$html, $currentUrl, false];
    }

    private function extractSoftRedirectUrl(string $locationHeader, string $html, string $baseUrl): ?string
    {
        $candidate = trim($locationHeader);

        if ($candidate === '' && preg_match('/<meta\b[^>]*http-equiv=["\']?refresh["\']?[^>]*content=(["\'])(.*?)\1/i', $html, $matches)) {
            $refreshContent = html_entity_decode($matches[2], ENT_QUOTES | ENT_HTML5);

            if (preg_match('/url\s*=\s*([\'"]?)([^\'";>\s]+)\1/i', $refreshContent, $urlMatches)) {
                $candidate = $urlMatches[2];
            }
        }

        if ($candidate === '') {
            return null;
        }

        $candidate = trim(html_entity_decode($candidate, ENT_QUOTES | ENT_HTML5), " \t\n\r\0\x0B'\"");

        if ($candidate === '') {
            return null;
        }

        return (string) UriResolver::resolve(Utils::uriFor($baseUrl), Utils::uriFor($candidate));
    }
}
