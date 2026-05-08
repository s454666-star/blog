<?php

namespace App\Console\Commands;

use App\Services\TwStockDailyPriceFetcher;
use Illuminate\Console\Command;

class FetchTwStockDailyPricesCommand extends Command
{
    protected $signature = 'tw-stock:fetch-daily-prices
        {--latest : 抓最新全市場股價與成交量}
        {--backfill : 用歷史 K 線資料補齊日期區間}
        {--from=2024-01-01 : backfill 開始日期}
        {--to= : backfill 結束日期，預設今天}
        {--exchange= : 只處理 TWSE 或 TPEx}
        {--stock-code= : 只處理單一股票代碼}
        {--limit= : 限制處理股票數，測試用}
        {--shard-count=1 : 將股票候選切成幾份並行處理}
        {--shard-index=0 : 目前處理第幾份，從 0 開始}
        {--sleep-ms=60 : backfill 每檔股票節流毫秒數}';

    protected $description = '抓取台股每日股價、成交量與歷史 K 線資料。';

    public function handle(TwStockDailyPriceFetcher $fetcher): int
    {
        $runLatest = (bool) $this->option('latest') || !(bool) $this->option('backfill');
        $totalLatest = 0;
        if ($runLatest) {
            $rows = $fetcher->fetchLatestRows();
            $totalLatest = $fetcher->upsertRows($rows);
            $this->info(sprintf('最新股價完成：rows=%d', $totalLatest));
        }

        $totalBackfilled = 0;
        $checked = 0;
        if ((bool) $this->option('backfill')) {
            $exchange = $this->option('exchange') !== null && $this->option('exchange') !== ''
                ? (string) $this->option('exchange')
                : null;
            $stockCode = $this->option('stock-code') !== null && $this->option('stock-code') !== ''
                ? (string) $this->option('stock-code')
                : null;
            $limit = $this->option('limit') !== null && $this->option('limit') !== ''
                ? max(1, (int) $this->option('limit'))
                : null;
            $shardCount = max(1, (int) $this->option('shard-count'));
            $shardIndex = max(0, min($shardCount - 1, (int) $this->option('shard-index')));
            $sleepMs = max(0, (int) $this->option('sleep-ms'));
            $from = (string) $this->option('from');
            $to = $this->option('to') !== null && $this->option('to') !== ''
                ? (string) $this->option('to')
                : now((string) config('app.timezone'))->toDateString();

            $candidates = $fetcher->knownStockCandidates($exchange, $stockCode);
            if ($limit !== null) {
                $candidates = array_slice($candidates, 0, $limit);
            }

            foreach ($candidates as $candidate) {
                $checked++;
                if (!$this->stockCodeBelongsToShard((string) $candidate['stock_code'], $shardCount, $shardIndex)) {
                    continue;
                }

                if ($sleepMs > 0) {
                    usleep($sleepMs * 1000);
                }

                $rows = $fetcher->fetchHistoricalRows($candidate, $from, $to);
                $stored = $fetcher->upsertRows($rows);
                $totalBackfilled += $stored;
                if ($stored > 0) {
                    $this->line(sprintf(
                        '%s %s %s stored=%d',
                        $candidate['exchange'],
                        $candidate['stock_code'],
                        $candidate['stock_name'],
                        $stored,
                    ));
                }
            }

            $this->info(sprintf(
                '歷史 K 線完成：checked_stocks=%d stored_rows=%d from=%s to=%s shard=%d/%d',
                $checked,
                $totalBackfilled,
                $from,
                $to,
                $shardIndex,
                $shardCount,
            ));
        }

        $this->info(sprintf('完成：latest_rows=%d backfill_rows=%d', $totalLatest, $totalBackfilled));

        return self::SUCCESS;
    }

    private function stockCodeBelongsToShard(string $stockCode, int $shardCount, int $shardIndex): bool
    {
        if ($shardCount <= 1) {
            return true;
        }

        return ((int) sprintf('%u', crc32($stockCode)) % $shardCount) === $shardIndex;
    }
}
