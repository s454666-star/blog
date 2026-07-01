<?php

namespace App\Console\Commands;

use App\Services\TwStockMonthlyRevenueFetcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class FetchTwStockMonthlyRevenuesCommand extends Command
{
    protected $signature = 'tw-stock:fetch-monthly-revenues
        {--year= : 營收所屬西元年，預設上個月}
        {--month= : 營收所屬月份，預設上個月}
        {--skip-outside-window : 只在每月 1-10 號與假日遞延緩衝日執行}
        {--skip-price-refresh : 不先刷新每日股價，只用既有 tw_stock_daily_prices 計算漲跌幅}';

    protected $description = '抓取上市櫃每月營收排行資料並計算近一日與五日股價表現。';

    public function handle(TwStockMonthlyRevenueFetcher $fetcher): int
    {
        $today = CarbonImmutable::today((string) config('app.timezone'));
        if ((bool) $this->option('skip-outside-window') && !$this->isRevenueFetchWindow($today)) {
            $this->info(sprintf('略過：%s 不在月營收 1-10 號或假日遞延緩衝視窗。', $today->toDateString()));

            return self::SUCCESS;
        }

        [$year, $month] = $this->resolvePeriod($today);
        if ($year === null || $month === null) {
            $this->error('year/month 參數不正確。');

            return self::FAILURE;
        }

        $result = $fetcher->fetchAndStore($year, $month, !(bool) $this->option('skip-price-refresh'));

        $this->info(sprintf(
            '完成：period=%s fetched_rows=%d stored_rows=%d refreshed_price_rows=%d',
            $result['period'],
            $result['fetched_rows'],
            $result['stored_rows'],
            $result['refreshed_price_rows'],
        ));

        return self::SUCCESS;
    }

    /**
     * @return array{int|null, int|null}
     */
    private function resolvePeriod(CarbonImmutable $today): array
    {
        $yearOption = $this->option('year');
        $monthOption = $this->option('month');

        if ($yearOption === null && $monthOption === null) {
            $period = $today->subMonthNoOverflow();

            return [(int) $period->year, (int) $period->month];
        }

        $year = $yearOption !== null && $yearOption !== '' ? (int) $yearOption : null;
        $month = $monthOption !== null && $monthOption !== '' ? (int) $monthOption : null;
        if ($year === null || $month === null || $year < 2000 || $month < 1 || $month > 12) {
            return [null, null];
        }

        return [$year, $month];
    }

    private function isRevenueFetchWindow(CarbonImmutable $today): bool
    {
        if ((int) $today->day <= 10) {
            return true;
        }

        return (int) $today->day <= 15 && $today->isWeekday();
    }
}
