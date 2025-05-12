<?php

namespace App\Http\Controllers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
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
            $response = $this->client->request('GET', $detailPageUrl, [
                'verify'      => false,
                'http_errors' => false,  // 不拋例外
                'headers'     => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
                    'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Referer'    => 'https://image.javbee.vip/',
                ],
            ]);

            $status = $response->getStatusCode();
            $html   = (string) $response->getBody();
            Log::info('processImage HTTP 回應', compact('detailPageUrl','status') + ['body_len'=>strlen($html)]);

            // 只在 5xx（真正的 Server Error）才跳過
            if ($status >= 500) {
                Log::warning('processImage HTTP ≥500，跳過解析', compact('detailPageUrl','status'));
                return null;
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
}
