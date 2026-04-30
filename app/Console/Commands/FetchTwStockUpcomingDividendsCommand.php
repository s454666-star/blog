<?php

namespace App\Console\Commands;

use App\Models\TwStockUpcomingDividend;
use App\Services\TwStockUpcomingDividendFetcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class FetchTwStockUpcomingDividendsCommand extends Command
{
    protected $signature = 'tw-stock:fetch-upcoming-dividends
        {--as-of= : 查詢基準日，格式 YYYY-MM-DD 或 YYYY/MM/DD}
        {--days=30 : 往後查詢天數}
        {--include-funds : 包含 ETF、債券 ETF、REIT 等非 4 碼股票代號}';

    protected $description = '抓取未來 30 天台股除息股票、最新股價、殖利率與上次填息天數。';

    public function handle(TwStockUpcomingDividendFetcher $fetcher): int
    {
        try {
            $asOf = $this->resolveAsOfDate();
            $days = max(0, (int) $this->option('days'));
            $includeFunds = (bool) $this->option('include-funds');
            $payloads = $fetcher->fetch($asOf, $days, $includeFunds);
        } catch (Throwable $e) {
            report($e);
            $this->error('抓取失敗：' . $e->getMessage());

            return self::FAILURE;
        }

        $endDate = $asOf->addDays($days);
        $keptIds = [];

        foreach ($payloads as $payload) {
            $model = TwStockUpcomingDividend::query()->updateOrCreate(
                [
                    'exchange' => $payload['exchange'],
                    'stock_code' => $payload['stock_code'],
                    'ex_dividend_date' => $payload['ex_dividend_date'],
                ],
                $payload
            );

            $keptIds[] = $model->id;

            $this->info(sprintf(
                '%s %s %s saved: 股利 %s, 股價 %s, 殖利率 %s%%, 上次填息 %s',
                $payload['ex_dividend_date'],
                $payload['stock_code'],
                $payload['stock_name'],
                number_format((float) $payload['cash_dividend'], 4),
                $payload['latest_close_price'] !== null ? number_format((float) $payload['latest_close_price'], 2) : 'N/A',
                $payload['dividend_yield_percent'] !== null ? number_format((float) $payload['dividend_yield_percent'], 2) : 'N/A',
                $payload['last_fill_days'] !== null ? $payload['last_fill_days'] . ' 天' : $this->fillStatusLabel((string) $payload['last_fill_status']),
            ));
        }

        TwStockUpcomingDividend::query()
            ->whereDate('ex_dividend_date', '<', $asOf->toDateString())
            ->orWhereDate('ex_dividend_date', '>', $endDate->toDateString())
            ->delete();

        $staleCurrentRangeQuery = TwStockUpcomingDividend::query()
            ->whereBetween('ex_dividend_date', [$asOf->toDateString(), $endDate->toDateString()]);

        if ($keptIds !== []) {
            $staleCurrentRangeQuery->whereNotIn('id', $keptIds);
        }

        $staleCurrentRangeQuery->delete();

        $this->newLine();
        $this->info(sprintf(
            '完成：saved=%d range=%s~%s include_funds=%s',
            count($payloads),
            $asOf->toDateString(),
            $endDate->toDateString(),
            $includeFunds ? 'yes' : 'no',
        ));

        return self::SUCCESS;
    }

    private function resolveAsOfDate(): CarbonImmutable
    {
        $asOf = $this->option('as-of');
        if ($asOf === null || $asOf === '') {
            return CarbonImmutable::today();
        }

        return CarbonImmutable::createFromFormat('Y-m-d', str_replace('/', '-', (string) $asOf))->startOfDay();
    }

    private function fillStatusLabel(string $status): string
    {
        return match ($status) {
            'unfilled' => '未填息',
            'no_history' => '無歷史',
            default => 'N/A',
        };
    }
}
