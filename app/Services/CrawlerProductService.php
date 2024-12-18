<?php

namespace App\Services;

use DOMDocument;
use Symfony\Component\DomCrawler\Crawler;

class CrawlerProductService
{
    public function getProductTitle($html): string
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

    public function getProductPrice($html): string
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

    public function getProductDescription($html): string
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

    public function getPackageWeight($html): string
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

    public function getProductColors($html): string
    {
        try {
            $crawler = new Crawler($html);
            // 使用过滤器筛选包含特定 title 的 li 元素
            $colorElements = $crawler->filter('#twisterContainer li[title*="Click to select"] img');

            $colors = [];
            foreach ($colorElements as $element) {
                $imgCrawler = new Crawler($element);
                $altText    = $imgCrawler->attr('alt'); // 获取 img 标签的 alt 属性作为颜色名称
                if (!empty(trim($altText))) { // 确保 alt 文本不为空
                    $colors[] = trim($altText);
                }
            }

            if (!empty($colors)) {
                return "商品顏色: " . implode(", ", $colors);
            } else {
                return "商品顏色: 無資訊";
            }
        }
        catch (\Exception $e) {
            return "商品顏色: 無資訊"; // 如果出现异常，则返回无信息
        }
    }

    public function getProductStyles($html): string
    {
        try {
            $crawler = new Crawler($html);
            // 定位到包含商品样式的所有选项
            $styleElements = $crawler->filter('#variation_style_name .a-button-text');

            $styles = [];
            foreach ($styleElements as $element) {
                $styleCrawler = new Crawler($element);
                $styleText    = $styleCrawler->filter('.twisterTextDiv.text p')->text(); // 从结构中提取样式名称
                if (!empty(trim($styleText))) { // 确保样式文本不为空
                    $styles[] = trim($styleText);
                }
            }

            if (!empty($styles)) {
                return "商品樣式: " . implode(", ", $styles);
            } else {
                return "商品樣式: 無資訊";
            }
        }
        catch (\Exception $e) {
            return "商品樣式: 無資訊"; // 如果出现异常，则返回无信息
        }
    }

    public function getProductImage($html): string
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML($html);
        libxml_clear_errors();
        $scriptTags = $doc->getElementsByTagName('script');
        $images     = [];
        foreach ($scriptTags as $tag) {
            if (strpos($tag->nodeValue, 'colorImages') !== false) {
                preg_match_all('/"large":"([^"]+)"/', $tag->nodeValue, $matches);
                if (!empty($matches[1])) {
                    // Add only new images to array, preventing duplicates immediately
                    $images = array_merge($images, $matches[1]);
                }
            }
        }
        // Remove duplicates from the array
        $images = array_unique($images);
        // Return all unique large image URLs as a single string
        return implode(", ", $images);
    }


}
