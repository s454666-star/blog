<?php

namespace App\Console\Commands;

use App\Services\TwFuturesHourlyPriceFetcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class BackfillTwFuturesContinuousPricesCommand extends Command
{
    protected $signature = 'tw-stock:backfill-taiex-futures-continuous
        {--from= : 開始日期，預設最近 31 天}
        {--to= : 結束日期，預設今天}
        {--interval=15 : TradingView K 線週期，支援 5、15、30 或 60}
        {--bars=5000 : 每個單月合約從資料源要求的根數}
        {--symbol=TXF1! : DB 內部商品代碼}';

    protected $description = '用單月合約拼接台指期近月連續 K 線並入庫。';

    private const MONTH_CODES = [
        1 => 'F',
        2 => 'G',
        3 => 'H',
        4 => 'J',
        5 => 'K',
        6 => 'M',
        7 => 'N',
        8 => 'Q',
        9 => 'U',
        10 => 'V',
        11 => 'X',
        12 => 'Z',
    ];

    public function handle(TwFuturesHourlyPriceFetcher $fetcher): int
    {
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

        $bars = max(1, (int) $this->option('bars'));
        $symbol = (string) $this->option('symbol');
        $contractMonth = $this->frontContractMonthForDate($fromDate);
        $lastContractMonth = $this->frontContractMonthForDate($toDate);
        $totalStored = 0;
        $firstStartedAt = null;
        $lastStartedAt = null;

        while ($contractMonth->lessThanOrEqualTo($lastContractMonth)) {
            $contractCode = $this->contractCode($contractMonth);
            $activeFrom = $this->maxDateTime($fromDate, $this->thirdWednesday($contractMonth->subMonth())->startOfDay());
            $activeToExclusive = $this->minDateTime($toDate->addSecond(), $this->thirdWednesday($contractMonth)->startOfDay());

            if ($activeFrom->lessThan($activeToExclusive)) {
                $rows = $fetcher->fetchRows(
                    from: $activeFrom->toDateString(),
                    to: $activeToExclusive->subSecond()->toDateString(),
                    symbol: $symbol,
                    tradingViewSymbol: 'TAIFEX:' . $contractCode,
                    bars: $bars,
                    interval: $interval,
                );
                $rows = array_values(array_filter(
                    $rows,
                    function (array $row) use ($activeFrom, $activeToExclusive): bool {
                        $startedAt = CarbonImmutable::parse((string) $row['started_at'], 'Asia/Taipei');

                        return $startedAt->greaterThanOrEqualTo($activeFrom)
                            && $startedAt->lessThan($activeToExclusive);
                    },
                ));
                $stored = $fetcher->upsertRows($rows);
                $totalStored += $stored;

                if ($rows !== []) {
                    $firstStartedAt ??= (string) $rows[0]['started_at'];
                    $lastStartedAt = (string) $rows[array_key_last($rows)]['started_at'];
                }

                $this->line(sprintf(
                    '%s %sK：stored_rows=%d active=%s ~ %s',
                    $contractCode,
                    $interval,
                    $stored,
                    $activeFrom->format('Y-m-d H:i:s'),
                    $activeToExclusive->subSecond()->format('Y-m-d H:i:s'),
                ));
            }

            $contractMonth = $contractMonth->addMonthNoOverflow();
        }

        $this->info(sprintf(
            '台指期近月連續 %sK 補資料完成：stored_rows=%d from=%s to=%s symbol=%s',
            $interval,
            $totalStored,
            $fromDate->toDateString(),
            $toDate->toDateString(),
            $symbol,
        ));

        if ($firstStartedAt !== null && $lastStartedAt !== null) {
            $this->line(sprintf('資料範圍：%s ~ %s', $firstStartedAt, $lastStartedAt));
        }

        return self::SUCCESS;
    }

    private function frontContractMonthForDate(CarbonImmutable $date): CarbonImmutable
    {
        $month = $date->startOfMonth();

        return $date->greaterThanOrEqualTo($this->thirdWednesday($month)->startOfDay())
            ? $month->addMonthNoOverflow()
            : $month;
    }

    private function thirdWednesday(CarbonImmutable $month): CarbonImmutable
    {
        $date = $month->startOfMonth();
        while ((int) $date->dayOfWeekIso !== 3) {
            $date = $date->addDay();
        }

        return $date->addWeeks(2);
    }

    private function contractCode(CarbonImmutable $month): string
    {
        return sprintf('TXF%s%d', self::MONTH_CODES[(int) $month->month], (int) $month->year);
    }

    private function maxDateTime(CarbonImmutable $first, CarbonImmutable $second): CarbonImmutable
    {
        return $first->greaterThanOrEqualTo($second) ? $first : $second;
    }

    private function minDateTime(CarbonImmutable $first, CarbonImmutable $second): CarbonImmutable
    {
        return $first->lessThanOrEqualTo($second) ? $first : $second;
    }
}
