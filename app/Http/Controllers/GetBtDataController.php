<?php

namespace App\Http\Controllers;

use DOMDocument;
use DOMXPath;
use Exception;
use GuzzleHttp\Client;

class GetBtDataController
{
    private $getBtDataDetailController;

    // 透過 Laravel 的自動解析功能注入 GetBtDataDetailController
    public function __construct(GetBtDataDetailController $getBtDataDetailController)
    {
        $this->getBtDataDetailController = $getBtDataDetailController;
    }

    public function fetchData()
    {
        $client  = new Client();
        $baseUrl = 'https://sukebei.nyaa.si';

        for ($page = 1; $page <= 2; $page++) {
            try {
                $response = $client->request('GET', "{$baseUrl}/?f=0&c=0_0&q=%22%2B%2B%2B+FC%22&p={$page}", [
                    'verify' => false,
                ]);

                $body = $response->getBody()->getContents();

                $dom = new DOMDocument();
                @$dom->loadHTML($body);
                $xpath = new DOMXPath($dom);

                // XPath查詢找到所有的明細頁面連結
                $links = $xpath->query("//a[contains(@href, '/view/')]");

                foreach ($links as $link) {
                    $detailPageUrl = $baseUrl . $link->getAttribute('href');
                    $this->getBtDataDetailController->fetchDetail($detailPageUrl);
                }
            }
            catch (Exception $e) {
                echo "請求失敗: " . $e->getMessage() . "\n";
            }
        }
    }
}
