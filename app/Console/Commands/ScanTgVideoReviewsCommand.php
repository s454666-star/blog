<?php

namespace App\Console\Commands;

use App\Services\TgVideoReviewScanner;
use Illuminate\Console\Command;
use Throwable;

class ScanTgVideoReviewsCommand extends Command
{
    protected $signature = 'tg-video-review:scan
        {--root= : 覆寫掃描根目錄（測試用）}
        {--run-token= : 本次掃描識別碼}
        {--cleanup-run= : 只清理指定的未完成掃描}';

    protected $description = '掃描 TG 暫存第一層影片，產生每 5% 一格的 5x4 接觸表並寫入資料表。';

    public function handle(TgVideoReviewScanner $scanner): int
    {
        $cleanupToken = trim((string) $this->option('cleanup-run'));
        if ($cleanupToken !== '') {
            $scanner->cleanupRun($cleanupToken);
            $this->info('未完成掃描已精準清理。');
            return self::SUCCESS;
        }

        try {
            $result = $scanner->scan(
                trim((string) $this->option('root')) ?: null,
                function (int $current, int $total, string $status): void {
                    $percent = $total > 0 ? (int) floor(($current / $total) * 100) : 100;
                    $this->line(sprintf('[%d/%d %d%%] %s', $current, $total, $percent, $status));
                },
                trim((string) $this->option('run-token')) ?: null,
            );

            $this->info(sprintf(
                '掃描完成：影片 %d、產生 %d、未變更 %d、無法讀取 %d。',
                $result['videos'], $result['generated'], $result['unchanged'], $result['failed']
            ));
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('掃描失敗或已中斷：' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
