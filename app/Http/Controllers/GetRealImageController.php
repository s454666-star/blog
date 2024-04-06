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

    public function processImage(string $imageUrl)
    {
        try {
            $response = $this->client->request('GET', $imageUrl, [ 'verify' => false ]);
            $body     = $response->getBody()->getContents();

            // 使用 Symfony DomCrawler 解析 HTML
            $crawler = new Crawler($body);

            // 針對 class 為 'fileviewer-file' 的 div 內的 img 標籤進行過濾
            $imgUrl = $crawler->filter('.fileviewer-file img')->attr('src');
            var_dump($imgUrl);
            if (empty($imgUrl)) {
                dd($body);
            }
            return $imgUrl;
        }
        catch (GuzzleException $e) {
            // 處理異常情況
            Log::error("圖片處理失敗", [ 'url' => $imageUrl, 'error' => $e->getMessage() ]);
        }
    }
}
