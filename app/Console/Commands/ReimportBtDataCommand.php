<?php

    namespace App\Console\Commands;

    use App\Http\Controllers\GetBtDataDetailController;
    use App\Models\Article;
    use GuzzleHttp\Client;
    use GuzzleHttp\Exception\GuzzleException;
    use Illuminate\Console\Command;
    use Illuminate\Support\Facades\Log;

    class ReimportBtDataCommand extends Command
    {
        /**
         * The name and signature of the console command.
         *
         * 此命令可接受一個 URL 參數，若未輸入會進行互動式詢問
         *
         * @var string
         */
        protected $signature = 'bt:reimport {url?}';

        /**
         * The console command description.
         *
         * @var string
         */
        protected $description = '根據輸入 URL 取得文章，若文章存在則先刪除原資料後重新匯入（文章與圖片）';

        /**
         * @var GetBtDataDetailController
         */
        protected $btDataController;

        public function __construct(GetBtDataDetailController $btDataController)
        {
            parent::__construct();
            $this->btDataController = $btDataController;
        }

        /**
         * @throws GuzzleException
         */
        public function handle()
        {
            // 若未透過參數傳入 URL，則互動詢問
            $url = $this->argument('url') ?: $this->ask('請輸入文章 URL');

            if (!$url) {
                $this->error('URL 不可為空！');
                return;
            }

            // 嘗試抓取文章標題（使用與 GetBtDataDetailController 相似的邏輯）
            $title = $this->getArticleTitle($url);
            if (!$title) {
                $this->error("無法從 URL 中取得文章標題，請確認網址正確。");
                return;
            }

            // 檢查資料庫中是否已存在此文章
            $existingArticle = Article::where('title', $title)->first();
            if ($existingArticle) {
                $this->info("發現文章 [{$title}] 已存在，刪除原有資料...");
                // 若有關聯的圖片，請確保在模型或資料庫層級設定 cascade 刪除
                $existingArticle->delete();
                $this->info("原有資料已刪除！");
            }

            // 呼叫原先的 fetchDetail 方法處理文章與圖片的匯入
            $this->info("開始匯入文章及圖片...");
            $this->btDataController->fetchDetail($url);
            $this->info("文章匯入作業完成！");
        }

        /**
         * 使用 Guzzle 及 DOMDocument 抓取 URL 頁面，解析出文章標題。
         *
         * @param string $url
         * @return string|null
         * @throws GuzzleException
         */
        protected function getArticleTitle(string $url): ?string
        {
            try {
                $client = new Client(['verify' => false]);
                $response = $client->request('GET', $url);
                $htmlContent = $response->getBody()->getContents();

                $dom = new \DOMDocument();
                // 使用 @ 忽略 HTML 解析時的警告
                @$dom->loadHTML($htmlContent);
                $xpath = new \DOMXPath($dom);
                $titleNode = $xpath->query('//h3[@class="panel-title"]')->item(0);
                return $titleNode ? trim($titleNode->nodeValue) : null;
            } catch (\Exception $e) {
                Log::error("取得文章標題失敗", ['url' => $url, 'error' => $e->getMessage()]);
                return null;
            }
        }
    }
