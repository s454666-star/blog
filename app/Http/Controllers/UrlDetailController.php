<?php

namespace App\Http\Controllers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\DomCrawler\Crawler;
use App\Models\Article;
use App\Models\Image;
use Illuminate\Support\Facades\Log;

class UrlDetailController extends Controller
{
    public function fetchDetails($url)
    {
        $client = new Client();

        try {
            $response = $client->request('GET', $url, [ 'verify' => false ]);

            if ($response->getStatusCode() == 200) {
                $body    = $response->getBody()->getContents();
                $crawler = new Crawler($body);

                // 定位到包含所有文章的容器
                $content = $crawler->filter('.content.typo.editor-style')->first();

                // 分割內容以識別獨立的文章
                $articlesHtml = explode("【影片名称】：", $content->html());

                // 移除第一個元素，因為它是在第一個【影片名称】：之前的內容
                array_shift($articlesHtml);

                $pageTitle = $crawler->filter('title')->first()->text();

                foreach ($articlesHtml as $articleHtml) {
                    $articleCrawler = new Crawler("<div>【影片名称】：" . $articleHtml);

                    // 提取標題
                    $title = $articleCrawler->filterXPath('//text()[contains(., "【影片名称】：")]')->each(function (Crawler $node) {
                        return trim(str_replace('【影片名称】：', '', $node->text()));
                    })[0] ?? '未知標題';

                    // 提取密碼
                    $passwordPattern = '/(?:【解压密码】：|解压密码：|【文件密码】：|【解压密码】|解压密码)(?:<\/?font.*?>)?\s*([^<]+?)(?:<\/?font>)?\s*(?:<br\s*\/?>|$)/i';

                    if (preg_match($passwordPattern, $articleHtml, $passwordMatches)) {
                        $password = trim($passwordMatches[1]); // 使用trim函数去除可能的前后空白字符
                    } else {
                        $password = '无密码';
                    }
                    $password = html_entity_decode($password);


                    // 提取下載鏈接
                    $links = $articleCrawler->filter('a')->each(function (Crawler $node) {
                        // 檢查 href 是否包含特定的字串
                        if (strpos($node->attr('href'), 'www.qqupload.com') !== false) {
                            return trim($node->attr('href'));
                        }
                    });

                    // 移除空值並去重
                    $links = array_unique(array_filter($links));

                    // 將鏈接使用逗號串聯起來
                    $https_link = implode(',', $links);

                    // 在資料庫中查找或創建新的文章
                    $article = Article::firstOrCreate(
                        [ 'title' => $title ],                                   // 查找條件
                        [ 'password' => $password, 'https_link' => $https_link ] // 創建時的其他屬性
                    );


                    // 提取圖片 src 屬性
                    $imageSrcs = $articleCrawler->filter('img')->each(function (Crawler $node) {
                        // 對於每個找到的 img 標籤，檢查它的 src 屬性
                        return $node->attr('src');
                    });

                    // 確認文章成功創建並且有有效的 id
                    if ($article->wasRecentlyCreated) {
                        if (!empty($imageSrcs)) {
                            foreach ($imageSrcs as $src) {
                                Image::create([
                                    'article_id' => $article->article_id,
                                    'image_path' => $src,
                                    'image_name' => basename($src),
                                ]);
                            }
                        } else {
                            // 如果沒有圖片，則記錄錯誤訊息
                            Log::error('文章缺少圖片', [ 'title' => $title, 'pageTitle' => $pageTitle ]);
                        }
                    }
                }
            }
        }
        catch (GuzzleException $e) {
            dd('HTTP 請求失敗: ' . $e->getMessage());
        }
        catch (\Exception $e) {
            dd('發生錯誤: ' . $e->getMessage());
        }
    }
}
