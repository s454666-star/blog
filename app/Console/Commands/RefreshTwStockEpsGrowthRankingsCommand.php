<?php

namespace App\Console\Commands;

use App\Services\TwStockEpsGrowthRankingService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class RefreshTwStockEpsGrowthRankingsCommand extends Command
{
    protected $signature = 'tw-stock:refresh-eps-growth-rankings
        {--date= : 快照日期，預設今天（Asia/Taipei）}
        {--lookback-days= : FactSet 文章回溯天數}
        {--sleep-ms=80 : FinMind 每檔查詢間隔毫秒}
        {--minimum-eligible= : 最少完整可比股票數}
        {--allow-missing-top-prices : 允許前 50 名缺少收盤價，僅供診斷}';

    protected $description = '更新台股 2025A 至 2028E EPS 百分位加權分數排行與上週名次變化。';

    public function handle(TwStockEpsGrowthRankingService $service): int
    {
        $timezone = (string) config('app.timezone', 'Asia/Taipei');
        try {
            $snapshotDate = $this->option('date')
                ? CarbonImmutable::parse((string) $this->option('date'), $timezone)->startOfDay()
                : CarbonImmutable::now($timezone)->startOfDay();
        } catch (Throwable) {
            $this->error('無效的 --date，請使用 YYYY-MM-DD。');

            return self::FAILURE;
        }

        $lookbackDays = $this->option('lookback-days') !== null
            ? max(35, (int) $this->option('lookback-days'))
            : (int) config('tw_stock.eps_growth_ranking.lookback_days', 400);
        $minimumEligible = $this->option('minimum-eligible') !== null
            ? max(1, (int) $this->option('minimum-eligible'))
            : (int) config('tw_stock.eps_growth_ranking.minimum_eligible', 50);
        $sleepMs = max(0, (int) $this->option('sleep-ms'));

        try {
            $result = $service->refresh(
                $snapshotDate,
                $lookbackDays,
                $sleepMs,
                $minimumEligible,
                !(bool) $this->option('allow-missing-top-prices'),
            );
        } catch (Throwable $exception) {
            report($exception);
            $this->error('更新失敗：' . $exception->getMessage());

            return self::FAILURE;
        }

        $run = $result['run'];
        $this->info(sprintf(
            'EPS 成長排行完成：run=%d snapshot=%s price_date=%s articles=%d forecasts=%d eligible=%d top=%d',
            $run->id,
            $run->snapshot_date->toDateString(),
            $run->price_date?->toDateString() ?? '-',
            $run->article_count,
            $run->forecast_count,
            $run->eligible_count,
            $run->top_count,
        ));

        foreach (array_slice($result['top_rows'], 0, 5) as $row) {
            $this->line(sprintf(
                '#%d %s %s score=%.2f sum=%.1f%% close=%s change=%s',
                $row['rank'],
                $row['stock_code'],
                $row['stock_name'],
                $row['weighted_score'],
                $row['growth_sum'],
                $row['close_price'] === null ? '-' : number_format($row['close_price'], 2, '.', ''),
                $row['rank_change'] === null ? '-' : sprintf('%+d', $row['rank_change']),
            ));
        }

        return self::SUCCESS;
    }
}
