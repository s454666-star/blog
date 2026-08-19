<?php

    namespace App\Console\Commands;

    use App\Http\Controllers\GetBtDataDetailController;
    use Illuminate\Console\Command;

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
         */
        public function handle(): int
        {
            // 若未透過參數傳入 URL，則互動詢問
            $url = $this->argument('url') ?: $this->ask('請輸入文章 URL');

            if (!$url) {
                $this->error('URL 不可為空！');
                return self::FAILURE;
            }

            $this->info("開始匯入文章及圖片...");
            $imported = $this->btDataController->fetchDetail($url, true);

            if (!$imported) {
                $this->error('文章重跑失敗，原有資料已保留。');

                return self::FAILURE;
            }

            $this->info("文章匯入作業完成！");

            return self::SUCCESS;
        }
    }
