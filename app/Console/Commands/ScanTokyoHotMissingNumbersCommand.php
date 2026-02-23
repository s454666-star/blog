<?php

    declare(strict_types=1);

    namespace App\Console\Commands;

    use Illuminate\Console\Command;
    use RecursiveDirectoryIterator;
    use RecursiveIteratorIterator;
    use FilesystemIterator;
    use SplFileInfo;

    class ScanTokyoHotMissingNumbersCommand extends Command
    {
        /**
         * 用法：
         * php artisan media:scan-tokyohot-missing "F:\Tokyo-Hot"
         */
        protected $signature = 'media:scan-tokyohot-missing
        {path : 目標資料夾路徑（可含子資料夾）}';

        protected $description = '掃描指定路徑所有檔案，找出 Tokyo-Hot nxxxx 最小與最大之間缺少的序號（忽略 A/B/C 等尾碼差異）';

        public function handle(): int
        {
            $path = (string) $this->argument('path');
            $path = rtrim($path, "\\/");

            if ($path === '' || !is_dir($path)) {
                $this->error("路徑不存在或不是資料夾：{$path}");
                return self::FAILURE;
            }

            $this->info("開始掃描：{$path}");

            $numbersFound = [];
            $totalFiles = 0;
            $matchedFiles = 0;

            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($it as $file) {
                /** @var SplFileInfo $file */
                if (!$file->isFile()) {
                    continue;
                }

                $totalFiles++;

                $filename = $file->getFilename();

                // 支援：
                // Tokyo-Hot n0001.wmv
                // Tokyo-Hot n0064 A.wmv
                // Tokyo-Hot n0064 B.wmv
                // (大小寫不敏感，副檔名不限，重點是抓 nxxxx)
                if (preg_match('/\bn(\d{4})\b/i', $filename, $m) !== 1) {
                    continue;
                }

                $matchedFiles++;
                $n = (int) $m[1];
                $numbersFound[$n] = true;
            }

            if (count($numbersFound) === 0) {
                $this->warn("未在檔名中找到 n0000 形式的序號。掃描檔案數：{$totalFiles}");
                return self::SUCCESS;
            }

            ksort($numbersFound);
            $numbers = array_keys($numbersFound);

            $min = $numbers[0];
            $max = $numbers[count($numbers) - 1];

            $missing = [];
            for ($i = $min; $i <= $max; $i++) {
                if (!isset($numbersFound[$i])) {
                    $missing[] = $i;
                }
            }

            $this->line('');
            $this->info("掃描完成");
            $this->line("總檔案數：{$totalFiles}");
            $this->line("命中含 nxxxx 的檔案數：{$matchedFiles}");
            $this->line("唯一序號數：".count($numbersFound));
            $this->line("最小序號：n".str_pad((string) $min, 4, '0', STR_PAD_LEFT));
            $this->line("最大序號：n".str_pad((string) $max, 4, '0', STR_PAD_LEFT));
            $this->line("缺少數量：".count($missing));
            $this->line('');

            if (count($missing) === 0) {
                $this->info("沒有缺號（最小到最大之間都齊）。");
                return self::SUCCESS;
            }

            $this->info("缺少序號清單：");
            foreach ($missing as $n) {
                $this->line("n".str_pad((string) $n, 4, '0', STR_PAD_LEFT));
            }

            return self::SUCCESS;
        }
    }
