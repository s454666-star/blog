<?php

namespace App\Console\Commands;

use App\Services\TwFuturesHourlyPriceFetcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class FetchTwFuturesHourlyPricesCommand extends Command
{
    protected $signature = 'tw-stock:fetch-taiex-futures-hourly
        {--from= : 開始日期，預設最近 21 天}
        {--to= : 結束日期，預設今天}
        {--interval=60 : TradingView K 線週期，支援 5 或 60}
        {--bars=0 : 從資料源要求的根數，0 代表依週期自動}
        {--delay-seconds=0 : 抓取前延遲秒數，讓資料源更新當下 K 棒}
        {--symbol=TXF1! : DB 內部商品代碼}
        {--tradingview-symbol=TAIFEX:TXF1! : TradingView 商品代碼}';

    protected $description = '抓取台指期貨近月連續 K 線價格並入庫。';

    public function handle(TwFuturesHourlyPriceFetcher $fetcher): int
    {
        $timezone = (string) config('app.timezone', 'Asia/Taipei');
        $from = $this->option('from') !== null && $this->option('from') !== ''
            ? (string) $this->option('from')
            : CarbonImmutable::now($timezone)->subDays(21)->toDateString();
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
        if (! in_array($interval, ['5', '60'], true)) {
            $this->error('interval 只支援 5 或 60。');

            return self::FAILURE;
        }

        $barsOption = (int) $this->option('bars');
        $bars = $barsOption > 0 ? $barsOption : ($interval === '5' ? 6000 : 1200);
        $delaySeconds = max(0, min(300, (int) $this->option('delay-seconds')));
        $symbol = (string) $this->option('symbol');
        $tradingViewSymbol = (string) $this->option('tradingview-symbol');

        if ($delaySeconds > 0) {
            $this->line(sprintf('等待 %d 秒後抓取 %sK。', $delaySeconds, $interval));
            sleep($delaySeconds);
        }

        $rows = $fetcher->fetchRows(
            from: $fromDate->toDateString(),
            to: $toDate->toDateString(),
            symbol: $symbol,
            tradingViewSymbol: $tradingViewSymbol,
            bars: $bars,
            interval: $interval,
        );
        $stored = $fetcher->upsertRows($rows);

        $this->info(sprintf(
            '台指期 %sK 完成：stored_rows=%d from=%s to=%s symbol=%s bars=%d',
            $interval,
            $stored,
            $fromDate->toDateString(),
            $toDate->toDateString(),
            $symbol,
            $bars,
        ));

        if ($rows !== []) {
            $this->line(sprintf(
                '資料範圍：%s ~ %s',
                (string) $rows[0]['started_at'],
                (string) $rows[array_key_last($rows)]['started_at'],
            ));
        }

        return self::SUCCESS;
    }
}
