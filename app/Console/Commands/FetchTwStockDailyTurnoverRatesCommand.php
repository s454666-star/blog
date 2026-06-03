<?php

namespace App\Console\Commands;

use App\Services\TwStockDailyTurnoverRateFetcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class FetchTwStockDailyTurnoverRatesCommand extends Command
{
    protected $signature = 'tw-stock:fetch-daily-turnover-rates
        {--date= : 指定單日 YYYY-MM-DD，預設今天}
        {--from= : 區間開始日期 YYYY-MM-DD}
        {--to= : 區間結束日期 YYYY-MM-DD}
        {--recent-days= : 從今天往前抓 N 個日曆日，含今天}
        {--skip-non-trading-day : 沒有資料時視為非交易日並成功結束}
        {--sleep-ms=200 : 多日抓取時每次請求後節流毫秒數}';

    protected $description = '抓取台股上市與上櫃每日周轉率。';

    public function handle(TwStockDailyTurnoverRateFetcher $fetcher): int
    {
        $dates = $this->requestedDates();
        if ($dates === []) {
            $this->error('日期區間不正確。');

            return self::FAILURE;
        }

        $sleepMs = max(0, (int) $this->option('sleep-ms'));
        $totalRows = 0;
        $emptyDates = [];

        foreach ($dates as $index => $date) {
            if ($index > 0 && $sleepMs > 0) {
                usleep($sleepMs * 1000);
            }

            $rows = $fetcher->fetchRowsForDate($date);
            if ($rows === []) {
                $emptyDates[] = $date->toDateString();
                $this->warn(sprintf('%s 無周轉率資料', $date->toDateString()));
                continue;
            }

            $stored = $fetcher->upsertRows($rows);
            $totalRows += $stored;
            $this->line(sprintf('%s stored=%d', $date->toDateString(), $stored));
        }

        if ($totalRows === 0 && $emptyDates !== [] && !(bool) $this->option('skip-non-trading-day')) {
            $this->error('沒有寫入任何周轉率資料。');

            return self::FAILURE;
        }

        $this->info(sprintf(
            '完成：dates=%d rows=%d empty_dates=%d',
            count($dates),
            $totalRows,
            count($emptyDates),
        ));

        return self::SUCCESS;
    }

    /**
     * @return list<CarbonImmutable>
     */
    private function requestedDates(): array
    {
        $timezone = (string) config('app.timezone', 'Asia/Taipei');

        try {
            $fromOption = trim((string) $this->option('from'));
            $toOption = trim((string) $this->option('to'));

            if ($fromOption !== '' || $toOption !== '') {
                $from = CarbonImmutable::parse($fromOption !== '' ? $fromOption : $toOption, $timezone)->startOfDay();
                $to = CarbonImmutable::parse($toOption !== '' ? $toOption : $fromOption, $timezone)->startOfDay();
            } else {
                $dateOption = trim((string) $this->option('date'));
                $recentDaysOption = trim((string) $this->option('recent-days'));
                if ($dateOption === '' && $recentDaysOption !== '') {
                    $recentDays = max(1, (int) $recentDaysOption);
                    $to = CarbonImmutable::now($timezone)->startOfDay();
                    $from = $to->subDays($recentDays - 1);
                } else {
                    $from = $dateOption === ''
                        ? CarbonImmutable::now($timezone)->startOfDay()
                        : CarbonImmutable::parse($dateOption, $timezone)->startOfDay();
                    $to = $from;
                }
            }
        } catch (Throwable) {
            return [];
        }

        if ($from->greaterThan($to)) {
            return [];
        }

        $dates = [];
        for ($date = $from; $date->lessThanOrEqualTo($to); $date = $date->addDay()) {
            $dates[] = $date;
        }

        return $dates;
    }
}
