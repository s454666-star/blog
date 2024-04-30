<?php

namespace App\Services;

use Symfony\Component\DomCrawler\Crawler;

class CrawlerProductService
{
    public function getProductTitle($html)
    {
        try {
            $crawler = new Crawler($html);
            $title   = $crawler->filter('#productTitle')->text();
            return trim($title);
        }
        catch (\Exception $e) {
            return "錯誤：無法處理產品標題";
        }
    }

    public function getProductPrice($html)
    {
        try {
            $crawler   = new Crawler($html);
            $priceText = $crawler->filter('.a-price .a-offscreen')->first()->text();
            $price     = preg_replace('/[^\d,]/', '', $priceText);
            return trim($price);
        }
        catch (\Exception $e) {
            return "錯誤：無法處理產品價格";
        }
    }

    public function getProductDescription($html)
    {
        try {
            $crawler      = new Crawler($html);
            $descriptions = [];

            // 嘗試提取特徵列表
            $featureBullets = $crawler->filter('#feature-bullets .a-list-item');
            if ($featureBullets->count() > 0) {
                foreach ($featureBullets as $element) {
                    $descriptions[] = trim($element->textContent);
                }
            }

            // 嘗試提取表格數據
            $tableData = $crawler->filter('div.a-section.a-spacing-small.a-spacing-top-small table');
            if ($tableData->count() > 0) {
                foreach ($tableData->filter('tr') as $row) {
                    $cells = new Crawler($row);
                    $label = $cells->filter('td')->eq(0)->text(); // 選擇第一個td元素的文本
                    $value = $cells->filter('td')->eq(1)->text(); // 選擇第二個td元素的文本
                    if (!empty(trim($label)) && !empty(trim($value))) {
                        $descriptions[] = trim($label) . ": " . trim($value);
                    }
                }
            }
            return implode("\n", $descriptions);
        }
        catch (\Exception $e) {
            return "錯誤：無法處理產品描述";
        }
    }

    public function getPackageWeight($html)
    {
        try {
            $crawler = new Crawler($html);
            $weight  = $crawler->filterXPath('//th[contains(text(), "梱包重量")]/following-sibling::td')->text();
            return trim($weight);
        }
        catch (\Exception $e) {
            return ''; // 如果元素未找到或其他錯誤，返回空字符串
        }
    }
}
