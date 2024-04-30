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
            $response = $this->client->request('GET', $url, [
                'headers' => [
                    'User-Agent'                => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/88.0.4324.150 Safari/537.36',
                    'Accept'                    => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                    'Accept-Language'           => 'en-US,en;q=0.5',
                    'Accept-Encoding'           => 'gzip, deflate, br',
                    'Connection'                => 'keep-alive',
                    'Upgrade-Insecure-Requests' => '1',
                    'TE'                        => 'Trailers'
                ]
            ]);
            $html     = (string)$response->getBody();

            $productTitle       = $this->crawler->getProductTitle($html);
            $productPrice       = $this->crawler->getProductPrice($html);
            $productDescription = $this->crawler->getProductDescription($html);
            $packageWeight      = $this->crawler->getPackageWeight($html);
            $productColors      = $this->crawler->getProductColors($html);
            $productStyles      = $this->crawler->getProductStyles($html);
            $productImage       = $this->crawler->getProductImage($html);

            $formattedResponse = "商品名稱: " . $productTitle .
                "\n商品價格: " . $productPrice .
                "\n商品說明: " . $productDescription .
                "\n梱包重量: " . ($packageWeight ?: "無資訊") .
                "\n" . $productColors .
                "\n" . $productStyles . // 添加商品样式信息
                "\n商品圖片: " . $productImage;
//                "\n\n" . $html;
            return $formattedResponse;
        }
        catch (\Exception $e) {
            return "Error: " . $e->getMessage();
        }
    }
}
