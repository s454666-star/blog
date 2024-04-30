<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use GuzzleHttp\Client;
use App\Services\CrawlerProductService;

class FetchController extends Controller
{
    protected $crawler;
    protected $client;

    public function __construct(CrawlerProductService $crawler)
    {
        $this->crawler = $crawler;
        $this->client  = new Client(); // 初始化 GuzzleHttp Client
    }

    public function fetchData(Request $request): string
    {
        $url = $request->input('url');

        try {
            // 发送请求，设置 User-Agent
            $response = $this->client->request('GET', $url, [
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.36'
                ]
            ]);
            $html     = (string)$response->getBody();

            $productTitle       = $this->crawler->getProductTitle($html);
            $productPrice       = $this->crawler->getProductPrice($html);
            $productDescription = $this->crawler->getProductDescription($html);
            $packageWeight      = $this->crawler->getPackageWeight($html);

            $formattedResponse = "商品名稱: " . $productTitle .
                "\n商品價格: " . $productPrice .
                "\n商品說明: " . $productDescription .
                "\n梱包重量: " . ($packageWeight ?: "無資訊") . "\n\n" . $html;
            return $formattedResponse;
        }
        catch (\Exception $e) {
            return "Error: " . $e->getMessage();
        }
    }
}
