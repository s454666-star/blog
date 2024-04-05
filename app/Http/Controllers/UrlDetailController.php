<?php

namespace App\Http\Controllers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\DomCrawler\Crawler;
use App\Models\Article;
use App\Models\Image;

class UrlDetailController extends Controller
{
    public function fetchDetails($url)
    {
        $client = new Client();

        try {
            $response = $client->request('GET', $url, ['verify' => false]);

            if ($response->getStatusCode() == 200) {
                $body = $response->getBody()->getContents();
                $crawler = new Crawler($body);

                // 定位到包含所有文章的容器
                $content = $crawler->filter('.content.typo.editor-style')->first();

                // 分割內容以識別獨立的文章
                $articlesHtml = explode("【影片名称】：", $content->html());

                // 移除第一個元素，因為它是在第一個【影片名称】：之前的內容
                array_shift($articlesHtml);

                foreach ($articlesHtml as $articleHtml) {
                    $articleCrawler = new Crawler("<div>【影片名称】：" . $articleHtml);

                    // 提取標題
                    $title = $articleCrawler->filterXPath('//text()[contains(., "【影片名称】：")]')->each(function (Crawler $node) {
                        return trim(str_replace('【影片名称】：', '', $node->text()));
                    })[0] ?? 'Unknown Title';

                    // 提取密碼
                    $password = $articleCrawler->filterXPath('//text()[contains(., "解压密码：")]')->each(function (Crawler $node) {
                        return trim(str_replace('解压密码：', '', $node->text()));
                    })[0] ?? 'No Password';

                    // 提取下載鏈接
                    $https_link = $articleCrawler->filterXPath('//a[contains(text(), "下载地址：")]')->each(function (Crawler $node) {
                        return trim($node->attr('href'));
                    })[0] ?? 'No Link';

                    // 提取圖片 src 屬性
                    $imageSrcs = $articleCrawler->filter('img')->each(function (Crawler $node) {
                        return $node->attr('src');
                    });

                    // 在資料庫中查找或創建新的文章
                    $article = Article::firstOrCreate(
                        ['title' => $title], // 查找條件
                        ['password' => $password, 'https_link' => $https_link] // 創建時的其他屬性
                    );

                    // 如果文章是新創建的，則添加相關圖片
                    if ($article->wasRecentlyCreated) {
                        foreach ($imageSrcs as $src) {
                            Image::create([
                                'article_id' => $article->id,
                                'image_path' => $src,
                                'image_name' => basename($src),
                            ]);
                        }
                    }
                }
                dd('sucess');
            }
        } catch (GuzzleException $e) {
            dd('HTTP 請求失敗: ' . $e->getMessage());
        } catch (\Exception $e) {
            dd('發生錯誤: ' . $e->getMessage());
        }
    }
}
