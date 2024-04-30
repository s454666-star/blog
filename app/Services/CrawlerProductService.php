<?php

namespace App\Services;

use Symfony\Component\DomCrawler\Crawler;

class CrawlerProductService
{
    public function getProductTitle($html)
    {
        $crawler = new Crawler($html);
        $title   = $crawler->filter('#productTitle')->text();
        return trim($title);
    }

    public function getProductPrice($html)
    {
        $crawler   = new Crawler($html);
        $priceText = $crawler->filter('.a-price .a-offscreen')->first()->text();
        $price     = preg_replace('/[^\d,]/', '', $priceText);
        return trim($price);
    }

    public function getProductDescription($html)
    {
        $crawler      = new Crawler($html);
        $descriptions = [];

        // 尝试提取特征列表
        $featureBullets = $crawler->filter('#feature-bullets .a-list-item');
        if ($featureBullets->count() > 0) {
            foreach ($featureBullets as $element) {
                $descriptions[] = trim($element->textContent);
            }
        }

        // 尝试提取表格数据
        try {
            $tableData = $crawler->filter('div.a-section.a-spacing-small.a-spacing-top-small table');
            if ($tableData->count() > 0) {
                foreach ($tableData->filter('tr') as $row) {
                    $cells = new Crawler($row);
                    $label = $cells->filter('td')->eq(0)->text(); // 选择第一个td元素的文本
                    $value = $cells->filter('td')->eq(1)->text(); // 选择第二个td元素的文本
                    if (!empty(trim($label)) && !empty(trim($value))) {
                        $descriptions[] = trim($label) . ": " . trim($value);
                    }
                }
            }
        }
        catch (\Exception $e) {
            // Handle exceptions if any
            $descriptions[] = "Error processing table data: " . $e->getMessage();
        }

        return implode("\n", $descriptions);
    }

    public function getPackageWeight($html)
    {
        $crawler = new Crawler($html);
        try {
            $weight = $crawler->filterXPath('//th[contains(text(), "梱包重量")]/following-sibling::td')->text();
            return trim($weight);
        }
        catch (\Exception $e) {
            // If the element is not found or any other error, return an empty string
            return '';
        }
    }
}
