<?php

namespace App\Console\Commands;

use App\Services\TwFuturesHourlyPriceFetcher;
use App\Services\TwFuturesOfficialIntradayPriceFetcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class FetchTwFuturesHourlyPricesCommand extends Command
{
    protected $signature = 'tw-stock:fetch-taiex-futures-hourly
        {--from= : 開始日期，預設最近 31 天}
        {--to= : 結束日期，預設今天}
        {--interval=60 : K 線週期，支援 5、15、30 或 60}
        {--bars=0 : 從資料源要求的根數，0 代表依週期自動}
        {--delay-seconds=0 : 抓取前延遲秒數，讓資料源更新當下 K 棒}
        {--symbol=TXF1! : DB 內部商品代碼}
        {--tradingview-symbol=TAIFEX:TXF1! : TradingView 商品代碼}
        {--source=auto : 資料來源，支援 auto、official 或 tradingview；auto 會以 TAIFEX 官方逐筆成交為主，TradingView 只補缺口}';

    protected $description = '抓取台指期貨近月連續 K 線價格並入庫，預設以 TAIFEX 官方逐筆成交資料為主。';

    public function handle(
        TwFuturesHourlyPriceFetcher $fetcher,
        TwFuturesOfficialIntradayPriceFetcher $officialFetcher,
    ): int {
        $timezone = (string) config('app.timezone', 'Asia/Taipei');
        $from = $this->option('from') !== null && $this->option('from') !== ''
            ? (string) $this->option('from')
            : CarbonImmutable::now($timezone)->subDays(31)->toDateString();
        $to = $this->option('to') !== null && $this->option('to') !== ''
            ? (string) $this->option('to')
            : CarbonImmutable::now($timezone)->toDateString();

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

        $interval = trim((string) $this->option('interval'));
        if (! in_array($interval, ['5', '15', '30', '60'], true)) {
            $this->error('interval 只支援 5、15、30 或 60。');

            return self::FAILURE;
        }

        $source = strtolower(trim((string) $this->option('source')));
        if (! in_array($source, ['auto', 'official', 'tradingview'], true)) {
            $this->error('source 只支援 auto、official 或 tradingview。');

            return self::FAILURE;
        }

        $barsOption = (int) $this->option('bars');
        $bars = $barsOption > 0 ? $barsOption : match ($interval) {
            '5' => 6000,
            '15' => 4800,
            '30' => 2400,
            default => 1200,
        };
        $delaySeconds = max(0, min(300, (int) $this->option('delay-seconds')));
        $symbol = (string) $this->option('symbol');
        $tradingViewSymbol = (string) $this->option('tradingview-symbol');

        $officialRows = [];
        $officialStored = 0;
        if ($source !== 'tradingview') {
            try {
                $officialRows = $officialFetcher->fetchRows(
                    from: $fromDate->toDateString(),
                    to: $toDate->toDateString(),
                    symbol: $symbol,
                    interval: $interval,
                );
                $officialStored = $officialFetcher->upsertRows($officialRows);
            } catch (Throwable $exception) {
                if ($source === 'official') {
                    $this->error('TAIFEX 官方逐筆成交資料抓取失敗：' . $exception->getMessage());

                    return self::FAILURE;
                }

                $this->warn('TAIFEX 官方逐筆成交資料暫時不可用，改用 TradingView 補資料：' . $exception->getMessage());
            }
        }

        $fallbackRows = [];
        $fallbackStored = 0;
        if ($source !== 'official') {
            if ($delaySeconds > 0) {
                $this->line(sprintf('等待 %d 秒後抓取 %sK。', $delaySeconds, $interval));
                sleep($delaySeconds);
            }

            $fallbackRows = $fetcher->fetchRows(
                from: $fromDate->toDateString(),
                to: $toDate->toDateString(),
                symbol: $symbol,
                tradingViewSymbol: $tradingViewSymbol,
                bars: $bars,
                interval: $interval,
            );

            if ($source === 'auto') {
                $fallbackRows = $officialFetcher->filterRowsWithoutOfficialSource($fallbackRows);
            }

            $fallbackStored = $fetcher->upsertRows($fallbackRows);
        } elseif ($delaySeconds > 0) {
            $this->line(sprintf('等待 %d 秒後抓取 %sK。', $delaySeconds, $interval));
            sleep($delaySeconds);
        }

        $this->info(sprintf(
            '台指期 %sK 完成：stored_rows=%d official_rows=%d fallback_rows=%d from=%s to=%s symbol=%s bars=%d source=%s',
            $interval,
            $officialStored + $fallbackStored,
            $officialStored,
            $fallbackStored,
            $fromDate->toDateString(),
            $toDate->toDateString(),
            $symbol,
            $bars,
            $source,
        ));

        if ($officialRows !== []) {
            $this->line(sprintf(
                '官方資料範圍：%s ~ %s',
                (string) $officialRows[0]['started_at'],
                (string) $officialRows[array_key_last($officialRows)]['started_at'],
            ));
        }

        if ($fallbackRows !== []) {
            $this->line(sprintf(
                '補充資料範圍：%s ~ %s',
                (string) $fallbackRows[0]['started_at'],
                (string) $fallbackRows[array_key_last($fallbackRows)]['started_at'],
            ));
        }

        return self::SUCCESS;
    }
}
