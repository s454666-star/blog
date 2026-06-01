<?php

namespace App\Console\Commands;

use App\Models\TwStockUpcomingDividend;
use App\Services\TwStockUpcomingDividendFetcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class FetchTwStockUpcomingDividendsCommand extends Command
{
    protected $signature = 'tw-stock:fetch-upcoming-dividends
        {--as-of= : 查詢基準日，格式 YYYY-MM-DD 或 YYYY/MM/DD}
        {--days=30 : 往後查詢天數}
        {--include-funds : 包含 ETF、債券 ETF、REIT 等非 4 碼股票代號}
        {--prices-only : 只用每日股價表刷新既有除權息列的最新股價、價格日期與殖利率}';

    protected $description = '抓取未來 30 天台股除權息股票、最新股價、殖利率、近 20 天漲跌幅與上次填息天數。';

    public function handle(TwStockUpcomingDividendFetcher $fetcher): int
    {
        try {
            $asOf = $this->resolveAsOfDate();
            $days = max(0, (int) $this->option('days'));
            $includeFunds = (bool) $this->option('include-funds');
            if ((bool) $this->option('prices-only')) {
                return $this->refreshExistingPrices($asOf, $days);
            }

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
                $this->dividendLabel($payload),
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

    private function refreshExistingPrices(CarbonImmutable $asOf, int $days): int
    {
        $endDate = $asOf->addDays($days);
        $latestDates = DB::table('tw_stock_daily_prices')
            ->select(['exchange', 'stock_code'])
            ->selectRaw('MAX(trade_date) as max_trade_date')
            ->groupBy('exchange', 'stock_code');

        $latestPrices = DB::table('tw_stock_daily_prices as prices')
            ->joinSub($latestDates, 'latest_prices', function ($join): void {
                $join->on('prices.exchange', '=', 'latest_prices.exchange')
                    ->on('prices.stock_code', '=', 'latest_prices.stock_code')
                    ->on('prices.trade_date', '=', 'latest_prices.max_trade_date');
            })
            ->get([
                'prices.exchange',
                'prices.stock_code',
                'prices.trade_date',
                'prices.close_price',
            ])
            ->keyBy(fn (object $row): string => $row->exchange . ':' . $row->stock_code);

        $updated = 0;
        TwStockUpcomingDividend::query()
            ->whereBetween('ex_dividend_date', [$asOf->toDateString(), $endDate->toDateString()])
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($latestPrices, &$updated): void {
                foreach ($rows as $row) {
                    $price = $latestPrices->get((string) $row->exchange . ':' . (string) $row->stock_code);
                    if ($price === null || $price->close_price === null) {
                        continue;
                    }

                    $latestClose = (float) $price->close_price;
                    $cashDividend = (float) $row->cash_dividend;
                    $row->forceFill([
                        'latest_close_price' => $latestClose,
                        'latest_price_date' => $price->trade_date,
                        'dividend_yield_percent' => $latestClose > 0.0
                            ? round(($cashDividend / $latestClose) * 100, 4)
                            : null,
                        'fetched_at' => now(),
                    ])->save();
                    $updated++;
                }
            });

        $this->info(sprintf(
            '完成：updated_price_rows=%d range=%s~%s',
            $updated,
            $asOf->toDateString(),
            $endDate->toDateString(),
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

    /**
     * @param array<string, mixed> $payload
     */
    private function dividendLabel(array $payload): string
    {
        $parts = [];
        if ((float) ($payload['cash_dividend'] ?? 0) > 0.0) {
            $parts[] = '息 ' . number_format((float) $payload['cash_dividend'], 4);
        }

        if ((float) ($payload['stock_dividend'] ?? 0) > 0.0) {
            $parts[] = '權 ' . number_format((float) $payload['stock_dividend'], 4);
        }

        return $parts !== [] ? implode(' / ', $parts) : 'N/A';
    }
}
