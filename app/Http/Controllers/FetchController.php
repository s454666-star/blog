<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Services\CrawlerProductService;

class FetchController extends Controller
{
    protected $crawler;

    public function __construct(CrawlerProductService $crawler)
    {
        $this->crawler = $crawler;
    }

    public function fetchData(Request $request): string
    {
        $url = $request->input('url');
        try {
            $response           = Http::get($url);
            $html               = $response->body();
            $productTitle       = $this->crawler->getProductTitle($html);
            $productPrice       = $this->crawler->getProductPrice($html);
            $productDescription = $this->crawler->getProductDescription($html);
            $packageWeight      = $this->crawler->getPackageWeight($html);
            $formattedResponse  = "商品名稱: " . $productTitle . "\n商品價格: " . $productPrice . "\n商品說明: " . $productDescription . "\n梱包重量: " . ($packageWeight ?: "無資訊") . "\n\n";
            return $formattedResponse;
        }
        catch (\Exception $e) {
            return "Error: " . $e->getMessage();
        }
    }
}
