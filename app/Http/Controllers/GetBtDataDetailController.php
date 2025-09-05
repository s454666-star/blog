<?php

    namespace App\Http\Controllers;

    use App\Models\Article;
    use Exception;
    use GuzzleHttp\Client;
    use GuzzleHttp\Exception\GuzzleException;
    use DOMDocument;
    use DOMXPath;
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Log;

    class GetBtDataDetailController
    {
        protected $client;
        /**
         * @var GetRealImageController
         */
        private $getRealImageController;

        public function __construct(GetRealImageController $getRealImageController)
        {
            $this->getRealImageController = $getRealImageController;
            $this->client = new Client([
                // 允許更多重新導向
                'allow_redirects' => [
                    'max'             => 20,  // 最大重新導向次數
                    'strict'          => true,  // 根據規範嚴格執行重新導向
                    'referer'         => true,  // 在重新導向時添加 Referer header
                    'protocols'       => ['http', 'https'],  // 允許的協議
                    'track_redirects' => true  // 跟蹤並記錄所有的重新導向鏈
                ],
            ]);
        }

        public function fetchDetail(string $detailPageUrl)
        {
            DB::beginTransaction(); // 開始一個新的數據庫事務
            try {
                // 第一次請求取得網頁內容
                $response    = $this->client->request('GET', $detailPageUrl, ['verify' => false]);
                $htmlContent = $response->getBody()->getContents();

                // 解析 DOM，取得標題、時間、磁力連結與下載連結
                $dom   = new DOMDocument();
                @$dom->loadHTML($htmlContent);
                $xpath = new DOMXPath($dom);

                $titleNode = $xpath->query('//h3[@class="panel-title"]')->item(0);
                $title     = $titleNode ? trim($titleNode->nodeValue) : '';

                $timeNode    = $xpath->query('//div[@class="row"]/div[@class="col-md-1" and text()="Date:"]/following-sibling::div[@class="col-md-5"]')->item(0);
                $articleTime = $timeNode ? trim($timeNode->nodeValue) : '';
                $articleTime = str_replace(" UTC", "", $articleTime);
                $articleTimeWithSeconds = Carbon::createFromFormat('Y-m-d H:i', $articleTime, 'Asia/Taipei')->format('Y-m-d H:i:s');

                // 若文章已存在則跳過
                $existingArticle = Article::where('title', $title)->first();
                if ($existingArticle) {
                    echo "文章已存在，跳過儲存。\r\n";
                    DB::commit();
                    return;
                }

                $magnetLinkNode = $xpath->query('//a[contains(@href,"magnet:?xt=")]')->item(0);
                $magnetLink     = $magnetLinkNode ? trim($magnetLinkNode->getAttribute('href')) : '';

                $downloadLinkNode = $xpath->query('//div[@class="panel-footer clearfix"]/a[contains(@href,"/download/")]')->item(0);
                $baseUrl          = parse_url($detailPageUrl, PHP_URL_SCHEME) . '://' . parse_url($detailPageUrl, PHP_URL_HOST);
                $downloadLink     = $downloadLinkNode ? $baseUrl . trim($downloadLinkNode->getAttribute('href')) : '';

                // 重試機制：嘗試取得圖片，最多重試5次，每次失敗後等待30秒
                $attempt = 0;
                $imageUrls = [];
                do {
                    // 解析 DOM 從說明內容中取得圖片網址
                    $dom = new DOMDocument();
                    @$dom->loadHTML($htmlContent);
                    $xpath = new DOMXPath($dom);

                    $descriptionNode = $xpath->query('//div[contains(@id,"torrent-description")]')->item(0);
                    $imageUrls = [];
                    if ($descriptionNode) {
                        $lines = explode("\n", $descriptionNode->textContent);
                        foreach ($lines as $line) {
                            if (preg_match('/https:\/\/.*?\.jpg/', $line, $matches)) {
                                $imageUrls[] = $matches[0];
                            }
                        }
                    }

                    if (!empty($imageUrls)) {
                        break;
                    }

                    // 若未取得圖片，等待30秒後重新抓取網頁內容再重試
                    sleep(30);
                    $attempt++;
                    $response    = $this->client->request('GET', $detailPageUrl, ['verify' => false]);
                    $htmlContent = $response->getBody()->getContents();
                } while ($attempt < 5);

                // 建立文章記錄，若重試5次後仍無圖片，則以無圖片狀態儲存
                $article = Article::create([
                    'title'        => $title,
                    'password'     => $magnetLink,
                    'https_link'   => $downloadLink,
                    'detail_url'   => $detailPageUrl,
                    'article_time' => $articleTimeWithSeconds,
                    'source_type'  => 2,
                    'is_disabled'  => 0,
                ]);

                // 處理圖片，若有抓到圖片則存入資料庫
                if (!empty($imageUrls)) {
                    foreach ($imageUrls as $imageUrl) {
                        try {
                            var_dump($imageUrl);
                            $realUrl = $this->getRealImageController->processImage($imageUrl);

                            $existingImage = $article->images()->where('image_path', $realUrl)->first();
                            if (!$existingImage && $realUrl) {
                                $article->images()->create([
                                    'image_name' => basename($realUrl),
                                    'image_path' => $realUrl,
                                ]);
                            }
                        } catch (Exception $e) {
                            Log::error("圖片處理失敗", ['url' => $imageUrl, 'error' => $e->getMessage()]);
                        }
                    }
                } else {
                    Log::error("沒有找到圖片，儲存文章但無圖片", ['url' => $detailPageUrl]);
                }

                DB::commit(); // 提交事務
            } catch (GuzzleException $e) {
                DB::rollBack(); // 回滾事務
                Log::error("請求失敗", ['url' => $detailPageUrl, 'error' => $e->getMessage()]);
            }
        }
    }
