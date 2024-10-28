<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ExtractArticleNumber extends Command
{
    protected $signature = 'article:extract-number';
    protected $description = 'Extract number from the last 30 articles with null description, curl the FC2 URL, and insert content into the description field';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        // 從 articles 資料表撈取最後 30 筆 description 為 null 的資料
        $articles = DB::table('articles')
            ->whereNull('description')
            ->orderBy('created_at', 'desc')
            ->limit(60)
            ->get();

        foreach ($articles as $article) {
            if (preg_match('/V-(\d+)/', $article->title, $matches)) {
                // 抓出 V- 後面的數字
                $number = $matches[1];

                // curl 網址
                $url = "https://adult.contents.fc2.com/article/$number/";
                $response = Http::get($url);
                $html = $response->body();

                // 抓取 "商品の説明" 裡面的 iframe 路徑
                if (preg_match('/<iframe src="(\/widget\/article\/.+?\/description\?ac=[^"]+)"/', $html, $iframeMatch)) {
                    $iframeUrl = 'https://adult.contents.fc2.com' . $iframeMatch[1];

                    // 從回應 headers 取得 cookies
                    $cookies = $this->extractCookiesFromResponse($response);

                    // 使用帶有 cookies 的 curl 請求 iframe 頁面
                    $iframeResponse = Http::withCookies($cookies, 'adult.contents.fc2.com')
                        ->get($iframeUrl);

                    // 取得 iframe 頁面的內容
                    $iframeContent = $iframeResponse->body();

                    // 先移除所有 HTML 標籤
                    $plainTextContent = strip_tags($iframeContent);

                    // 過濾掉包含 "==”、 "window.xxx" 或類似 JavaScript 語句的行，並停止於 "※出演者は"
                    $cleanedContent = $this->removeUnwantedLines($plainTextContent);

                    // 清理連續的多個換行符，只保留一個
                    $cleanedContent = preg_replace("/(\n\s*){2,}/", "\n", $cleanedContent);

                    // 將抓到的內容寫入資料庫中的 description 欄位
                    DB::table('articles')
                        ->where('article_id', $article->article_id)
                        ->update(['description' => $cleanedContent]);

                    $this->info('Description updated successfully for article ID: ' . $article->article_id);
                } else {
                    $this->error('Iframe not found for article ID: ' . $article->article_id);
                }
            } else {
                $this->error('No valid article title found or format is incorrect for article ID: ' . $article->article_id);
            }
        }
    }

    private function removeUnwantedLines($content)
    {
        $lines = explode("\n", $content);
        $filteredLines = [];

        foreach ($lines as $line) {
            if (strpos($line, '※出演者は') !== false) {
                break;
            }

            if (preg_match('/window\.[a-zA-Z0-9_]+\s*=/', trim($line)) ||
                preg_match('/window\["[a-zA-Z0-9_]+"]\s*=/', trim($line)) ||
                preg_match('/^[a-zA-Z0-9+\/]+={1,2}$/', trim($line)) ||
                preg_match('/\bFC2ContentsObject\b/', trim($line)) ||
                preg_match('/\bpush\(/', trim($line))) {
                continue;
            }

            $filteredLines[] = $line;
        }

        return implode("\n", $filteredLines);
    }

    private function extractCookiesFromResponse($response)
    {
        $cookies = [];
        $setCookieHeaders = $response->header('Set-Cookie');

        if (is_array($setCookieHeaders)) {
            foreach ($setCookieHeaders as $header) {
                $cookieParts = explode(';', $header);
                $cookieKeyValue = explode('=', $cookieParts[0], 2);
                if (count($cookieKeyValue) === 2) {
                    $cookies[$cookieKeyValue[0]] = $cookieKeyValue[1];
                }
            }
        } elseif (is_string($setCookieHeaders)) {
            $cookieParts = explode(';', $setCookieHeaders);
            $cookieKeyValue = explode('=', $cookieParts[0], 2);
            if (count($cookieKeyValue) === 2) {
                $cookies[$cookieKeyValue[0]] = $cookieKeyValue[1];
            }
        }

        return $cookies;
    }
}
