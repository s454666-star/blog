<?php

namespace App\Console\Commands;

use App\Services\TwFuturesDailyPriceFetcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class FetchTwFuturesDailyPricesCommand extends Command
{
    protected $signature = 'tw-stock:fetch-taiex-futures-daily
        {--from= : 開始日期，預設最近 45 天}
        {--to= : 結束日期，預設今天}
        {--year= : 指定年度，未給 from/to 時自動使用該年完整日期區間}
        {--symbol=TXF1! : DB 內部商品代碼}
        {--contract=TX : TAIFEX 契約代碼}';

    protected $description = '抓取 TAIFEX 官方台指期日行情，經雙來源驗證後入庫。';

    public function handle(TwFuturesDailyPriceFetcher $fetcher): int
    {
        $timezone = (string) config('app.timezone', 'Asia/Taipei');
        $yearOption = $this->option('year');
        $year = $yearOption === null || $yearOption === '' ? null : (int) $yearOption;
        $from = $this->option('from') !== null && $this->option('from') !== ''
            ? (string) $this->option('from')
            : ($year === null ? CarbonImmutable::now($timezone)->subDays(45)->toDateString() : sprintf('%04d-01-01', $year));
        $to = $this->option('to') !== null && $this->option('to') !== ''
            ? (string) $this->option('to')
            : ($year === null ? CarbonImmutable::now($timezone)->toDateString() : sprintf('%04d-12-31', $year));

        try {
            $fromDate = CarbonImmutable::parse($from, $timezone)->startOfDay();
            $toDate = CarbonImmutable::parse($to, $timezone)->endOfDay();
        } catch (Throwable) {
            $this->error('from/to 日期格式錯誤，請使用 YYYY-MM-DD。');

            return self::FAILURE;
        }

        if ($fromDate->greaterThan($toDate)) {
            $this->error('from 不可晚於 to。');

            return self::FAILURE;
        }

        try {
            $rows = $fetcher->fetchRows(
                from: $fromDate->toDateString(),
                to: $toDate->toDateString(),
                year: $year,
                symbol: (string) $this->option('symbol'),
                contractCode: (string) $this->option('contract'),
            );
            $result = $fetcher->upsertVerifiedRows($rows);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'TAIFEX 台指期日行情完成：stored_rows=%d skipped_rows=%d fetched_rows=%d from=%s to=%s',
            $result['stored'],
            $result['skipped'],
            count($rows),
            $fromDate->toDateString(),
            $toDate->toDateString(),
        ));

        foreach (array_slice($result['mismatches'], 0, 10) as $mismatch) {
            $this->warn(json_encode($mismatch, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }

        return $result['stored'] > 0 || $rows === [] ? self::SUCCESS : self::FAILURE;
    }
}
